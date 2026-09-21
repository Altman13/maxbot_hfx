<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\InitialRegistrationHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class InitialRegistrationHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private StateFileHandler $stateFileHandler;
    private InitialRegistrationHandler $handler;

    protected function setUp(): void
    {
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->handler = new InitialRegistrationHandler($this->stateFileHandler);
    }

    public function testHandleReturnsTrueAndSetsStartStateWhenCurrentStateIsEmpty(): void
    {
        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Start);

        $result = $this->handler->handle('', '123');

        $this->assertTrue($result);
    }

    public function testHandleReturnsFalseWhenCurrentStateIsNotEmpty(): void
    {
        $this->stateFileHandler
            ->shouldNotReceive('setStateField');

        $result = $this->handler->handle('Main_Menu', '123');

        $this->assertFalse($result);
    }

    public function testHandleReturnsFalseForAnyOtherState(): void
    {
        $this->stateFileHandler
            ->shouldNotReceive('setStateField');

        $result = $this->handler->handle('Expected_Plotter_Number', '456');

        $this->assertFalse($result);
    }
}