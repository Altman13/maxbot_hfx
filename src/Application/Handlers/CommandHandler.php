<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Psr\Log\LoggerInterface;

class CommandHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        private LoggerInterface $logger,
        private StateFileHandler $stateFileHandler,
        private StateManagerHandler $stateManagerHandler,
        private MenuCreateAction $menuCreateAction,
        private MaxBotHelper $maxBot,
        private OkDeskHelper $okdeskHelper,
    ) {}

    // ============================================================
    // Шаг 2: handleStartAndDeleteCommands
    // ============================================================

    public function handleStartAndDelete(string $text, string $chatId): bool
    {
        if ($text === State::Delete_Command->value) {
            $this->resetUserState($chatId, State::Start);
            return true;
        }
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');
        $phone_number = $this->stateFileHandler->getStateField($chatId, 'phone_number');

        //если удалили историю после авторизации и зашли в бота нажав /start
        if ($text === State::Start_Command->value && $phone_number !== "") {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Command->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Main_Menu,
                ResponseMessage::Main_Menu->value
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }

        //ввели /start когда есть активная заявка
        if ($text === State::Start_Command->value && $issueId === "") {
            return $this->handleStartCommand($chatId);
        }

        return false;
    }

    private function handleStartCommand(string $chatId): bool
    {
        $this->resetUserState($chatId, State::Share_Contact);
        return true;
    }

    private function resetUserState(string $chatId, State $nextState): void
    {
        $this->stateFileHandler->clearAllState($chatId);
        $this->maxBot->requestContact((int)$chatId);
    }

    // ============================================================
    // Шаг 13: handleCommandIfRequestWithoutAuth
    // ============================================================

    public function handleCommandIfRequestWithoutAuth(string $text, string $currentState, string $chatId): bool
    {
        if ($text === State::Return_To_Start->value) {
            return false;
        }

        if ($currentState !== State::Create_Request_Without_Auth->value || empty($text)) {
            return false;
        }

        return match ($text) {
            State::Main_Command->value => $this->handleMainCommand($chatId),
            State::Create_Command->value => $this->handleCreateCommand($chatId),
            State::Close_Command->value => $this->handleCloseCommandInMenuTransitions($chatId),
            default => false
        };
    }

    // ============================================================
    // Шаг 17: handleOkForStateCommand + isValidOkState
    // ============================================================

    public function handleOkForStateCommand(
        string $text,
        array $message,
        string $chatId,
        string $currentState,
        array $commandStates
    ): bool {
        if ($this->isValidOkState($text, $currentState, $commandStates)) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Create_Request,
                ResponseMessage::Instruction_Notification->value,
                self::KEYBOARD_TYPE_INLINE
            );

            $this->maxBot->sendMenu($menu);
            $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);

            return true;
        }

        return false;
    }

    private function isValidOkState(string $text, string $currentState, array $commandStates): bool
    {
        $isOkState = $currentState === State::Main_Menu->value
            && $currentState === State::Close_Command->value;

        if (!$isOkState) {
            return false;
        }

        $hasText   = trim($text) !== '';
        $isCommand = in_array($text, $commandStates, true);

        return $hasText || $isCommand;
    }

    // ============================================================
    // Шаг 18: handleCommandIfRequestExist
    // ============================================================

    public function handleCommandIfRequestExist(string $text, string $currentState, string $chatId): bool
    {
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if ($issueId === '') {
            return false;
        }

        switch ($text) {
            case State::Create_Command->value:
                $issueDetails = $this->okdeskHelper->fetchIssueDetails((int)$issueId);
                $issueNumber = $issueDetails['id'] ?? (int)$issueId;
                $issueDate   = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

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

            case State::Main_Command->value:
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Main_Menu,
                    ResponseMessage::Main_Menu->value,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
                return true;

            case State::Close_Command->value:
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Issue_AlReady_Exist,
                    ResponseMessage::Are_You_Sure->value,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
                return true;

            default:
                return false;
        }
    }

    // ============================================================
    // Шаг 19: handleCommands
    // ============================================================

    public function handleCommands(string $text, string $chatId): bool
    {
        $commands = [
            State::Main_Command->value,
            State::Create_Command->value,
            State::Close_Command->value,
            State::Delete_Command->value,
        ];

        if (!in_array($text, $commands, true)) return false;

        switch ($text) {
            case State::Delete_Command->value:
                $this->stateFileHandler->clearAllState($chatId);
                $this->stateManagerHandler->processState($chatId, State::Share_Contact);
                break;

            case State::Create_Command->value:
                $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);
                $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                    $chatId,
                    State::Expected_Plotter_Number,
                    ResponseMessage::Instruction_Notification->value,
                    self::KEYBOARD_TYPE_INLINE
                );
                $this->maxBot->sendMenu($menu);
                $this->sendPhotoHint($chatId, State::Expected_Plotter_Number->value);
                break;
        }
        return true;
    }

    // ============================================================
    // Шаг 20: handleUnknownTextIfRequestExist
    // ============================================================

    public function handleUnknownTextIfRequestExist(string $text, string $chatId): bool
    {
        $issueId = $this->stateFileHandler->getStateField($chatId, 'issueId');

        if ($issueId === "") {
            return false;
        }
        if ($text === "") {
            return false;
        }

        //инлайн кнопки в открытой заявке невалидные переходы по стейтам
        if ($text == State::Yes->value || $text == State::No->value || $text == State::Stay->value) {
            return false;
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
        return true;
    }

    // ============================================================
    // Приватные помощники
    // ============================================================

    private function handleMainCommand(string $chatId): bool
    {
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value
        );
        $this->maxBot->sendMenu($menu);
        return true;
    }

    private function handleCreateCommand(string $chatId): bool
    {
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

    private function sendPhotoHint(string $chatId, string $state): void
    {
        try {
            $photoPath = null;

            if ($state === State::Expected_Plotter_Number->value) {
                $projectRoot = dirname(__DIR__, 3);
                $photoPath = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'plotter_number.jpg';
                if (!$photoPath || !file_exists($photoPath)) {
                    $this->maxBot->sendMessage((int)$chatId, "Подсказка временно недоступна. Пожалуйста, обратитесь к инструкции.");
                    return;
                }
                $this->maxBot->uploadAndSendPhoto((int)$chatId, $photoPath);
            }
        } catch (\Exception $e) {
            $this->maxBot->sendMessage((int)$chatId, "Не удалось отправить подсказку. Пожалуйста, обратитесь к инструкции.");
        }
    }
}