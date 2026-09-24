<?php

declare(strict_types=1);

namespace Tests\Application\Actions\OkDeskCallBack;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Actions\OkDeskCallBack\ListOkDeskCallBackAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ListOkDeskCallBackActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var LoggerInterface&MockInterface */
    private LoggerInterface $logger;

    /** @var MaxBotHelper&MockInterface */
    private MaxBotHelper $maxBot;

    /** @var MenuCreateAction&MockInterface */
    private MenuCreateAction $menuCreateAction;

    /** @var StateFileHandler&MockInterface */
    private StateFileHandler $stateFileHandler;

    /** @var OkDeskHelper&MockInterface */
    private OkDeskHelper $okdeskHelper;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);
    }

    // ============================================================
    // Вспомогательные
    // ============================================================

    private function makeAction(): ListOkDeskCallBackAction
    {
        return new ListOkDeskCallBackAction(
            $this->logger,
            $this->maxBot,
            $this->menuCreateAction,
            $this->stateFileHandler,
            $this->okdeskHelper
        );
    }

    /**
     * Собирает типичный webhook от OkDesk с параметром chatId.
     */
    private function webhook(array $override = []): array
    {
        $base = [
            'issue' => [
                'id' => 100,
                'parameters' => [
                    ['code' => 'chatId', 'value' => '123'],
                ],
            ],
            'event' => [],
        ];

        return array_replace_recursive($base, $override);
    }

    // ============================================================
    // Базовые проверки через sendMessage
    // ============================================================

    public function testReturnsEarlyWhenChatIdMissing(): void
    {
        // issue.parameters без chatId
        $data = [
            'issue' => ['id' => 100, 'parameters' => []],
            'event' => [],
        ];

        $this->maxBot->shouldNotReceive('sendMessage');

        $action = $this->makeAction();
        // sendMessage protected — вызываем через reflection
        $this->invokeSendMessage($action, $data);
    }

    public function testReturnsEarlyWhenIssueIdMismatch(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn(999); // текущая заявка

        $this->maxBot->shouldNotReceive('sendMessage');

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'issue' => ['id' => 100], // не совпадает с 999
        ]));
    }

    public function testReturnsEarlyWhenCommentIsNotPublic(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn(100);

        $this->maxBot->shouldNotReceive('sendMessage');

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'comment' => ['is_public' => false],
            ],
        ]));
    }

    public function testReturnsEarlyWhenAttachmentIsNotPublic(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn(100);

        $this->maxBot->shouldNotReceive('sendMessage');

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'attachments' => [['is_public' => false]],
            ],
        ]));
    }

    // ============================================================
    // Статус "В работе (ТП-1)"
    // ============================================================

    public function testSendsInProgressMessageOnInProgressStatus(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'lastComment')->andReturn('');

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'lastComment', Mockery::any());

        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::type('string'));

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'new_status' => ['name' => 'В работе (ТП-1)'],
                'author' => ['type' => 'employee'],
            ],
        ]));
    }

    // ============================================================
    // Статус "Решено"
    // ============================================================

    public function testSendsConfirmationMessageOnResolved(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        // responseMessage использует fetchIssueDetails
        $this->okdeskHelper->shouldReceive('fetchIssueDetails')
            ->with(100)
            ->andReturn(['id' => 100, 'created_at' => '2025-09-15T10:00:00']);

        $this->okdeskHelper->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::type('string'));

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'state', State::Finish_Request->value);

        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn(['chat_id' => '123', 'text' => 'Main']);

        $this->maxBot->shouldReceive('sendMenu')->once();

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', Mockery::any())->andReturnNull();

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'new_status' => ['name' => 'Решено'],
            ],
        ]));
    }

    // ============================================================
    // Статус "Рез отклонен"
    // ============================================================

    public function testSendsRejectionMessageOnRejected(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        $this->okdeskHelper->shouldReceive('fetchIssueDetails')
            ->with(100)
            ->andReturn(['id' => 100, 'created_at' => '2025-09-15T10:00:00']);

        $this->okdeskHelper->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::pattern('/отклонена/'));

        $this->stateFileHandler->shouldReceive('clearStateKey')
            ->with('123', Mockery::any())->andReturnNull();

        $this->menuCreateAction->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn(['chat_id' => '123', 'text' => 'Main']);

        $this->maxBot->shouldReceive('sendMenu')->once();

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'new_status' => ['name' => 'Рез отклонен'],
            ],
        ]));
    }

    // ============================================================
    // Возврат с паузы
    // ============================================================

    public function testSendsReturnToRequestMessageOnPauseResume(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'lastComment')->andReturn('');

        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'lastComment', Mockery::any());

        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::type('string'));

        $action = $this->makeAction();
        $this->invokeSendMessage($action, $this->webhook([
            'event' => [
                'old_status' => ['code' => 'In_progress_tp1_pause'],
                'new_status' => ['name' => 'В работе (ТП-1)'],
            ],
        ]));
    }

    // ============================================================
    // Reflection helper
    // ============================================================

    private function invokeSendMessage(
        ListOkDeskCallBackAction $action,
        array $data
    ): void {
        $ref = new \ReflectionClass($action);
        $method = $ref->getMethod('sendMessage');
        $method->setAccessible(true);
        $method->invoke($action, $data);
    }
}