<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\CommandHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CommandHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private StateManagerHandler $stateManagerHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private OkDeskHelper $okdeskHelper;
    private CommandHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->stateManagerHandler = Mockery::mock(StateManagerHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);

        $this->handler = new CommandHandler(
            $this->logger,
            $this->stateFileHandler,
            $this->stateManagerHandler,
            $this->menuCreateAction,
            $this->maxBot,
            $this->okdeskHelper
        );
    }

    // ============================================================
    // handleStartAndDelete
    // ============================================================

    public function testHandleStartAndDeleteResetsStateOnDelete(): void
    {
        $this->stateFileHandler
            ->shouldReceive('clearAllState')
            ->once()
            ->with('123');

        $this->maxBot
            ->shouldReceive('requestContact')
            ->once()
            ->with(123);

        $result = $this->handler->handleStartAndDelete(State::Delete_Command->value, '123');

        $this->assertTrue($result);
    }

    public function testHandleStartAndDeleteSendsMainMenuForAuthorizedUser(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'phone_number')
            ->andReturn('+79991234567');

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Main_Command->value);

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleStartAndDelete(State::Start_Command->value, '123');

        $this->assertTrue($result);
    }

    public function testHandleStartAndDeleteResetsForUnauthorizedUserWithoutIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'phone_number')
            ->andReturn('');

        $this->stateFileHandler
            ->shouldReceive('clearAllState')
            ->once()
            ->with('123');

        $this->maxBot->shouldReceive('requestContact')->once()->with(123);

        $result = $this->handler->handleStartAndDelete(State::Start_Command->value, '123');

        $this->assertTrue($result);
    }

    public function testHandleStartAndDeleteReturnsFalseForOtherText(): void
    {
        $result = $this->handler->handleStartAndDelete('какой-то текст', '123');

        $this->assertFalse($result);
    }

    // ============================================================
    // handleCommandIfRequestWithoutAuth
    // ============================================================

    public function testHandleCommandIfRequestWithoutAuthReturnsFalseForReturnToStart(): void
    {
        $result = $this->handler->handleCommandIfRequestWithoutAuth(
            State::Return_To_Start->value,
            State::Create_Request_Without_Auth->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleCommandIfRequestWithoutAuthReturnsFalseForWrongState(): void
    {
        $result = $this->handler->handleCommandIfRequestWithoutAuth(
            State::Main_Command->value,
            State::Main_Menu->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleCommandIfRequestWithoutAuthSendsMainMenu(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleCommandIfRequestWithoutAuth(
            State::Main_Command->value,
            State::Create_Request_Without_Auth->value,
            '123'
        );

        $this->assertTrue($result);
    }

    // ============================================================
    // handleCommandIfRequestExist
    // ============================================================

    public function testHandleCommandIfRequestExistReturnsFalseWithoutIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $result = $this->handler->handleCommandIfRequestExist(
            State::Create_Command->value,
            State::Main_Menu->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleCommandIfRequestExistSendsWarningForCreateCommand(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Уже есть заявка', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->okdeskHelper
            ->shouldReceive('fetchIssueDetails')
            ->with(789)
            ->andReturn(['id' => 789, 'created_at' => '2025-09-15']);

        $this->okdeskHelper
            ->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleCommandIfRequestExist(
            State::Create_Command->value,
            State::Main_Menu->value,
            '123'
        );

        $this->assertTrue($result);
    }

    public function testHandleCommandIfRequestExistSendsMainMenuForMainCommand(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')->once()->andReturn($menu);
        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleCommandIfRequestExist(
            State::Main_Command->value,
            State::Main_Menu->value,
            '123'
        );

        $this->assertTrue($result);
    }

    public function testHandleCommandIfRequestExistReturnsFalseForUnknownText(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $result = $this->handler->handleCommandIfRequestExist(
            'какой-то текст',
            State::Main_Menu->value,
            '123'
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // handleCommands
    // ============================================================

    public function testHandleCommandsReturnsFalseForNonCommand(): void
    {
        $result = $this->handler->handleCommands('обычный текст', '123');

        $this->assertFalse($result);
    }

    public function testHandleCommandsClearsStateOnDelete(): void
    {
        $this->stateFileHandler
            ->shouldReceive('clearAllState')
            ->once()
            ->with('123');

        $this->stateManagerHandler
            ->shouldReceive('processState')
            ->once()
            ->with('123', State::Share_Contact);

        $result = $this->handler->handleCommands(State::Delete_Command->value, '123');

        $this->assertTrue($result);
    }

    public function testHandleCommandsSendsExpectedPlotterNumberOnCreate(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Instruction', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Main_Menu->value);

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleCommands(State::Create_Command->value, '123');

        $this->assertTrue($result);
    }

    // ============================================================
    // handleUnknownTextIfRequestExist
    // ============================================================

    public function testHandleUnknownTextIfRequestExistReturnsFalseWithoutIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $result = $this->handler->handleUnknownTextIfRequestExist('какой-то текст', '123');

        $this->assertFalse($result);
    }

    public function testHandleUnknownTextIfRequestExistReturnsFalseForEmptyText(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $result = $this->handler->handleUnknownTextIfRequestExist('', '123');

        $this->assertFalse($result);
    }

    public function testHandleUnknownTextIfRequestExistReturnsFalseForYesNoStay(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        // Yes, No, Stay — не считаются "неизвестным текстом"
        $this->assertFalse($this->handler->handleUnknownTextIfRequestExist(State::Yes->value, '123'));
        $this->assertFalse($this->handler->handleUnknownTextIfRequestExist(State::No->value, '123'));
        $this->assertFalse($this->handler->handleUnknownTextIfRequestExist(State::Stay->value, '123'));
    }

    public function testHandleUnknownTextIfRequestExistSendsNotificationForUnknownText(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Notification', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'issueId', '789')
            ->once();

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'state', State::Request_Created->value)
            ->once();

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleUnknownTextIfRequestExist('неизвестный текст', '123');

        $this->assertTrue($result);
    }
}