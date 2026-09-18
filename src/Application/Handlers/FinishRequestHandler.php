<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;

class FinishRequestHandler
{
    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        private MenuCreateAction $menuCreateAction,
        private MaxBotHelper $maxBot
    ) {}

    public function handle(string $text, string $chatId): bool
    {
        if ($text === State::Finish_Request->value) {
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Issue_AlReady_Exist,
                ResponseMessage::Are_You_Sure->value,
                self::KEYBOARD_TYPE_INLINE
            );
            $this->maxBot->sendMenu($menu);
            return true;
        }
        return false;
    }
}