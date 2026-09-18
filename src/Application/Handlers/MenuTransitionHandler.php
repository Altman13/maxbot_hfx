<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Psr\Log\LoggerInterface;

class MenuTransitionHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        private LoggerInterface $logger,
        private StateFileHandler $stateFileHandler,
        private MenuCreateAction $menuCreateAction,
        private MaxBotHelper $maxBot,
        private OkDeskHelper $okdeskHelper,
        private FileConverter $fileConverter,
        private array $commandStates
    ) {}

    // ============================================================
    // Точка входа — шаг 15
    // ============================================================
    public function handle(string $text, string $chatId, string $currentState, array $fileInfo, array $fileLinks): bool
    {
        $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');

        return
            $this->handleReturnToStartInMenuTransitions($text, $chatId)
            || $this->handleIfIssueExistMainMenuTransition($text, $chatId, $fileInfo, $fileLinks)
            || $this->handleScannerTransitions($text, $chatId, $fileInfo)
            || $this->handlePlotterScanResult($text, $chatId, $currentState)
            || $this->handleManualPlotterInputCase($text, $chatId, $currentState, $fileInfo, $fileLinks)
            || $this->handleCreateRequest($text, $chatId, $currentState, $issueId)
            || $this->handleControlCommandsInMenuTransitions($text, $chatId, $currentState)
            || $this->handleMainMenuInput($text, $chatId, $currentState, $fileInfo, $fileLinks)
            || $this->handleUnauthRequest($text, $chatId, $currentState, $fileInfo, $fileLinks)
            || $this->handleFileOnlySubmission($text, $chatId, $currentState, $fileInfo, $fileLinks);
    }

    // ============================================================
    // Шаг 16 — handleCreateNew
    // ============================================================
    public function handleCreateNew(string $text, array $fileInfo, string $chatId): bool
    {
        $currentState = $this->stateFileHandler->getStateField($chatId, 'state');

        if ($currentState === State::Create_New->value && !empty($fileInfo['filePath'])) {
            $lastFileBatch = (int)$this->stateFileHandler->getStateField($chatId, 'last_file_batch_time');
            $currentTime = time();

            if ($currentTime - $lastFileBatch > 10) {
                $this->stateFileHandler->setStateField($chatId, 'last_file_batch_time', $currentTime);
            }

            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Request_Created,
                ResponseMessage::Stayed_In_Current_Issue->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        if ($currentState === State::Create_New->value && $text !== "" && $text !== $currentState) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Request_Created,
                ResponseMessage::Stayed_In_Current_Issue->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        if ($text !== State::Create_New->value) {
            return false;
        }

        $issueData = $this->processIssueCompletion($chatId);
        $this->sendCompletionMessages($chatId, $issueData);

        $this->stateFileHandler->clearStateKey($chatId, 'issueId');
        $this->stateFileHandler->clearStateKey($chatId, 'file_links');
        $this->stateFileHandler->clearStateKey($chatId, 'lastComment');
        $this->stateFileHandler->setStateField($chatId, 'state', State::Expected_Plotter_Number->value);

        return true;
    }

    private function processIssueCompletion(string $chatId): array
    {
        $issueId = (int)$this->stateFileHandler->getStateField($chatId, 'issueId');
        $issueDetails = $this->okdeskHelper->fetchIssueDetails((int)$issueId);

        $issueData = [
            'id' => $issueDetails['id'] ?? $issueId,
            'date' => $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? ''),
            'original_id' => $issueId
        ];

        $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
        $this->okdeskHelper->addCommentToIssue($issueId, ResponseMessage::User_Closed_Request->value, (int)$contactId);
        $this->stateFileHandler->clearStateKey($chatId, 'issueId');

        return $issueData;
    }

    private function sendCompletionMessages(string $chatId, array $issueData): void
    {
        $message = str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueData['id'], $issueData['date']],
            ResponseMessage::Manually_Closing_Request->value
        );
        $this->maxBot->sendMessage((int)$chatId, $message);
        $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Create_Request,
            ResponseMessage::Instruction_Notification->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
    }

    // ============================================================
    // Подметоды handleMenuTransitions
    // ============================================================

    private function handleReturnToStartInMenuTransitions(string $text, string $chatId): bool
    {
        $currentState = $this->stateFileHandler->getStateField($chatId, 'state');

        if ($text !== State::Return_To_Start->value || $currentState === State::Issue_AlReady_Exist->value) {
            return false;
        }

        $this->stateFileHandler->setStateField($chatId, 'state', State::Unlock_Cutter->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handleIfIssueExistMainMenuTransition(string $text, string $chatId, array $fileInfo, array $fileLinks): bool
    {
        $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');

        $hasText = !empty(trim($text));
        $hasFiles = !empty($fileInfo['filePath']) || !empty($fileLinks);

        $issueDetails = $this->okdeskHelper->fetchIssueDetails((int)$issueId);
        $issueNumber = $issueDetails['id'] ?? $issueId;
        $issueDate = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

        $message = str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueNumber, $issueDate],
            ResponseMessage::Issue_AlReady_Exist->value
        );

        $textTransitions = [
            State::Main_Menu->value => [
                'prevState' => State::Main_Command,
                'currentState' => State::Main_Menu,
                'message' => ResponseMessage::Main_Menu->value
            ],
            State::Create_Request->value => [
                'prevState' => State::Main_Menu,
                'currentState' => State::Create_New,
                'message' => $message
            ],
            State::Unlock_Cutter->value => [
                'prevState' => State::Main_Menu,
                'currentState' => State::Create_New,
                'message' => $message
            ],
        ];

        if (($hasText || $hasFiles)
            && isset($textTransitions[$text])
            && !empty($issueId)
        ) {
            $transition = $textTransitions[$text];

            $this->stateFileHandler->setStateField($chatId, 'state', $transition['prevState']->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                $transition['currentState'],
                $transition['message'],
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        return false;
    }

    private function handleScannerTransitions(string $text, string $chatId, array $fileInfo): bool
    {
        $currentState = $this->stateFileHandler->getStateField($chatId, 'state');
        $fileLinks = $this->stateFileHandler->getStateField($chatId, 'file_links') ?? [];
        $hasFiles = !empty($fileInfo['filePath']) || !empty($fileLinks);

        if (
            $currentState == State::Unlock_Cutter->value &&
            ($text == State::Main_Command->value || $text == State::Start_Command->value)
        ) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        if ($currentState == State::Unlock_Cutter->value && $text == State::Create_Command->value) {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);
            $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Expected_Plotter_Number,
                ResponseMessage::Instruction_Notification->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        if ($currentState == State::Unlock_Cutter->value && $text == State::Close_Command->value) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::No_Open_Request->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        if ($currentState == State::Unlock_Cutter->value && (!empty($text) || $hasFiles)) {
            $this->sendQrScanningInstructions((int)$chatId);
            $this->qrCodeHelper->sendQRCodeScanner($chatId, ResponseMessage::Click_To_Open_Qr_Scanner->value);
            return true;
        }

        if (!in_array($text, [State::Scan_Plotter_QR_Code->value, State::Unlock_Cutter->value])) {
            return false;
        }

        $this->stateFileHandler->setStateField($chatId, 'state', $text);

        $message = $text === State::Scan_Plotter_QR_Code->value
            ? ResponseMessage::Click_To_Open_Qr_Scanner->value
            : ResponseMessage::How_To_Find_Unlock_Code->value;
        $this->sendQrScanningInstructions((int)$chatId);
        $this->qrCodeHelper->sendQRCodeScanner($chatId, $message);
        return true;
    }

    private function handlePlotterScanResult(string $text, string $chatId, string $currentState): bool
    {
        if ($currentState !== State::Scan_Plotter_QR_Code->value || $text === State::Unlock_Cutter->value) {
            return false;
        }

        $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Expected_Plotter_Number,
            ResponseMessage::Instruction_Notification->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
        return true;
    }

    private function handleCreateRequest(string $text, string $chatId, string $currentState, string $issueId): bool
    {
        if ($currentState !== State::Main_Menu->value || $text !== State::Create_Request->value) {
            return false;
        }

        if (empty($issueId)) {
            $this->initiateNewRequest($chatId);
            return true;
        }

        $this->showExistingIssueWarning($chatId, $issueId);
        return true;
    }

    private function handleControlCommandsInMenuTransitions(string $text, string $chatId, string $currentState): bool
    {
        if ($currentState !== State::Main_Menu->value && $currentState !== State::Expected_Plotter_Number->value) {
            return false;
        }

        return match ($text) {
            State::Create_Command->value => $this->handleCreateCommandsInMenuTransitions($chatId),
            State::Main_Command->value => $this->handleMainCommandsInMenuTransitions($chatId),
            State::Close_Command->value => $this->handleCloseCommandInMenuTransitions($chatId),
            default => false
        };
    }

    private function handleCreateCommandsInMenuTransitions(string $chatId): bool
    {
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if ($issueId === '') {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Expected_Plotter_Number,
                ResponseMessage::Instruction_Notification->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
            $this->maxBot->sendMenu($menu);
            return true;
        }

        $issueDetails = $this->okdeskHelper->fetchIssueDetails((int)$issueId);
        $issueNumber = $issueDetails['id'] ?? $issueId;
        $issueDate = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

        $message = str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueNumber, $issueDate],
            ResponseMessage::Issue_AlReady_Exist->value
        );

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Create_New,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handleMainCommandsInMenuTransitions(string $chatId): bool
    {
        $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Command);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handleCloseCommandInMenuTransitions(string $chatId): bool
    {
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if ($issueId !== '') {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Issue_AlReady_Exist,
                ResponseMessage::Are_You_Sure->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        $this->stateFileHandler->setStateField($chatId, 'state', State::Close_Command->value);
        $this->maxBot->sendMessage((int)$chatId, ResponseMessage::No_Open_Request->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handleMainMenuInput(string $text, string $chatId, string $currentState, array $fileInfo, array $fileLinks): bool
    {
        if ($currentState !== State::Main_Menu->value) {
            return false;
        }

        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if (!empty($issueId)) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Request_Created,
                ResponseMessage::Stayed_In_Current_Issue->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        $hasContent = !empty(trim($text)) || !empty($fileInfo['filePath']) || !empty($fileLinks);
        $isCommand = in_array($text, $this->commandStates);

        if (!$hasContent || $isCommand) {
            return false;
        }

        $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Expected_Plotter_Number,
            ResponseMessage::Instruction_Notification->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);

        return true;
    }

    private function handleUnauthRequest(string $text, string $chatId, string $currentState, array $fileInfo, array $fileLinks): bool
    {
        if ($currentState !== State::Create_Request_Without_Auth->value) {
            return false;
        }

        if (empty(trim($text))) {
            return false;
        }

        [$companyId, $contactId] = $this->stateFileHandler->getStateFields($chatId);
        $plotterNotFound = $this->stateFileHandler->getStateField($chatId, 'plotter_not_found');
        $plotter_number = (string)$this->stateFileHandler->getStateField($chatId, 'inventory_number');

        $issueTitle = $_ENV['ISSUE_TITLE'];
        $issueType = $_ENV['ISSUE_TYPE'];

        if (!$plotterNotFound) {
            $issueTitle = $_ENV['PLOTTER_ACTIVATION_TITLE'];
            $issueType = $_ENV['ISSUE_TYPE_HF_PLOTTER_ACTIVATION'];
        }

        $responseData = $this->okdeskHelper->createPlotterActivationRequest(
            (string)$companyId,
            $chatId,
            (string)$contactId,
            $text,
            $issueTitle,
            $issueType,
            null,
            $plotter_number
        );
        $issueId = $responseData['id'] ?? 'неизвестен';
        $issueIdString = (string)$issueId;

        if ($issueId === 'неизвестен') {
            $this->logger->error('Ошибка создания заявки', [
                'chatId' => $chatId, 'companyId' => $companyId,
                'contactId' => $contactId, 'responseData' => $responseData, 'text' => $text
            ]);
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Error_Occurred->value);
            return true;
        }

        $messageTemplate = $plotterNotFound
            ? ResponseMessage::Request_Created_Notification_Plotter_Activation->value
            : ResponseMessage::Request_Created_Notification->value;

        $message = str_replace('{{номер}}', (string)$issueId, $messageTemplate);

        $this->stateFileHandler->setStateField($chatId, 'issueId', $issueId);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Request_Created,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);

        $this->stateFileHandler->clearStateKey($chatId, 'plotter_not_found');

        if (!empty($fileInfo['filePath']) || !empty($fileLinks)) {
            $this->handleTechSupportFileFromTransition($text, $chatId, $fileInfo, $currentState);
        }

        return true;
    }

    private function handleFileOnlySubmission(string $text, string $chatId, string $currentState, array $fileInfo, array $fileLinks): bool
    {
        if ($currentState !== State::Expected_Plotter_Number->value) {
            return false;
        }

        if (!empty($fileInfo['filePath']) || !empty($fileLinks)) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        return false;
    }

    // ============================================================
    // Manual plotter input
    // ============================================================

    private function handleManualPlotterInputCase(string $text, string $chatId, string $currentState, array $fileInfo, array $fileLinks): bool
    {
        if ($currentState == State::Issue_AlReady_Exist->value && (!empty($fileInfo['filePath']) || !empty($fileLinks))) {
            $lastMessageTime = (int)$this->stateFileHandler->getStateField($chatId, 'last_file_batch_time');
            $currentTime = time();

            if ($currentTime - $lastMessageTime > 10) {
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Request_Created,
                    ResponseMessage::Stayed_In_Current_Issue->value,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
                $this->stateFileHandler->setStateField($chatId, 'last_file_batch_time', $currentTime);
            }
            return true;
        }

        if ($currentState === State::Issue_AlReady_Exist->value && $text !== "" && $text !== $currentState) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Request_Created,
                ResponseMessage::Stayed_In_Current_Issue->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        $isExpectedPlotterState = $currentState === State::Expected_Plotter_Number->value;
        $isUnauthStateWithContent = $currentState === State::Create_Request_Without_Auth->value
            && (!empty($fileInfo['files']));

        if (!$isExpectedPlotterState && !$isUnauthStateWithContent) {
            return false;
        }

        $hasContent = !empty(trim($text)) || !empty($fileInfo['files']) || !empty($fileLinks);
        $isNotCommand = !in_array($text, $this->commandStates);

        if ($isExpectedPlotterState && $hasContent && $isNotCommand) {
            return $this->handleManualPlotterInput($text, $chatId, $fileInfo, $fileLinks);
        }

        if ($isUnauthStateWithContent) {
            return $this->handleManualPlotterInput($text, $chatId, $fileInfo, $fileLinks);
        }

        return false;
    }

    private function handleManualPlotterInput(string $text, string $chatId, array $fileInfo = [], array $fileLinks = []): bool
    {
        $hasFiles = !empty($fileInfo['files']);
        $hasText = !empty(trim($text));

        if ($hasText && !$hasFiles) {
            return $this->handleTextCaseInManualPlotterInput($text, $chatId, $fileInfo, $fileLinks);
        }

        if ($hasFiles) {
            return $this->handleFileCaseinManualPlotterInput($text, $chatId, $fileInfo, $fileLinks);
        }
        return false;
    }

    private function handleFileCaseinManualPlotterInput(string $text, string $chatId, array $fileInfo, array $fileLinks): bool
    {
        $jsonData = $this->getRequestData();
        $attachments = [];

        if (isset($jsonData['message']['body']['attachments'])) {
            $attachments = $jsonData['message']['body']['attachments'];
        } elseif (isset($jsonData['message']['attachments'])) {
            $attachments = $jsonData['message']['attachments'];
        } elseif (isset($jsonData['callback_query']['message']['attachments'])) {
            $attachments = $jsonData['callback_query']['message']['attachments'];
        }

        $contactId = $this->stateFileHandler->getStateField($chatId, 'contactId');
        $companyId = $this->stateFileHandler->getStateField($chatId, 'companyId');
        $currentState = $this->stateFileHandler->getStateField($chatId, 'state');

        if ($currentState === State::Expected_Plotter_Number->value) {
            $this->stateFileHandler->clearStateKey($chatId, 'file_links');
            return $this->handlePlotterNotFound($text, $chatId);
        }

        $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');
        if ($issueId !== "") {
            $this->showExistingIssueWarning($chatId, $issueId);
            return true;
        }

        $inventory_number = $this->stateFileHandler->getStateField($chatId, 'inventory_number');
        $plotterNotFound = $this->stateFileHandler->getStateField($chatId, 'plotter_not_found');

        [$issueTitle, $issueType] = $plotterNotFound
            ? [$_ENV['ISSUE_TITLE'], $_ENV['ISSUE_TYPE']]
            : [$_ENV['PLOTTER_ACTIVATION_TITLE'], $_ENV['ISSUE_TYPE_HF_PLOTTER_ACTIVATION']];

        $responseData = $this->okdeskHelper->createIssueRequest(
            (string)$companyId,
            $chatId,
            (string)$contactId,
            $text,
            $issueTitle,
            $issueType,
            null,
            $inventory_number
        );
        $issueId = $responseData['id'] ?? 'неизвестен';

        if ($issueId === 'неизвестен') {
            $this->logger->error('Ошибка создания заявки', [
                'chatId' => $chatId, 'companyId' => $companyId,
                'contactId' => $contactId, 'responseData' => $responseData, 'text' => $text
            ]);
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Error_Occurred->value);
            return true;
        }
        $issueIdString = (string)$issueId;

        if (!empty($attachments)) {
            $hasMultipleAttachments = is_array($attachments) && count($attachments) > 1;

            if ($hasMultipleAttachments) {
                foreach ($attachments as $index => $attachment) {
                    $singleFileInfo = $this->fileConverter->convert($attachment, $index);

                    if (!empty($singleFileInfo['filePath']) || !empty($singleFileInfo['fileId'])) {
                        $this->okdeskHelper->handleFileAddition($text, $singleFileInfo, $issueIdString, $contactId);

                        if ($index < count($attachments) - 1) {
                            usleep(300000);
                        }
                    }
                }
            } else {
                $singleAttachment = $attachments[0] ?? null;
                if ($singleAttachment) {
                    $singleFileInfo = $this->fileConverter->convert($singleAttachment, 0);

                    if ($singleFileInfo && (!empty($singleFileInfo['filePath']) || !empty($singleFileInfo['fileId']))) {
                        $fileName = $singleFileInfo['fileName'] ?? '';
                        $existingLinks = $this->stateFileHandler->getStateField($chatId, 'file_links');
                        $fileLinksArray = [];

                        if (is_array($existingLinks)) {
                            $fileLinksArray = $existingLinks;
                        } elseif (is_string($existingLinks) && !empty($existingLinks)) {
                            $decoded = json_decode($existingLinks, true);
                            $fileLinksArray = is_array($decoded) ? $decoded : [$existingLinks];
                        }

                        if (!in_array($fileName, $fileLinksArray)) {
                            if (!empty($singleFileInfo['token'])) {
                                $fileLinksArray[] = (string)$singleFileInfo['token'];
                            }

                            $this->okdeskHelper->handleFileAddition($text, $singleFileInfo, $issueIdString, $contactId);

                            $linksToSave = count($fileLinksArray) > 1 ? json_encode($fileLinksArray) : ($fileLinksArray[0] ?? '');
                            $this->stateFileHandler->setStateField($chatId, 'file_links', $linksToSave);
                        }
                    }
                }
            }
        }

        return $this->finalizeRequestCreationInPlotterManualInput($chatId, $issueIdString);
    }

    private function handleTextCaseInManualPlotterInput(string $text, string $chatId, array $fileInfo = [], array $fileLinks = []): bool
    {
        if (mb_strlen($text) > 50) {
            return false;
        }

        $hasFiles = !empty($fileInfo['filePath']) || !empty($fileLinks);
        if ($hasFiles) {
            $this->stateFileHandler->clearStateKey($chatId, 'file_links');
            return true;
        }

        $equipmentId = $this->okdeskHelper->getEquipmentIdByInventoryNumber($text);

        if (!$equipmentId) {
            $this->stateFileHandler->setStateField($chatId, 'plotter_not_found', 'true');
            $this->stateFileHandler->clearStateKey($chatId, 'file_links');
            return $this->handlePlotterNotFound($text, $chatId);
        }

        $maintenanceId = $this->okdeskHelper->getMaintenanceIdByInventoryNumber($text);

        if (!$maintenanceId) {
            return $this->handlePlotterActivationRequired($text, $chatId, $text);
        }

        $this->stateFileHandler->setStateField($chatId, 'plotter_not_found', 'false');
        return $this->createIssueWithPlotter($text, $chatId, $maintenanceId, $fileInfo, $fileLinks);
    }

    private function handlePlotterNotFound(string $text, string $chatId): bool
    {
        $message = str_replace('{{номер}}', $text, ResponseMessage::Plotter_Not_Found->value);
        $this->stateFileHandler->setStateField($chatId, 'inventory_number', $text);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Create_Request_Without_Auth,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->stateFileHandler->setStateField($chatId, 'plotter_not_found', 'true');
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handlePlotterActivationRequired(string $text, string $chatId, $equipmentId): bool
    {
        $this->stateFileHandler->setStateField($chatId, 'inventory_number', $equipmentId);
        $message = str_replace('{{номер}}', $text, ResponseMessage::Plotter_Activation_Required->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Create_Request_Without_Auth,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function createIssueWithPlotter(
        string $text,
        string $chatId,
        ?int $maintenanceId = null,
        array $fileInfo = [],
        array $fileLinks = []
    ): bool {
        $maintenanceData = null;
        $maintenanceEntityId = null;

        if ($maintenanceId !== null) {
            $maintenanceData = $this->okdeskHelper->getAddressByInventoryNumber($text, $maintenanceId);
            $maintenanceEntityId = (string)($maintenanceData["maintenance_entity_id"] ?? '');
        }

        $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');
        if ($issueId !== "") {
            $this->showExistingIssueWarning($chatId, $issueId);
            return true;
        }

        [$companyId, $contactId] = $this->stateFileHandler->getStateFields($chatId);

        $responseData = $this->okdeskHelper->createIssueRequest(
            (string)$companyId,
            $chatId,
            (string)$contactId,
            $text,
            $_ENV['ISSUE_TITLE'],
            $_ENV['ISSUE_TYPE'],
            $maintenanceEntityId
        );

        $issueId = $responseData['id'] ?? 'неизвестен';

        if ($issueId === 'неизвестен') {
            $this->logger->error('Ошибка создания заявки', [
                'chatId' => $chatId, 'companyId' => $companyId,
                'contactId' => $contactId, 'responseData' => $responseData, 'text' => $text
            ]);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::GoToBack,
                ResponseMessage::Error_Occurred->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return false;
        }

        $allFiles = array_merge($fileLinks, $fileInfo);
        $issueIdString = (string)$issueId;

        if (!empty($allFiles) && isset($responseData['id'])) {
            $this->okdeskHelper->handleFileAddition($text, $allFiles, $issueIdString, $contactId);
            $this->stateFileHandler->setStateField($chatId, 'file_links', []);
        }

        return $this->finalizeRequestCreationInPlotterManualInput($chatId, $issueIdString);
    }

    private function finalizeRequestCreationInPlotterManualInput(string $chatId, string $issueId): bool
    {
        $plotterNotFound = $this->stateFileHandler->getStateField($chatId, 'plotter_not_found');
        $finalResponseMessage = $plotterNotFound
            ? ResponseMessage::Request_Created_Notification_Plotter_Activation->value
            : ResponseMessage::Request_Created_Notification->value;

        $message = str_replace('{{номер}}', $issueId, $finalResponseMessage);

        $this->stateFileHandler->setStateField($chatId, 'issueId', $issueId);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Request_Created,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );

        $this->maxBot->sendMenu($menu);
        $this->stateFileHandler->clearStateKey($chatId, 'plotter_not_found');

        return true;
    }

    // ============================================================
    // Вспомогательные
    // ============================================================

    private function initiateNewRequest(string $chatId): void
    {
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Expected_Plotter_Number,
            ResponseMessage::Instruction_Notification->value,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
        $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
    }

    private function showExistingIssueWarning(string $chatId, string $issueId): void
    {
        $issueDetails = $this->okdeskHelper->fetchIssueDetails((int)$issueId);
        $issueNumber = $issueDetails['id'] ?? $issueId;
        $issueDate = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

        $message = str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueNumber, $issueDate],
            ResponseMessage::Issue_AlReady_Exist->value
        );

        $this->stateFileHandler->setStateField($chatId, 'state', State::Issue_AlReady_Exist->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Issue_AlReady_Exist,
            $message,
            self::KEYBOARD_TYPE_INLINE
        );
        $this->maxBot->sendMenu($menu);
    }

    private function handleTechSupportFileFromTransition(string $text, string $chatId, array $fileInfo, string $currentState): void
    {
        $contactId = (string)$this->stateFileHandler->getStateField($chatId, 'contactId');
        $currentIssue = $this->stateFileHandler->getStateField($chatId, 'issueId');
        if ($currentIssue) {
            $this->okdeskHelper->handleFileAddition($text, $fileInfo, $currentIssue, $contactId);
        }
    }

    private function sendQrScanningInstructions(int $chatId): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $photoPath = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'how_to_scan.png';
        $this->maxBot->uploadAndSendPhoto((int)$chatId, $photoPath);
    }

    private function sendPhotoHint(string $chatId, string $state): void
    {
        try {
            if ($state === State::Expected_Plotter_Number->value) {
                $projectRoot = dirname(__DIR__, 3);
                $photoPath = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'plotter_number.jpg';
                if (!file_exists($photoPath)) {
                    $this->maxBot->sendMessage((int)$chatId, "Подсказка временно недоступна.");
                    return;
                }
                $this->maxBot->uploadAndSendPhoto((int)$chatId, $photoPath);
            }
        } catch (\Exception $e) {
            $this->maxBot->sendMessage((int)$chatId, "Не удалось отправить подсказку.");
        }
    }

    private function getRequestData(): array
    {
        $postData = file_get_contents("php://input");
        return json_decode($postData, true) ?? [];
    }
}