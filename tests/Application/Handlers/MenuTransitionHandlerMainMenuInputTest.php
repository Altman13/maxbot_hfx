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

class MenuTransitionHandlerMainMenuInputTest extends TestCase
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

    public function testHandleMainMenuInputWithIssueExists(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $menu = ['chat_id' => '123', 'text' => 'Stayed'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('handleMainMenuInput', [
            'какой-то текст',
            '123',
            State::Main_Menu->value,
            [],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testHandleMainMenuInputWithContentInitiatesNewRequest(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $menu = ['chat_id' => '123', 'text' => 'Instruction'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->invokePrivate('handleMainMenuInput', [
            'какой-то текст',
            '123',
            State::Main_Menu->value,
            [],
            [],
        ]);

        $this->assertTrue($result);
    }

    public function testHandleMainMenuInputReturnsFalseForCommand(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $result = $this->invokePrivate('handleMainMenuInput', [
            State::Create_Command->value,
            '123',
            State::Main_Menu->value,
            [],
            [],
        ]);

        $this->assertFalse($result);
    }

    public function testHandleMainMenuInputReturnsFalseForOtherState(): void
    {
        $result = $this->invokePrivate('handleMainMenuInput', [
            'какой-то текст',
            '123',
            State::Expected_Plotter_Number->value,
            [],
            [],
        ]);

        $this->assertFalse($result);
    }

    private function invokePrivate(string $method, array $args)
    {
        $ref = new \ReflectionClass($this->handler);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->handler, ...$args);
    }
}