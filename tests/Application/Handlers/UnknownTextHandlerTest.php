<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\UnknownTextHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class UnknownTextHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MaxBotHelper $maxBot;
    private UnknownTextHandler $handler;

    protected function setUp(): void
    {
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->handler = new UnknownTextHandler($this->maxBot);
    }

    public function testHandleSendsMessageForUnknownText(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Select_From_Menu->value);

        $this->handler->handle('какой-то непонятный текст', '123');
    }

    public function testHandleDoesNothingForEmptyText(): void
    {
        $this->maxBot->shouldNotReceive('sendMessage');

        $this->handler->handle('', '123');
    }

    public function testHandleDoesNothingForKnownStateValue(): void
    {
        $this->maxBot->shouldNotReceive('sendMessage');

        // любой валидный стейт — не должен триггерить отправку
        $this->handler->handle(State::Main_Menu->value, '123');
    }
}