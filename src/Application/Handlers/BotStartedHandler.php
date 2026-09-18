<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Helpers\MaxBotHelper;
use App\Application\State\State;

class BotStartedHandler
{
    public function __construct(
        private StateFileHandler $stateFileHandler,
        private StateManagerHandler $stateManagerHandler,
        private MaxBotHelper $maxBot
    ) {}

    public function handle(array $message, string $chatId): void
    {
        if (($message["update_type"] ?? '') !== "bot_started") {
            return;
        }

        $chatId = (string)($message["chat_id"] ?? $chatId);
        $phoneNumber = $this->stateFileHandler->getStateField($chatId, 'phone_number');

        if ($phoneNumber !== '') {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Main_Menu->value);
            $this->stateManagerHandler->processState($chatId, State::Main_Menu);
            return;
        }

        $this->stateFileHandler->clearAllState($chatId);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Start->value);
        $this->maxBot->requestContact((int)$chatId);
    }
}