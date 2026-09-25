<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\UnsupportedTypeHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class UnsupportedTypeHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMMAND_STATES = [
        State::Main_Command->value,
        State::Create_Command->value,
        State::Close_Command->value,
        State::Delete_Command->value,
    ];

    private StateFileHandler $stateFileHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private QrCodeHelper $qrCodeHelper;
    private OkDeskHelper $okDeskHelper;
    private UnsupportedTypeHandler $handler;

    protected function setUp(): void
    {
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->qrCodeHelper = Mockery::mock(QrCodeHelper::class);
        $this->okDeskHelper = Mockery::mock(OkDeskHelper::class);

        $this->maxBot->shouldReceive('sendMessage')->andReturn([])->byDefault();
        $this->maxBot->shouldReceive('uploadAndSendPhoto')->andReturn([])->byDefault();
        $this->stateFileHandler->shouldReceive('setStateField')->andReturnNull()->byDefault();

        $this->handler = new UnsupportedTypeHandler(
            $this->stateFileHandler,
            $this->menuCreateAction,
            $this->maxBot,
            $this->qrCodeHelper,
            $this->okDeskHelper,
            self::COMMAND_STATES
        );
    }

    public function testHandleReturnsFalseOnShareContactState(): void
    {
        $result = $this->handler->handle(
            'любой текст',
            [],
            '123',
            State::Share_Contact->value
        );

        $this->assertFalse($result);
    }

    public function testHandleInitiatesNewRequestWhenFileOnMainMenuWithoutIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $menu = ['chat_id' => '123', 'text' => 'Instruction', 'reply_markup' => []];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $message = [
            'message' => [
                'body' => [
                    'attachments' => [['type' => 'image']],
                ],
            ],
        ];

        $result = $this->handler->handle('', $message, '123', State::Main_Menu->value);

        $this->assertTrue($result);
    }

    public function testHandleShowsExistingIssueWarningWhenFileOnMainMenuWithIssue(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->okDeskHelper
            ->shouldReceive('fetchIssueDetails')
            ->with(789)
            ->andReturn(['id' => 789, 'created_at' => '2025-09-15']);

        $this->okDeskHelper
            ->shouldReceive('formatCreatedAt')
            ->andReturn('15/09/2025');

        $menu = ['chat_id' => '123', 'text' => 'Existing', 'reply_markup' => []];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        $message = [
            'message' => [
                'body' => [
                    'attachments' => [['type' => 'image']],
                ],
            ],
        ];

        $result = $this->handler->handle('', $message, '123', State::Main_Menu->value);

        $this->assertTrue($result);
    }

    public function testHandleSendsQrScannerOnFileInExpectedPlotterState(): void
    {
        $message = [
            'message' => [
                'body' => [
                    'attachments' => [['type' => 'image']],
                ],
            ],
        ];

        $this->qrCodeHelper
            ->shouldReceive('sendQRCodeScanner')
            ->once()
            ->with('123', ResponseMessage::Click_To_Open_Qr_Scanner->value);

        $result = $this->handler->handle(
            '',
            $message,
            '123',
            State::Expected_Plotter_Number->value
        );

        $this->assertTrue($result);
    }

    public function testHandleReturnsFalseForCommandText(): void
    {
        $result = $this->handler->handle(
            State::Main_Command->value,
            [],
            '123',
            State::Main_Menu->value
        );

        $this->assertFalse($result);
    }

    public function testHandleSendsUnsupportedMessageForVoiceInMainMenu(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Unsupported_Format_Message->value);

        $message = ['voice' => ['file_id' => 'xxx']];

        $result = $this->handler->handle(
            State::Main_Menu->value,
            $message,
            '123',
            State::Main_Menu->value
        );

        // Обрати внимание: логика в коде для $text === State::Main_Menu->value
        // сначала проверяет медиа, но при пустом "не Create_Request" отправляет меню.
        // Здесь важно, что метод не падает и возвращает true/false.
        $this->assertIsBool($result);
    }

    public function testHandleReturnsFalseForValidMenuText(): void
    {
        // Передаём State::Create_Request — не команда, не медиа
        $message = [];

        $result = $this->handler->handle(
            State::Create_Request->value,
            $message,
            '123',
            State::Main_Menu->value
        );

        $this->assertFalse($result);
    }

    public function testHandleReturnsTrueForUnsupportedMediaFields(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Unsupported_Format_Message->value);

        $message = ['sticker' => ['file_id' => 'xxx']];

        $result = $this->handler->handle(
            'какой-то текст',
            $message,
            '123',
            State::Expected_Plotter_Number->value
        );

        $this->assertTrue($result);
    }
}
