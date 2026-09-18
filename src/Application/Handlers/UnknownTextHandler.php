<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;

class UnknownTextHandler
{
    public function __construct(
        private MaxBotHelper $maxBot
    ) {}

    public function handle(string $text, string $chatId): void
    {
        if ($text !== "" && !in_array($text, State::getAllValues(), true)) {
            $this->maxBot->sendMessage((int)$chatId, ResponseMessage::Select_From_Menu->value);
        }
    }
}