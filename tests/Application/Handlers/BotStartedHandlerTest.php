<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\BotStartedHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class BotStartedHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private StateFileHandler $stateFileHandler;
    private StateManagerHandler $stateManagerHandler;
    private MaxBotHelper $maxBot;
    private BotStartedHandler $handler;

    protected function setUp(): void
    {
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->stateManagerHandler = Mockery::mock(StateManagerHandler::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);

        $this->handler = new BotStartedHandler(
            $this->stateFileHandler,
            $this->stateManagerHandler,
            $this->maxBot
        );
    }

    public function testHandleDoesNothingWhenUpdateTypeIsNotBotStarted(): void
    {
        $this->stateFileHandler->shouldNotReceive('getStateField');
        $this->maxBot->shouldNotReceive('requestContact');

        $this->handler->handle(['update_type' => 'message_created'], '123');
    }

    public function testHandleGoesToMainMenuWhenPhoneNumberExists(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->once()
            ->with('123', 'phone_number')
            ->andReturn('+79991234567');

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Main_Menu->value);

        $this->stateManagerHandler
            ->shouldReceive('processState')
            ->once()
            ->with('123', State::Main_Menu);

        $this->maxBot->shouldNotReceive('requestContact');

        $this->handler->handle([
            'update_type' => 'bot_started',
            'chat_id'     => '123',
        ], '123');
    }

    public function testHandleClearsStateAndRequestsContactWhenNoPhone(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->once()
            ->with('123', 'phone_number')
            ->andReturn('');

        $this->stateFileHandler
            ->shouldReceive('clearAllState')
            ->once()
            ->with('123');

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Start->value);

        $this->maxBot
            ->shouldReceive('requestContact')
            ->once()
            ->with(123);

        $this->stateManagerHandler->shouldNotReceive('processState');

        $this->handler->handle([
            'update_type' => 'bot_started',
            'chat_id'     => '123',
        ], '123');
    }

    public function testHandleUsesChatIdFromMessageWhenProvided(): void
    {
        // В message['chat_id'] = 999, но аргумент = 123.
        // Хендлер должен использовать 999.
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->once()
            ->with('999', 'phone_number')
            ->andReturn('');

        $this->stateFileHandler->shouldReceive('clearAllState')->once()->with('999');
        $this->stateFileHandler->shouldReceive('setStateField')->once()->with('999', 'state', State::Start->value);
        $this->maxBot->shouldReceive('requestContact')->once()->with(999);

        $this->handler->handle([
            'update_type' => 'bot_started',
            'chat_id'     => '999',
        ], '123');
    }
}