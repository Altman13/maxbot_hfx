<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\State\State;
use App\Application\Handlers\StateFileHandler;
use Psr\Log\LoggerInterface;
use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\Helpers\MaxBotHelper;
class StateManagerHandler
{
    private StateFileHandler $stateFileHandler;
    private LoggerInterface $logger;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBotHelper;

    public function __construct(
        StateFileHandler $stateFileHandler,
        LoggerInterface $logger,
        MenuCreateAction $menuCreateAction,
        MaxBotHelper $maxBotHelper,
    ) {
        $this->stateFileHandler = $stateFileHandler;
        $this->logger = $logger;
        $this->menuCreateAction = $menuCreateAction;
        $this->maxBotHelper = $maxBotHelper;
        $this->logFunctionName();
    }

    public function processState(string $chatId, State $state,  string $keyboardType = 'inline')
    {
        $this->logFunctionName();

        $responseMessage = $this->getResponseMessage($state->value);

        if ($responseMessage !== ResponseMessage::ResponseNotRequired->value) {
            $menu = $this->menuCreateAction->createMenuLogic($chatId, $state, $responseMessage);
            $this->maxBotHelper->sendMenu($menu);
        }
    }

    public function isValidStateTransition(string $currentState, array $validStates): bool
    {
        $this->logFunctionName();
        return in_array($currentState, $validStates);
    }

    public function isValidStringData($data): bool
    {
        $this->logFunctionName();
        return is_string($data);
    }

    public function isValidArrayData($data): bool
    {
        $this->logFunctionName();
        return is_array($data) && !empty($data);
    }

    public function getResponseMessage(string $stateValue, string $chatId = ''): string
    {
        $this->logFunctionName();

        $responseMessages = [
            // Регистрация и авторизация
            State::Start->value => ResponseMessage::Request_Contact_Share->value,

            // Заявки и запросы
           // State::Request_Created->value => ResponseMessage::ResponseNotRequired->value,

            // Поддержка и обратная связь
            State::Finish_Request->value => ResponseMessage::Finish_Request->value,

            // Общий функционал и меню
            State::Main_Menu->value => ResponseMessage::Main_Menu->value,
            State::Stay->value => ResponseMessage::Main_Menu->value,
            State::Return_To_Start->value => ResponseMessage::Main_Menu->value,
            State::Cut_By_QR_Code->value => ResponseMessage::QR_Code->value,

            //---------------НОВЫЕ ПЕРЕХОДЫ ПО КНОПКАМ HFX
            State::Create_Request->value => ResponseMessage::Instruction_Notification->value,
            //---------------------------------------------
        ];


        return $responseMessages[$stateValue] ?? ResponseMessage::Select_From_Menu->value;
    }


    protected function sendMessage(string $chatId, string $message): void
    {
        $this->logFunctionName();
        if (is_array($message)) {
            $message = implode("\n", $message);
        }

        $this->maxBotHelper->sendMessage((int)$chatId, $message);
    }

    public function processStateFromText(string $chatId, string $text): void
    {
        $this->logFunctionName();
        foreach (State::cases() as $state) {
            if ($state->value === $text) {
                $this->processState($chatId, $state);
                return;
            }
        }

        $this->sendMessage($chatId, $this->getResponseMessage($this->stateFileHandler->getStateFromFile($chatId)['state'] ?? ''));
    }

    private function logFunctionName(): void
    {
        if ($_ENV['DEBUG_ENV']) {
            $backtrace = debug_backtrace();
            $functionName = $backtrace[1]['function'];
            $this->logger->info("Function $functionName was executed.");
        }
    }
}
