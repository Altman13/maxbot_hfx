<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\CommentHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\OkDeskHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CommentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private OkDeskHelper $okDeskHelper;
    private StateFileHandler $stateFileHandler;
    private CommentHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->okDeskHelper = Mockery::mock(OkDeskHelper::class);
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);

        $this->handler = new CommentHandler(
            $this->logger,
            $this->okDeskHelper,
            $this->stateFileHandler
        );
    }

    public function testHandleCommentReturnsFalseForEmptyText(): void
    {
        $this->okDeskHelper->shouldNotReceive('addCommentToIssue');

        $result = $this->handler->handleComment(
            '',
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            []
        );

        $this->assertFalse($result);
    }

    public function testHandleCommentReturnsFalseWhenStateNotInValidList(): void
    {
        $this->okDeskHelper->shouldNotReceive('addCommentToIssue');

        $result = $this->handler->handleComment(
            'Текст комментария',
            '123',
            State::Main_Menu->value,
            [State::Request_Created->value],
            []
        );

        $this->assertFalse($result);
    }

    public function testHandleCommentReturnsFalseWhenTextInInvalidList(): void
    {
        $this->okDeskHelper->shouldNotReceive('addCommentToIssue');

        $result = $this->handler->handleComment(
            State::Finish_Request->value,
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            [State::Finish_Request->value]
        );

        $this->assertFalse($result);
    }

    public function testHandleCommentReturnsFalseWhenTextMatchesOtherState(): void
    {
        $this->okDeskHelper->shouldNotReceive('addCommentToIssue');

        // Текст совпадает со стейтом (кроме Yes/No) — не комментарий
        $result = $this->handler->handleComment(
            State::Main_Menu->value,
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            []
        );

        $this->assertFalse($result);
    }

    public function testHandleCommentAddsCommentForValidCase(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->okDeskHelper
            ->shouldReceive('addCommentToIssue')
            ->once()
            ->with(789, 'Хороший комментарий', 456);

        $result = $this->handler->handleComment(
            'Хороший комментарий',
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            []
        );

        $this->assertTrue($result);
    }

    public function testHandleCommentAllowsYesAsComment(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        // Yes/No разрешены как комментарий (исключение в цикле)
        $this->okDeskHelper
            ->shouldReceive('addCommentToIssue')
            ->once()
            ->with(789, State::Yes->value, 456);

        $result = $this->handler->handleComment(
            State::Yes->value,
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            []
        );

        $this->assertTrue($result);
    }

    public function testHandleCommentDoesNotCallOkDeskWhenNoIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $this->okDeskHelper->shouldNotReceive('addCommentToIssue');

        $result = $this->handler->handleComment(
            'Комментарий без заявки',
            '123',
            State::Request_Created->value,
            [State::Request_Created->value],
            []
        );

        $this->assertTrue($result); // всё равно true — логика «текст обработан»
    }

    // ============================================================
    // addComment (legacy API)
    // ============================================================

    public function testAddCommentReturnsFalseWhenNoIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $result = $this->handler->addComment('123', 'текст');

        $this->assertFalse($result);
    }

    public function testAddCommentReturnsTrueWhenOkDeskSucceeds(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('456');

        $this->okDeskHelper
            ->shouldReceive('addCommentToIssue')
            ->once()
            ->with(789, 'текст', 456)
            ->andReturn(true);

        $result = $this->handler->addComment('123', 'текст');

        $this->assertTrue($result);
    }
}