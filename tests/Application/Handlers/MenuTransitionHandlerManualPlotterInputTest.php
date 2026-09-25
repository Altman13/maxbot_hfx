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

class MenuTransitionHandlerManualPlotterInputTest extends TestCase
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

    public function testHandleTextCaseWhenPlotterNotFound(): void
    {
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'plotter_not_found', 'true')
            ->once();

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'file_links')
            ->once();

        $this->okdeskHelper->shouldReceive('getEquipmentIdByInventoryNumber')
            ->with('12345')
            ->andReturn(null);

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'inventory_number', '12345')
            ->once();

        $menu = ['chat_id' => '123', 'text' => 'Not found'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('handleTextCaseInManualPlotterInput', [
            '12345',
            '123',
            [],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testHandleTextCaseTooLong(): void
    {
        $longText = str_repeat('a', 100);

        $result = $this->invokePrivate('handleTextCaseInManualPlotterInput', [
            $longText,
            '123',
            [],
            [],
        ]);

        $this->assertFalse($result);
    }

    public function testHandleTextCaseWithFiles(): void
    {
        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'file_links')
            ->once();

        $result = $this->invokePrivate('handleTextCaseInManualPlotterInput', [
            '12345',
            '123',
            ['filePath' => 'https://example.com/file.jpg'],
            [],
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