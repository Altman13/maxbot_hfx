<?php

declare(strict_types=1);

namespace Tests\Application\Actions\OkDeskCallBack;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Actions\OkDeskCallBack\ListOkDeskCallBackAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ListOkDeskCallBackActionFilesTest extends TestCase
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

    private ListOkDeskCallBackAction $action;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);

        $this->action = new ListOkDeskCallBackAction(
            $this->logger,
            $this->maxBot,
            $this->menuCreateAction,
            $this->stateFileHandler,
            $this->okdeskHelper
        );
    }

    // ============================================================
    // Простой текст (без вложений)
    // ============================================================

    public function testSendsPlainMessageFromEmployee(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        // Проверка на "Здравствуйте!..." — пропускаем
        // Проверка на is_public — пропускаем

        // Не "В работе (ТП-1)" и не "Решено" — идёт по ветке комментария
        $this->okdeskHelper->shouldReceive('fetchIssueDetails')
            ->with(100)->andReturn(['id' => 100, 'created_at' => '2025-09-15T10:00:00']);
        $this->okdeskHelper->shouldReceive('formatCreatedAt')->andReturn('15/09/2025');

        // processMessage сохраняет lastComment
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'lastComment')->andReturn('');
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'lastComment', 'Привет, это ответ от ТП');

        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, 'Привет, это ответ от ТП');

        // hasAttachments=false
        $data = [
            'issue' => [
                'id' => 100,
                'status' => ['name' => 'В работе (ТП1)'],
                'parameters' => [['code' => 'chatId', 'value' => '123']],
            ],
            'event' => [
                'author' => ['type' => 'employee'],
                'comment' => ['content' => 'Привет, это ответ от ТП'],
            ],
        ];

        $this->invokeSendMessage($data);
    }

    // ============================================================
    // Вложение: один файл
    // ============================================================

    public function testProcessesSingleAttachment(): void
    {
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'issueId')->andReturn(100);

        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'attachmentProcessCounter')->andReturn(0);

        $this->okdeskHelper->shouldReceive('fetchIssueDetails')
            ->with(100)->andReturn(['id' => 100, 'created_at' => '2025-09-15T10:00:00']);
        $this->okdeskHelper->shouldReceive('formatCreatedAt')->andReturn('15/09/2025');

        // Ключевое: URL от OkDesk НЕ получен — но это не должно ронять тест.
        // Метод sendMaxFile всё равно попытается обработать, но упадёт в свою же
        // ветку catch и отправит сообщение об ошибке через maxBot->sendMessage.
        $this->okdeskHelper->shouldReceive('getOkdeskAttachmentUrl')
            ->andReturn(null);

        // Сохранили имя последнего файла — чтобы в следующий раз не дублировать
        $this->stateFileHandler->shouldReceive('getStateField')
            ->with('123', 'lastAttachmentFileName')->andReturn('');
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'lastAttachmentFileName', 'file.pdf');

        // Сброс счётчика
        $this->stateFileHandler->shouldReceive('setStateField')
            ->with('123', 'attachmentProcessCounter', 0);

        // Раз URL не получен — метод должен отправить сообщение об ошибке в чат.
        // Именно это и проверяем.
        $this->maxBot->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::pattern('/Не удалось получить доступ к файлу/'))
            ->andReturn([]);

        $data = [
            'issue' => [
                'id' => 100,
                'status' => ['name' => 'В работе (ТП1)'],
                'parameters' => [['code' => 'chatId', 'value' => '123']],
            ],
            'event' => [
                'author' => ['type' => 'employee'],
                'attachments' => [
                    ['id' => 555, 'attachment_file_name' => 'file.pdf'],
                ],
            ],
        ];

        $this->invokeSendMessage($data);

        // Явное assertion — чтобы PHPUnit не ругался на risky
        $this->addToAssertionCount(1);
    }

    // ============================================================
    // Reflection helper
    // ============================================================

    private function invokeSendMessage(array $data): void
    {
        $ref = new \ReflectionClass($this->action);
        $m = $ref->getMethod('sendMessage');
        $m->setAccessible(true);
        $m->invoke($this->action, $data);
    }
}
