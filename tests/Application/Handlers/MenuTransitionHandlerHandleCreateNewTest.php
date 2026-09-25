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
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MenuTransitionHandlerHandleCreateNewTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMMAND_STATES = [
        State::Main_Command->value,
        State::Create_Command->value,
        State::Close_Command->value,
        State::Delete_Command->value,
    ];

    /** @var LoggerInterface&MockInterface */
    private LoggerInterface $logger;

    /** @var StateFileHandler&MockInterface */
    private StateFileHandler $stateFileHandler;

    /** @var MenuCreateAction&MockInterface */
    private MenuCreateAction $menuCreateAction;

    /** @var MaxBotHelper&MockInterface */
    private MaxBotHelper $maxBot;

    /** @var OkDeskHelper&MockInterface */
    private OkDeskHelper $okdeskHelper;

    /** @var FileConverter&MockInterface */
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
        $this->maxBot->shouldReceive('uploadAndSendPhoto')->andReturn([])->byDefault();
        $this->maxBot->shouldReceive('sendMenu')->andReturn([])->byDefault();

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

    // ============================================================
    // handleCreateNew — с файлом в состоянии Create_New
    // ============================================================

    public function testHandleCreateNewWithFileInCreateNewState(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn(State::Create_New->value);

        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'last_file_batch_time')
            ->andReturn(0);

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'last_file_batch_time', Mockery::type('int'));

        $menu = ['chat_id' => '123', 'text' => 'Stayed'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->handler->handleCreateNew(
            '',
            ['filePath' => 'https://example.com/file.jpg'],
            '123'
        );

        $this->assertTrue($result);
    }

    // ============================================================
    // handleCreateNew — текст в состоянии Create_New
    // ============================================================

    public function testHandleCreateNewWithTextInCreateNewState(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn(State::Create_New->value);

        $menu = ['chat_id' => '123', 'text' => 'Stayed'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $result = $this->handler->handleCreateNew(
            'какой-то текст',
            [],
            '123'
        );

        $this->assertTrue($result);
    }

    // ============================================================
    // handleCreateNew — не Create_New и не Create_New команда
    // ============================================================

    public function testHandleCreateNewReturnsFalseForUnrelatedText(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn(State::Main_Menu->value);

        $result = $this->handler->handleCreateNew(
            'обычный текст',
            [],
            '123'
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // handleCreateNew — команда Create_New
    // ============================================================

    public function testHandleCreateNewCompletesIssueAndResetsState(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'state')
            ->andReturn(State::Main_Menu->value);

        // processIssueCompletion
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn(789);

        $this->okdeskHelper->shouldReceive('fetchIssueDetails')
            ->with(789)
            ->andReturn(['id' => 789, 'created_at' => '2025-09-15']);

        $this->okdeskHelper->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn(456);

        $this->okdeskHelper->shouldReceive('addCommentToIssue')
            ->once()
            ->with(789, ResponseMessage::User_Closed_Request->value, 456);

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'issueId')
            ->once();

        // sendCompletionMessages
        $this->maxBot->shouldReceive('sendMessage')->once();

        // sendPhotoHint — уже byDefault

        $menu = ['chat_id' => '123', 'text' => 'Instruction'];
        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        // setStateField для состояния
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'state', State::Expected_Plotter_Number->value)
            ->once();

        // clearStateKey для file_links и lastComment
        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'file_links')
            ->once();

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', 'lastComment')
            ->once();

        $result = $this->handler->handleCreateNew(
            State::Create_New->value,
            [],
            '123'
        );

        $this->assertTrue($result);
    }
}