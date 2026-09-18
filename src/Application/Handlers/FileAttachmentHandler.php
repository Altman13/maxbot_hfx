<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Psr\Log\LoggerInterface;

class FileAttachmentHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private OkDeskHelper $okdeskHelper;
    private FileConverter $fileConverter;

    public function __construct(
        LoggerInterface $logger,
        StateFileHandler $stateFileHandler,
        MenuCreateAction $menuCreateAction,
        MaxBotHelper $maxBot,
        OkDeskHelper $okdeskHelper,
        FileConverter $fileConverter
    ) {
        $this->logger = $logger;
        $this->stateFileHandler = $stateFileHandler;
        $this->menuCreateAction = $menuCreateAction;
        $this->maxBot = $maxBot;
        $this->okdeskHelper = $okdeskHelper;
        $this->fileConverter = $fileConverter;
    }

    public function handleReturnToStart(string $chatId, array $fileInfo, string $currentState): bool
    {
        if ($currentState === State::Return_To_Start->value && !empty($fileInfo['filePath'] ?? '')) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Select_From_Menu->value);
            return true;
        }
        return false;
    }

    public function handleBackState(string $chatId, array $fileInfo, string $currentState): bool
    {
        if ($currentState === State::GoToBack->value && !empty($fileInfo["filePath"] ?? '')) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Select_From_Menu->value);
            return true;
        }
        return false;
    }

    public function handleFileRestriction(array $fileInfo, string $currentState, string $chatId): bool
    {
        if ($currentState === State::Create_Command->value && !empty($fileInfo["filePath"] ?? '')) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::ChooseFromMenu->value);
            return true;
        }
        return false;
    }

    public function handleTechSupportFile(string $text, string $chatId, array $fileInfo, string $currentState): bool
    {
        if (!empty($fileInfo['filePath'] ?? '') && $currentState === State::Create_Request->value) {
            [$companyId, $contactId, $maintenanceEntityId] = array_map(
                fn($field) => (string)$this->stateFileHandler->getStateField($chatId, $field),
                ['companyId', 'contactId', 'maintenance_entity_id']
            );
            $this->createIssueAndSendMessage($text, $chatId, $companyId, $contactId, $maintenanceEntityId);
            $currentIssue = $this->stateFileHandler->getStateField($chatId, 'issueId');
            if ($currentIssue) {
                $this->okdeskHelper->handleFileAddition($text, $fileInfo, $currentIssue, $contactId);
                $existingFileLinks = $this->stateFileHandler->getStateField($chatId, 'file_links') ?? [];
                $updatedFileLinks = array_values(array_unique(array_merge($existingFileLinks, [$fileInfo['fileName']])));
                $this->stateFileHandler->setStateField($chatId, 'file_links', $updatedFileLinks);
            }
            return true;
        }
        return false;
    }

    public function addFileAttachmentsToIssue(
        string $text,
        string $chatId,
        array $filesInfo,
        array $message,
        string $currentState,
        array $validCurrentStates
    ): bool {
        if (empty($filesInfo) || !in_array($currentState, $validCurrentStates, true)) {
            return false;
        }

        $caption = $message['caption'] ?? $message['message']['caption'] ?? '';
        $isCombined = $message['is_combined'] ?? false;
        $combinedText = ($isCombined && !empty($caption)) ? $caption : $text;

        $uploadedFiles = $this->stateFileHandler->getStateField($chatId, 'file_links');
        $fileLinks = [];
        if (is_array($uploadedFiles)) {
            $fileLinks = $uploadedFiles;
        } elseif (is_string($uploadedFiles) && !empty($uploadedFiles)) {
            $decoded = json_decode($uploadedFiles, true);
            if (is_array($decoded)) {
                $fileLinks = $decoded;
            } else {
                $fileLinks = [$uploadedFiles];
            }
        }

        $contactId = (string)$this->stateFileHandler->getStateField($chatId, 'contactId');
        $currentIssue = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if (!$currentIssue && (!empty($combinedText) || $isCombined)) {
            $this->createIssueWithText($combinedText, $chatId);
            $currentIssue = $this->stateFileHandler->getStateField($chatId, 'issueId');

            if (!$currentIssue) {
                return false;
            }
        }

        if (!$currentIssue) {
            return false;
        }

        $hasNewFiles = false;

        $attachments = $message['message']['body']['attachments'] ?? [];
        $hasMultipleAttachments = is_array($attachments) && count($attachments) > 1;

        if ($hasMultipleAttachments) {
            $processedCount = 0;

            foreach ($attachments as $index => $attachment) {
                $singleFileInfo = $this->fileConverter->convert($attachment, $index);

                if ($singleFileInfo['type'] === 'video') {
                    continue;
                }

                $fileIdentifier = $singleFileInfo['fileId'] ?? $singleFileInfo['fileName'] ?? $singleFileInfo['filePath'] ?? null;

                if ($fileIdentifier && !in_array($fileIdentifier, $fileLinks)) {
                    if (!empty($singleFileInfo['filePath']) || !empty($singleFileInfo['fileId'])) {
                        $this->okdeskHelper->handleFileAddition($combinedText, $singleFileInfo, $currentIssue, $contactId);
                        $processedCount++;
                        $hasNewFiles = true;

                        if (isset($singleFileInfo['token']) && (is_string($singleFileInfo['token']) || is_numeric($singleFileInfo['token']))) {
                            $fileLinks[] = (string)$singleFileInfo['token'];
                        } else {
                            $fileLinks[] = $fileIdentifier;
                        }

                        if ($index < count($attachments) - 1) {
                            usleep(300000);
                        }
                    }
                }
            }

            $this->logger->info("Processed $processedCount files from batch");
        } else {
            $singleFile = null;

            if (!empty($filesInfo['files'][0])) {
                $singleFile = $filesInfo['files'][0];
            } elseif (!empty($filesInfo['filePath']) || !empty($filesInfo['fileId'])) {
                $singleFile = $filesInfo;
            } elseif (isset($filesInfo[0]) && is_array($filesInfo[0])) {
                $singleFile = $filesInfo[0];
            } else {
                $singleFile = $filesInfo;
            }

            if ($singleFile && (isset($singleFile['fileName']) || isset($singleFile['fileId']))) {
                if (($singleFile['type'] ?? '') !== 'video') {
                    $fileIdentifier = $singleFile['fileId'] ?? $singleFile['fileName'] ?? $singleFile['filePath'] ?? '';

                    if (!empty($fileIdentifier) && !in_array($fileIdentifier, $fileLinks)) {
                        $token = $singleFile['token'] ?? null;

                        if ($token && (is_string($token) || is_numeric($token))) {
                            $fileLinks[] = (string)$token;
                        } else {
                            $fileLinks[] = $fileIdentifier;
                        }

                        $this->okdeskHelper->handleFileAddition($combinedText, $singleFile, $currentIssue, $contactId);
                        $hasNewFiles = true;
                    }
                }
            }
        }

        if ($hasNewFiles) {
            $fileLinks = array_unique($fileLinks);

            $linksToSave = count($fileLinks) > 1 ? json_encode($fileLinks) : ($fileLinks[0] ?? '');
            $this->stateFileHandler->setStateField($chatId, 'file_links', $linksToSave);

            $this->logger->info("Всего загружено файлов: " . count($fileLinks));

            if ($isCombined && $currentIssue) {
                $messageText = str_replace('{{номер}}', (string)$currentIssue, ResponseMessage::Request_Created_Notification->value);
                $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Request_Created,
                    $messageText,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
            }
        }

        return $hasNewFiles;
    }

    private function createIssueWithText(string $text, string $chatId): void
    {
        $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
        $companyId = $this->stateFileHandler->getStateField($chatId, 'companyId');
        $inventory_number = $this->stateFileHandler->getStateField($chatId, 'inventory_number');

        if (!$contactId || !$companyId) {
            $this->logger->error("Cannot create issue: missing contactId or companyId for chatId: $chatId");
            return;
        }

        $responseData = $this->okdeskHelper->createIssueRequest(
            (string)$companyId,
            $chatId,
            (string)$contactId,
            $text,
            $_ENV['ISSUE_TITLE'],
            $_ENV['ISSUE_TYPE'],
            null,
            $inventory_number
        );

        $issueId = $responseData['id'] ?? null;
        if ($issueId) {
            $this->stateFileHandler->setStateField($chatId, 'issueId', (string)$issueId);
            $this->logger->info("Created issue $issueId from text: $text");
        } else {
            $this->logger->error("Failed to create issue from text: $text", $responseData);
        }
    }

    private function createIssueAndSendMessage(string $text, string $chatId, string $companyId, string $contactId, string $maintenanceEntityId): void
    {
        $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');
        if ($issueId !== "") {
            return;
        }

        $responseData = $this->okdeskHelper->createIssue($text, $companyId, $chatId, $contactId, $maintenanceEntityId);
        $issueId = $responseData['id'] ?? 'неизвестен';

        if ($issueId === 'неизвестен') {
            $this->logger->error('Ошибка создания заявки', [
                'chatId' => $chatId, 'companyId' => $companyId,
                'contactId' => $contactId, 'responseData' => $responseData, 'text' => $text,
            ]);
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Error_Occurred->value);
            return;
        }

        $message = str_replace('{{номер}}', (string)$issueId, ResponseMessage::Request_Created_Notification->value);

        $this->stateFileHandler->setStateField($chatId, 'issueId', $issueId);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Request_Created,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
    }

    public function handleFileLinks(array $message, string $chatId): void
    {
        $existingFileLinks = $this->stateFileHandler->getStateField($chatId, 'file_links') ?? [];
        if (!is_array($existingFileLinks)) {
            $existingFileLinks = [];
        }

        $newFileLinks = [];

        if (isset($message['document']) && is_array($message['document'])) {
            $filePath = $this->getFilePath($message['document']['file_id']);
            if ($filePath !== null) {
                $newFileLinks[] = $message['document']['file_id'];
            }
        }

        $updatedFileLinks = array_values(array_unique(array_merge($existingFileLinks, $newFileLinks)));
        $this->stateFileHandler->setStateField($chatId, 'file_links', $updatedFileLinks);
    }

    private function getFilePath(string $fileId): ?string
    {
        $apiKey = $_ENV['TELEGRAM_API_KEY'] ?? null;
        if (!$apiKey) return null;

        $url = "https://api.telegram.org/bot{$apiKey}/getFile?file_id={$fileId}";
        $response = @file_get_contents($url);
        if ($response === false) return null;

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        if (isset($data['result']['file_path'])) {
            return "https://api.telegram.org/file/bot{$apiKey}/" . $data['result']['file_path'];
        }

        return null;
    }
}