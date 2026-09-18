<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;

class UnsupportedTypeHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        private StateFileHandler $stateFileHandler,
        private MenuCreateAction $menuCreateAction,
        private MaxBotHelper $maxBot,
        private QrCodeHelper $qrCodeHelperNew,
        private OkDeskHelper $okDeskHelper,
        private array $commandStates
    ) {}

    public function handle(string $text, array $message, string $chatId, string $currentState): bool
    {
        if ($currentState == State::Share_Contact->value) {
            return false;
        }

        // Файл на главном меню
        if (isset($message['message']['body']['attachments'][0]['type']) && $currentState == State::Main_Menu->value) {
            $issueId = (string)$this->stateFileHandler->getStateField($chatId, 'issueId');
            if (empty($issueId)) {
                $this->initiateNewRequest($chatId);
                return true;
            }
            $this->showExistingIssueWarning($chatId, $issueId);
            return true;
        }

        // Файл на экране разблокировки резчика
        if (
            isset($message['message']['body']['attachments'][0]['type']) && $currentState == State::Expected_Plotter_Number->value
            && $text !== State::Return_To_Start->value
        ) {
            $this->sendQrScanningInstructions((int)$chatId);
            $this->qrCodeHelperNew->sendQRCodeScanner($chatId, ResponseMessage::Click_To_Open_Qr_Scanner->value);
            return true;
        }

        if (in_array($text, $this->commandStates)) return false;

        if ($text == State::Unlock_Cutter->value || $text == State::Return_To_Start->value) return false;

        if ($text === State::Main_Menu->value) {
            if (!empty($text) && $text !== State::Create_Request->value) {
                $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Main_Menu,
                    ResponseMessage::Main_Menu->value,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
                return true;
            }

            $unsupportedTypesForOK = [
                'voice', 'sticker', 'animation', 'audio',
                'location', 'contact', 'forward_from', 'forward_from_chat'
            ];

            foreach ($unsupportedTypesForOK as $type) {
                if (isset($message[$type])) {
                    $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Unsupported_Format_Message->value);
                    return true;
                }
            }

            return false;
        }

        $unsupportedTypes = [
            'voice', 'sticker', 'animation', 'audio',
            'location', 'contact', 'forward_from', 'forward_from_chat'
        ];

        foreach ($unsupportedTypes as $type) {
            if (isset($message[$type])) {
                $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Unsupported_Format_Message->value);
                return true;
            }
        }

        return false;
    }

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
        $issueDetails = $this->okDeskHelper->fetchIssueDetails((int)$issueId);
        $issueNumber = $issueDetails['id'] ?? $issueId;
        $issueDate = $this->okDeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

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
                    $this->maxBot->sendMessage((int)$chatId, "Подсказка временно недоступна. Пожалуйста, обратитесь к инструкции.");
                    return;
                }
                $this->maxBot->uploadAndSendPhoto((int)$chatId, $photoPath);
            }
        } catch (\Exception $e) {
            $this->maxBot->sendMessage((int)$chatId, "Не удалось отправить подсказку.");
        }
    }
}