<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\State\State;

class InitialRegistrationHandler
{
    public function __construct(
        private StateFileHandler $stateFileHandler
    ) {}

    public function handle(string $currentState, string $chatId): bool
    {
        if ($currentState === '') {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Start);
            return true;
        }
        return false;
    }
}