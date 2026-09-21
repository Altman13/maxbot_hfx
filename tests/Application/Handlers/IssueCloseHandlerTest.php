<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\IssueCloseHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IssueCloseHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private OkDeskHelper $okdeskHelper;
    private IssueCloseHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);

        $this->handler = new IssueCloseHandler(
            $this->logger,
            $this->stateFileHandler,
            $this->menuCreateAction,
            $this->maxBot,
            $this->okdeskHelper
        );
    }

    public function testHandleManuallyCloseRequestReturnsFalseForOtherText(): void
    {
        $result = $this->handler->handleManuallyCloseRequest(
            'какой-то текст',
            State::Issue_AlReady_Exist->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleManuallyCloseRequestReturnsFalseForWrongState(): void
    {
        $result = $this->handler->handleManuallyCloseRequest(
            State::Yes->value,
            State::Main_Menu->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleManuallyCloseRequestHandlesNegativeResponse(): void
    {
        $menu = ['chat_id' => '123', 'text' => 'Stayed', 'reply_markup' => []];

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Request_Created->value);

        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleManuallyCloseRequest(
            State::No->value,
            State::Issue_AlReady_Exist->value,
            '123'
        );

        $this->assertTrue($result);
    }

    public function testHandleManuallyCloseRequestHandlesPositiveResponse(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->okdeskHelper
            ->shouldReceive('addCommentToIssue')
            ->once()
            ->with(789, ResponseMessage::User_Closed_Request->value, 456);

        $this->stateFileHandler
            ->shouldReceive('clearStateKey')
            ->with('123', 'issueId')
            ->once();

        foreach (['lastComment', 'attachmentFileName', 'file_links', 'last_file_batch_time', 'inventory_number'] as $field) {
            $this->stateFileHandler
                ->shouldReceive('clearStateKey')
                ->with('123', $field)
                ->once();
        }

        $this->okdeskHelper
            ->shouldReceive('fetchIssueDetails')
            ->with(789)
            ->andReturn(['id' => 789, 'created_at' => '2025-09-15']);

        $this->okdeskHelper
            ->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once(); // confirmation message

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'state', State::Finish_Request->value);

        $menu = ['chat_id' => '123', 'text' => 'Main', 'reply_markup' => []];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $result = $this->handler->handleManuallyCloseRequest(
            State::Yes->value,
            State::Issue_AlReady_Exist->value,
            '123'
        );

        $this->assertTrue($result);
    }

    public function testHandleManuallyCloseRequestSendsMessageWhenNoIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::No_Open_Request->value);

        $result = $this->handler->handleManuallyCloseRequest(
            State::Yes->value,
            State::Issue_AlReady_Exist->value,
            '123'
        );

        $this->assertFalse($result);
    }

    public function testHandleManuallyCloseRequestHandlesExceptionInCloseIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->okdeskHelper
            ->shouldReceive('addCommentToIssue')
            ->andThrow(new \Exception('OkDesk is down'));

        $result = $this->handler->handleManuallyCloseRequest(
            State::Yes->value,
            State::Issue_AlReady_Exist->value,
            '123'
        );

        $this->assertFalse($result);
    }
}