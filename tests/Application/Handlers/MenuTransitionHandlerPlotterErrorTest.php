<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers\MenuTransitionHandler;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\FileConverter;
use App\Application\Handlers\MenuTransitionHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MenuTransitionHandlerPlotterErrorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMMAND_STATES = [
        State::Main_Command->value,
        State::Create_Command->value,
        State::Close_Command->value,
        State::Delete_Command->value,
    ];

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private OkDeskHelper $okdeskHelper;
    private FileConverter $fileConverter;
    private MenuTransitionHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);
        $this->fileConverter = Mockery::mock(FileConverter::class);

        $this->maxBot->shouldReceive('sendMessage')->andReturn([])->byDefault();
        $this->maxBot->shouldReceive('sendMenu')->andReturn([])->byDefault();
        $this->maxBot->shouldReceive('uploadAndSendPhoto')->andReturn([])->byDefault();

        $this->handler = new MenuTransitionHandler(
            $this->logger,
            $this->stateFileHandler,
            $this->menuCreateAction,
            $this->maxBot,
            $this->okdeskHelper,
            $this->fileConverter,
            self::COMMAND_STATES
        );
    }

    public function testHandlePlotterNotFound(): void
    {
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'inventory_number', '12345')
            ->once();

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'plotter_not_found', 'true')
            ->once();

        $menu = ['chat_id' => '123', 'text' => 'Not found'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('handlePlotterNotFound', ['12345', '123']);

        $this->assertTrue($result);
    }

    public function testHandlePlotterActivationRequired(): void
    {
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'inventory_number', '12345')
            ->once();

        $menu = ['chat_id' => '123', 'text' => 'Activation'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('handlePlotterActivationRequired', [
            '12345',
            '123',
            '12345',
        ]);

        $this->assertTrue($result);
    }

    public function testFinalizeRequestCreationInPlotterManualInput(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'plotter_not_found')
            ->andReturn('');

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'issueId', '789')
            ->once();

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'state', State::Request_Created->value)
            ->once();

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'plotter_not_found')
            ->once();

        $menu = ['chat_id' => '123', 'text' => 'Created'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('finalizeRequestCreationInPlotterManualInput', [
            '123',
            '789',
        ]);

        $this->assertTrue($result);
    }

    private function invokePrivate(string $method, array $args)
    {
        $ref = new \ReflectionClass($this->handler);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->handler, ...$args);
    }
}