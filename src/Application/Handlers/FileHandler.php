<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use Psr\Log\LoggerInterface;

/**
 * Обрабатывает файлы и вложения в сообщениях
 */
class FileHandler
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MaxBotHelper $maxBot;

    public function __construct(
        LoggerInterface $logger,
        StateFileHandler $stateFileHandler,
        MaxBotHelper $maxBot
    ) {
        $this->logger = $logger;
        $this->stateFileHandler = $stateFileHandler;
        $this->maxBot = $maxBot;
    }

    // ============================================================
    // Публичные методы для фасада
    // ============================================================

    /**
     * Основной метод — парсит вложения из webhook и возвращает
     * структуру ['files' => [...], 'links' => [...]].
     */
    public function processFileData(array $update): array
    {
        $fileInfoList = [];
        $fileLinks = [];

        if (!isset($update['message'])) {
            return ['files' => $fileInfoList, 'links' => $fileLinks];
        }

        $message = $update['message'];
        $chatId = (int)($message['recipient']['chat_id'] ?? 0);

        // Проверка размера
        if ($this->isFileTooLarge($message)) {
            if ($chatId) {
                $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Max_File_Size->value);
                return [];
            }
            return ['files' => $fileInfoList, 'links' => $fileLinks];
        }

        // Основное сообщение
        if (isset($message['body']['attachments']) && is_array($message['body']['attachments'])) {
            $filtered = $this->filterVideoAttachments($message['body']['attachments'], (string)$chatId);

            foreach ($filtered as $index => $attachment) {
                $fileInfo = $this->convertAttachmentToFileInfoSimple($attachment, $index);
                if (!empty($fileInfo['filePath'])) {
                    $fileInfoList[] = $fileInfo;
                    $fileLinks[] = $fileInfo['filePath'];
                }
            }
        }

        // Пересланное сообщение
        if (empty($fileInfoList) && isset($message['link']['message']['attachments'])) {
            $filtered = $this->filterVideoAttachments($message['link']['message']['attachments'], (string)$chatId);

            foreach ($filtered as $index => $attachment) {
                $fileInfo = $this->convertAttachmentToFileInfoSimple($attachment, $index);
                if (!empty($fileInfo['filePath'])) {
                    $fileInfoList[] = $fileInfo;
                    $fileLinks[] = $fileInfo['filePath'];
                }
            }
        }

        if (empty($fileInfoList)) {
            return [];
        }

        return [
            'files' => $fileInfoList,
            'links' => $fileLinks,
        ];
    }

    /**
     * Проверяет, есть ли файл в сообщении.
     */
    public function hasFile(array $message): bool
    {
        $attachments = $this->extractAllAttachments($message);
        return !empty($attachments);
    }

    /**
     * Получает количество файлов в сообщении.
     */
    public function getFileCount(array $message): int
    {
        return count($this->extractAllAttachments($message));
    }

    /**
     * Проверяет, что сообщение — медиагруппа (альбом).
     */
    public function isMediaGroup(array $message): bool
    {
        return isset($message['media_group_id']) && !empty($message['media_group_id']);
    }

    /**
     * Обрабатывает медиагруппу (альбом файлов).
     */
    public function handleMediaGroup(string $chatId, array $message, string $text): void
    {
        $mediaGroupId = $message['media_group_id'] ?? null;
        if (!$mediaGroupId) {
            return;
        }

        $this->logger->info("Handling media group for chat: {$chatId}");

        $existingFileLinks = $this->stateFileHandler->getStateField($chatId, 'file_links') ?? [];
        if (!is_array($existingFileLinks)) {
            $existingFileLinks = [];
        }

        $newFileLinks = [];

        if (isset($message['document']) && is_array($message['document'])) {
            $filePath = $this->getFilePath($message['document']['file_id']);
            if ($filePath !== null) {
                $newFileLinks[] = $filePath;
            }
        }

        if (isset($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            $filePath = $this->getFilePath($photo['file_id']);
            if ($filePath !== null) {
                $newFileLinks[] = $filePath;
            }
        }

        $updatedFileLinks = array_values(array_unique(array_merge($existingFileLinks, $newFileLinks)));
        $this->stateFileHandler->setStateField($chatId, 'file_links', $updatedFileLinks);
    }

    /**
     * Проверка, не слишком ли большой файл.
     */
    public function isFileTooLarge(array $message): bool
    {
        $checkAttachment = function ($attachment) {
            $size = 0;

            if (isset($attachment['size']) && is_numeric($attachment['size'])) {
                $size = (int)$attachment['size'];
            } elseif (isset($attachment['payload']['size']) && is_numeric($attachment['payload']['size'])) {
                $size = (int)$attachment['payload']['size'];
            } elseif (isset($attachment['payload']['fileSize']) && is_numeric($attachment['payload']['fileSize'])) {
                $size = (int)$attachment['payload']['fileSize'];
            }

            if ($size > 0) {
                $this->logger->info("Attachment size: $size bytes");
                if ($size > self::MAX_FILE_SIZE) {
                    return true;
                }
            }

            return false;
        };

        foreach ($this->extractAllAttachments($message) as $attachment) {
            if ($checkAttachment($attachment)) {
                return true;
            }
        }

        return false;
    }

    // ============================================================
    // Конвертация аттача в fileInfo
    // ============================================================

    public function convertAttachmentToFileInfoSimple(array $attachment, int $index): array
    {
        $type = $attachment['type'] ?? 'unknown';
        $payload = $attachment['payload'] ?? [];
        $fileID = $this->getUniqueIdFromAttachment($attachment);

        $fileInfo = [
            'filePath' => $payload['url'] ?? '',
            'fileType' => 'application/octet-stream',
            'fileSize' => $payload['size'] ?? 0,
            'fileName' => $fileID,
            'type'     => $type,
        ];

        $uniqueId = null;
        $mimeType = null;

        switch ($type) {
            case 'image':
                if (!empty($payload['photo_id'])) {
                    $uniqueId = (string) $payload['photo_id'];
                    $mimeType = 'image/jpeg';
                }
                break;

            case 'video':
                if (!empty($payload['id'])) {
                    $uniqueId = (string) $payload['id'];
                    $mimeType = $payload['mime_type'] ?? 'video/mp4';
                } elseif (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $matches)) {
                    $uniqueId = (string)$matches[1];
                    $mimeType = $payload['mime_type'] ?? 'video/mp4';
                }
                break;

            case 'audio':
                if (!empty($payload['id'])) {
                    $uniqueId = (string) $payload['id'];
                    $mimeType = $payload['mime_type'] ?? 'audio/mpeg';
                }
                break;

            case 'document':
            case 'file':
                if (!empty($payload['file_id'])) {
                    $uniqueId = $payload['file_id'];
                    $mimeType = $payload['mime_type'] ?? 'application/octet-stream';
                }
                break;
        }

        if ($uniqueId !== null) {
            $fileInfo['fileId'] = $uniqueId;
            $fileInfo['fileType'] = $mimeType;

            $extension = match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => $this->getExtensionFromMime($mimeType) ?: 'mp3',
                default => $this->getExtensionFromMime($mimeType) ?: 'bin',
            };

            $fileInfo['fileName'] = $uniqueId . '.' . $extension;
        } elseif (!empty($payload['url'])) {
            $fileInfo['filePath'] = $payload['url'];

            $extension = match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'mp3',
                default => '',
            };

            $fileInfo['fileName'] = basename(parse_url($payload['url'], PHP_URL_PATH))
                ?: "{$type}_{$index}" . ($extension ? ".{$extension}" : '');
        }

        if (!empty($attachment['filename'])) {
            $fileInfo['fileName'] = $attachment['filename'];
        }

        return $fileInfo;
    }

    // ============================================================
    // Приватные помощники
    // ============================================================

    private function filterVideoAttachments(array $attachments, string $chatId): array
    {
        $filteredAttachments = [];
        $hasVideo = false;

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? 'unknown';

            if ($type === 'video') {
                $hasVideo = true;
                continue;
            }

            $filteredAttachments[] = $attachment;
        }

        if ($hasVideo) {
            $this->maxBot->sendMessage(
                (int)$chatId,
                "⚠️ Видеофайлы не поддерживаются. Пожалуйста, отправьте изображение или файл."
            );
        }

        return $filteredAttachments;
    }

    private function getUniqueIdFromAttachment(array $attachment): ?string
    {
        $type = $attachment['type'] ?? 'unknown';
        $payload = $attachment['payload'] ?? [];

        switch ($type) {
            case 'video':
                if (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $matches)) {
                    return (string)$matches[1];
                }
                return isset($payload['id']) ? (string)$payload['id'] : null;

            case 'image':
            case 'photo':
                return isset($payload['photo_id']) ? (string)$payload['photo_id'] : null;

            case 'audio':
            case 'voice':
                return isset($payload['audio_id']) ? (string)$payload['audio_id'] : null;

            case 'file':
            case 'document':
                return isset($payload['file_id']) ? (string)$payload['file_id'] : null;

            default:
                if (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $matches)) {
                    return (string)$matches[1];
                }
                return null;
        }
    }

    private function getExtensionFromMime(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/x-tar' => 'tar',
            'application/gzip' => 'gz',
            'video/mp4' => 'mp4',
            'video/x-msvideo' => 'avi',
            'video/quicktime' => 'mov',
            'video/x-ms-wmv' => 'wmv',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }

    private function extractAllAttachments(array $message): array
    {
        $attachments = [];

        if (isset($message['message']['body']['attachments']) && is_array($message['message']['body']['attachments'])) {
            $attachments = array_merge($attachments, $message['message']['body']['attachments']);
        }

        if (isset($message['body']['attachments']) && is_array($message['body']['attachments'])) {
            $attachments = array_merge($attachments, $message['body']['attachments']);
        }

        if (isset($message['message']['link']['message']['attachments']) && is_array($message['message']['link']['message']['attachments'])) {
            $attachments = array_merge($attachments, $message['message']['link']['message']['attachments']);
        }

        if (isset($message['link']['message']['attachments']) && is_array($message['link']['message']['attachments'])) {
            $attachments = array_merge($attachments, $message['link']['message']['attachments']);
        }

        if (isset($message['message']['body']['parts']) && is_array($message['message']['body']['parts'])) {
            foreach ($message['message']['body']['parts'] as $part) {
                if (isset($part['attachments']) && is_array($part['attachments'])) {
                    $attachments = array_merge($attachments, $part['attachments']);
                }
            }
        }

        return $attachments;
    }

    private function getFilePath(string $fileId): ?string
    {
        $apiKey = $_ENV['TELEGRAM_API_KEY'] ?? $_ENV['MAXBOT_API_KEY'] ?? null;
        if (!$apiKey) {
            return null;
        }

        $url = "https://api.telegram.org/bot{$apiKey}/getFile?file_id={$fileId}";
        $response = @file_get_contents($url);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (isset($data['result']['file_path'])) {
            return "https://api.telegram.org/file/bot{$apiKey}/" . $data['result']['file_path'];
        }

        return null;
    }
}