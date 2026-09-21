<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\FileAttachmentHandler;
use App\Application\Handlers\FileConverter;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileAttachmentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MenuCreateAction $menuCreateAction;
    private MaxBotHelper $maxBot;
    private OkDeskHelper $okdeskHelper;
    private FileConverter $fileConverter;
    private FileAttachmentHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->menuCreateAction = Mockery::mock(MenuCreateAction::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);
        $this->okdeskHelper = Mockery::mock(OkDeskHelper::class);
        $this->fileConverter = Mockery::mock(FileConverter::class);

        $this->handler = new FileAttachmentHandler(
            $this->logger,
            $this->stateFileHandler,
            $this->menuCreateAction,
            $this->maxBot,
            $this->okdeskHelper,
            $this->fileConverter
        );
    }

    // ============================================================
    // handleReturnToStart
    // ============================================================

    public function testHandleReturnToStartReturnsTrueForFileInReturnToStartState(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Select_From_Menu->value);

        $result = $this->handler->handleReturnToStart(
            '123',
            ['filePath' => 'https://example.com/file.jpg'],
            State::Return_To_Start->value
        );

        $this->assertTrue($result);
    }

    public function testHandleReturnToStartReturnsFalseForOtherState(): void
    {
        $result = $this->handler->handleReturnToStart(
            '123',
            ['filePath' => 'https://example.com/file.jpg'],
            State::Main_Menu->value
        );

        $this->assertFalse($result);
    }

    public function testHandleReturnToStartReturnsFalseWithoutFile(): void
    {
        $result = $this->handler->handleReturnToStart(
            '123',
            [],
            State::Return_To_Start->value
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // handleBackState
    // ============================================================

    public function testHandleBackStateReturnsTrueForFileInGoToBackState(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Select_From_Menu->value);

        $result = $this->handler->handleBackState(
            '123',
            ['filePath' => 'https://example.com/file.jpg'],
            State::GoToBack->value
        );

        $this->assertTrue($result);
    }

    public function testHandleBackStateReturnsFalseWithoutFile(): void
    {
        $result = $this->handler->handleBackState(
            '123',
            [],
            State::GoToBack->value
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // handleFileRestriction
    // ============================================================

    public function testHandleFileRestrictionReturnsTrueForFileInCreateCommand(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::ChooseFromMenu->value);

        $result = $this->handler->handleFileRestriction(
            ['filePath' => 'https://example.com/file.jpg'],
            State::Create_Command->value,
            '123'
        );

        $this->assertTrue($result);
    }

    public function testHandleFileRestrictionReturnsFalseForOtherState(): void
    {
        $result = $this->handler->handleFileRestriction(
            ['filePath' => 'https://example.com/file.jpg'],
            State::Main_Menu->value,
            '123'
        );

        $this->assertFalse($result);
    }

    // ============================================================
    // handleTechSupportFile
    // ============================================================

    public function testHandleTechSupportFileReturnsFalseForOtherState(): void
    {
        $result = $this->handler->handleTechSupportFile(
            'текст',
            '123',
            ['filePath' => 'https://example.com/file.jpg'],
            State::Main_Menu->value
        );

        $this->assertFalse($result);
    }

    public function testHandleTechSupportFileReturnsFalseWithoutFile(): void
    {
        $result = $this->handler->handleTechSupportFile(
            'текст',
            '123',
            [],
            State::Create_Request->value
        );

        $this->assertFalse($result);
    }

    public function testHandleTechSupportFileCreatesIssueAndAttachesFile(): void
    {
        // Стейт фикстура
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'companyId')
            ->andReturn('100');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('200');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'maintenance_entity_id')
            ->andReturn('300');

        // createIssueAndSendMessage — сценарий с пустым issueId
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('', '789', '789', '789'); // до и после создания

        $this->okdeskHelper
            ->shouldReceive('createIssue')
            ->once()
            ->andReturn(['id' => 789]);

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'issueId', 789)
            ->once();

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'state', State::Request_Created->value)
            ->once();

        $menu = ['chat_id' => '123', 'text' => 'Created', 'reply_markup' => []];
        $this->menuCreateAction
            ->shouldReceive('createMenuLogicWithKeyboardType')
            ->once()
            ->andReturn($menu);

        $this->maxBot->shouldReceive('sendMenu')->once()->with($menu);

        // handleFileAddition
        $this->okdeskHelper
            ->shouldReceive('handleFileAddition')
            ->once();

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'file_links', Mockery::type('array'))
            ->once();

        $result = $this->handler->handleTechSupportFile(
            'текст заявки',
            '123',
            ['filePath' => 'https://example.com/file.jpg', 'fileName' => 'file.jpg'],
            State::Create_Request->value
        );

        $this->assertTrue($result);
    }

    // ============================================================
    // addFileAttachmentsToIssue
    // ============================================================

    public function testAddFileAttachmentsToIssueReturnsFalseForEmptyFiles(): void
    {
        $result = $this->handler->addFileAttachmentsToIssue(
            'текст',
            '123',
            [],
            [],
            State::Request_Created->value,
            [State::Request_Created->value]
        );

        $this->assertFalse($result);
    }

    public function testAddFileAttachmentsToIssueReturnsFalseForWrongState(): void
    {
        $result = $this->handler->addFileAttachmentsToIssue(
            'текст',
            '123',
            ['files' => [['filePath' => 'https://example.com/f.jpg']]],
            [],
            State::Main_Menu->value,
            [State::Request_Created->value]
        );

        $this->assertFalse($result);
    }

    public function testAddFileAttachmentsToIssueReturnsFalseWhenNoIssueAndNoText(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('200');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('');

        $this->okdeskHelper->shouldNotReceive('handleFileAddition');

        $result = $this->handler->addFileAttachmentsToIssue(
            '',
            '123',
            ['files' => [['filePath' => 'https://example.com/f.jpg', 'fileName' => 'f.jpg']]],
            [],
            State::Request_Created->value,
            [State::Request_Created->value]
        );

        $this->assertFalse($result);
    }

    public function testAddFileAttachmentsToIssueAttachesSingleFile(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('200');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->okdeskHelper
            ->shouldReceive('handleFileAddition')
            ->once()
            ->with('текст', Mockery::type('array'), '789', '200');

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'file_links', Mockery::type('string'))
            ->once();

        $filesInfo = [
            'files' => [
                [
                    'filePath' => 'https://example.com/photo.jpg',
                    'fileName' => 'photo.jpg',
                    'fileId'   => 'photo_1',
                    'type'     => 'image',
                ],
            ],
        ];

        $result = $this->handler->addFileAttachmentsToIssue(
            'текст',
            '123',
            $filesInfo,
            [],
            State::Request_Created->value,
            [State::Request_Created->value]
        );

        $this->assertTrue($result);
    }

    public function testAddFileAttachmentsToIssueSkipsVideoFiles(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('200');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->okdeskHelper->shouldNotReceive('handleFileAddition');

        $filesInfo = [
            'files' => [
                [
                    'filePath' => 'https://example.com/video.mp4',
                    'fileName' => 'video.mp4',
                    'fileId'   => 'vid_1',
                    'type'     => 'video',
                ],
            ],
        ];

        $result = $this->handler->addFileAttachmentsToIssue(
            'текст',
            '123',
            $filesInfo,
            [],
            State::Request_Created->value,
            [State::Request_Created->value]
        );

        $this->assertFalse($result);
    }

    public function testAddFileAttachmentsToIssueHandlesBatchAttachments(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'contactId')
            ->andReturn('200');

        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'issueId')
            ->andReturn('789');

        $this->fileConverter
            ->shouldReceive('convert')
            ->andReturnUsing(function ($attachment, $index) {
                return [
                    'filePath' => 'https://example.com/' . $attachment['type'] . $index . '.jpg',
                    'fileType' => 'image/jpeg',
                    'fileName' => $attachment['type'] . $index . '.jpg',
                    'fileId'   => 'id_' . $index,
                    'type'     => $attachment['type'],
                ];
            });

        $this->okdeskHelper
            ->shouldReceive('handleFileAddition')
            ->twice();

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->with('123', 'file_links', Mockery::type('string'))
            ->once();

        $message = [
            'message' => [
                'body' => [
                    'attachments' => [
                        ['type' => 'image'],
                        ['type' => 'image'],
                    ],
                ],
            ],
        ];

        $filesInfo = ['files' => [['filePath' => 'x', 'type' => 'image']]];

        $result = $this->handler->addFileAttachmentsToIssue(
            'текст',
            '123',
            $filesInfo,
            $message,
            State::Request_Created->value,
            [State::Request_Created->value]
        );

        $this->assertTrue($result);
    }

    // ============================================================
    // handleFileLinks
    // ============================================================

    public function testHandleFileLinksAppendsDocumentId(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'file_links', ['doc_1']);

        $message = [
            'document' => ['file_id' => 'doc_1'],
        ];

        $this->handler->handleFileLinks($message, '123');
    }
}