<?php

declare(strict_types=1);

namespace App\Application\Handlers;

class FileConverter
{
    public function convert(array $attachment, int $index): array
    {
        $type = $attachment['type'] ?? 'unknown';
        $payload = $attachment['payload'] ?? [];
        $fileID = $this->getUniqueId($attachment);

        $fileInfo = [
            'filePath' => $payload['url'] ?? '',
            'fileType' => 'application/octet-stream',
            'fileSize' => $payload['size'] ?? 0,
            'fileName' => $fileID,
            'type' => $type,
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
                } elseif (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $m)) {
                    $uniqueId = (string)$m[1];
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
            $fileInfo['fileName'] = basename(parse_url($payload['url'], PHP_URL_PATH)) ?: "{$type}_{$index}" . ($extension ? ".{$extension}" : '');
        }

        if (!empty($attachment['filename'])) {
            $fileInfo['fileName'] = $attachment['filename'];
        }

        return $fileInfo;
    }

    private function getUniqueId(array $attachment): ?string
    {
        $type = $attachment['type'] ?? 'unknown';
        $payload = $attachment['payload'] ?? [];

        return match ($type) {
            'video' => (function () use ($payload) {
                if (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $m)) {
                    return (string)$m[1];
                }
                return isset($payload['id']) ? (string)$payload['id'] : null;
            })(),
            'image', 'photo' => isset($payload['photo_id']) ? (string)$payload['photo_id'] : null,
            'audio', 'voice' => isset($payload['audio_id']) ? (string)$payload['audio_id'] : null,
            'file', 'document' => isset($payload['file_id']) ? (string)$payload['file_id'] : null,
            default => (!empty($payload['url']) && preg_match('/[&?]id=(\d+)/', $payload['url'], $m)) ? (string)$m[1] : null,
        };
    }

    private function getExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'application/pdf' => 'pdf', 'text/plain' => 'txt',
            'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
        ];
        return $map[$mimeType] ?? 'bin';
    }
}