<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\FileHandler;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\ResponseMessage\ResponseMessage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LoggerInterface $logger;
    private StateFileHandler $stateFileHandler;
    private MaxBotHelper $maxBot;
    private FileHandler $handler;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $this->stateFileHandler = Mockery::mock(StateFileHandler::class);
        $this->maxBot = Mockery::mock(MaxBotHelper::class);

        $this->handler = new FileHandler(
            $this->logger,
            $this->stateFileHandler,
            $this->maxBot
        );
    }

    // ============================================================
    // processFileData
    // ============================================================

    public function testProcessFileDataReturnsEmptyWhenNoMessage(): void
    {
        $result = $this->handler->processFileData([]);

        $this->assertSame(['files' => [], 'links' => []], $result);
    }

    public function testProcessFileDataReturnsEmptyForMessageWithoutAttachments(): void
    {
        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => ['text' => 'просто текст'],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertSame([], $result);
    }

    public function testProcessFileDataConvertsSingleImage(): void
    {
        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => [
                    'attachments' => [
                        [
                            'type'    => 'image',
                            'payload' => [
                                'photo_id' => 'photo_1',
                                'url'      => 'https://example.com/photo.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('links', $result);
        $this->assertCount(1, $result['files']);
        $this->assertSame('photo_1.jpg', $result['files'][0]['fileName']);
        $this->assertSame('https://example.com/photo.jpg', $result['links'][0]);
    }

    public function testProcessFileDataFiltersOutVideo(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, Mockery::pattern('/Видеофайлы не поддерживаются/'));

        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => [
                    'attachments' => [
                        ['type' => 'video', 'payload' => ['url' => 'https://example.com/video.mp4']],
                    ],
                ],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertSame([], $result);
    }

    public function testProcessFileDataHandlesMultipleAttachments(): void
    {
        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => [
                    'attachments' => [
                        [
                            'type'    => 'image',
                            'payload' => ['photo_id' => 'p1', 'url' => 'https://example.com/p1.jpg'],
                        ],
                        [
                            'type'    => 'document',
                            'payload' => [
                                'file_id'   => 'f1',
                                'mime_type' => 'application/pdf',
                                'url'       => 'https://example.com/doc.pdf',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertCount(2, $result['files']);
        $this->assertCount(2, $result['links']);
    }

    public function testProcessFileDataHandlesForwardedAttachments(): void
    {
        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => ['text' => ''],
                'link'      => [
                    'message' => [
                        'attachments' => [
                            [
                                'type'    => 'image',
                                'payload' => [
                                    'photo_id' => 'forwarded_photo',
                                    'url'      => 'https://example.com/forwarded.jpg',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertCount(1, $result['files']);
        $this->assertSame('forwarded_photo.jpg', $result['files'][0]['fileName']);
    }

    public function testProcessFileDataSendsMessageWhenFileTooLarge(): void
    {
        $this->maxBot
            ->shouldReceive('sendMessage')
            ->once()
            ->with(123, ResponseMessage::Max_File_Size->value);

        $update = [
            'message' => [
                'recipient' => ['chat_id' => 123],
                'body'      => [
                    'attachments' => [
                        [
                            'type'    => 'file',
                            'payload' => [
                                'file_id' => 'big_file',
                                'size'    => 30 * 1024 * 1024, // 30 MB
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->processFileData($update);

        $this->assertSame([], $result);
    }

    // ============================================================
    // isFileTooLarge
    // ============================================================

    public function testIsFileTooLargeReturnsTrueForBigFile(): void
    {
        $message = [
            'body' => [
                'attachments' => [
                    ['payload' => ['size' => 25 * 1024 * 1024]],
                ],
            ],
        ];

        $this->assertTrue($this->handler->isFileTooLarge($message));
    }

    public function testIsFileTooLargeReturnsFalseForSmallFile(): void
    {
        $message = [
            'body' => [
                'attachments' => [
                    ['payload' => ['size' => 5 * 1024 * 1024]],
                ],
            ],
        ];

        $this->assertFalse($this->handler->isFileTooLarge($message));
    }

    public function testIsFileTooLargeHandlesFileSizeKey(): void
    {
        $message = [
            'body' => [
                'attachments' => [
                    ['payload' => ['fileSize' => 30 * 1024 * 1024]],
                ],
            ],
        ];

        $this->assertTrue($this->handler->isFileTooLarge($message));
    }

    public function testIsFileTooLargeChecksForwardedMessages(): void
    {
        $message = [
            'link' => [
                'message' => [
                    'attachments' => [
                        ['payload' => ['size' => 30 * 1024 * 1024]],
                    ],
                ],
            ],
        ];

        $this->assertTrue($this->handler->isFileTooLarge($message));
    }

    public function testIsFileTooLargeReturnsFalseForEmptyMessage(): void
    {
        $this->assertFalse($this->handler->isFileTooLarge([]));
    }

    // ============================================================
    // hasFile / getFileCount / isMediaGroup
    // ============================================================

    public function testHasFileReturnsTrueForMessageWithAttachments(): void
    {
        $message = [
            'body' => [
                'attachments' => [['type' => 'image']],
            ],
        ];

        $this->assertTrue($this->handler->hasFile($message));
    }

    public function testHasFileReturnsFalseForMessageWithoutAttachments(): void
    {
        $this->assertFalse($this->handler->hasFile([]));
    }

    public function testGetFileCountReturnsCorrectCount(): void
    {
        $message = [
            'body' => [
                'attachments' => [
                    ['type' => 'image'],
                    ['type' => 'document'],
                    ['type' => 'image'],
                ],
            ],
        ];

        $this->assertSame(3, $this->handler->getFileCount($message));
    }

    public function testIsMediaGroupReturnsTrueWhenMediaGroupIdPresent(): void
    {
        $message = ['media_group_id' => 'abc123'];

        $this->assertTrue($this->handler->isMediaGroup($message));
    }

    public function testIsMediaGroupReturnsFalseWhenMediaGroupIdAbsent(): void
    {
        $message = ['body' => ['text' => 'привет']];

        $this->assertFalse($this->handler->isMediaGroup($message));
    }

    // ============================================================
    // convertAttachmentToFileInfoSimple — основные сценарии
    // ============================================================

    public function testConvertImageWithPhotoId(): void
    {
        $attachment = [
            'type'    => 'image',
            'payload' => ['photo_id' => 'photo_42'],
        ];

        $result = $this->handler->convertAttachmentToFileInfoSimple($attachment, 0);

        $this->assertSame('photo_42', $result['fileId']);
        $this->assertSame('photo_42.jpg', $result['fileName']);
        $this->assertSame('image/jpeg', $result['fileType']);
    }

    public function testConvertImageByUrl(): void
    {
        $attachment = [
            'type'    => 'image',
            'payload' => ['url' => 'https://example.com/image.jpg'],
        ];

        $result = $this->handler->convertAttachmentToFileInfoSimple($attachment, 0);

        $this->assertSame('https://example.com/image.jpg', $result['filePath']);
        $this->assertSame('image.jpg', $result['fileName']);
    }

    public function testConvertDocumentUsesFilenameWhenProvided(): void
    {
        $attachment = [
            'type'     => 'document',
            'filename' => 'invoice.pdf',
            'payload'  => ['file_id' => 'doc_1', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->handler->convertAttachmentToFileInfoSimple($attachment, 0);

        $this->assertSame('invoice.pdf', $result['fileName']);
        $this->assertSame('application/pdf', $result['fileType']);
    }

    public function testConvertVideoWithUrl(): void
    {
        $attachment = [
            'type'    => 'video',
            'payload' => ['id' => 'vid_99', 'url' => 'https://example.com/vid.mp4'],
        ];

        $result = $this->handler->convertAttachmentToFileInfoSimple($attachment, 0);

        $this->assertSame('vid_99', $result['fileId']);
        $this->assertSame('vid_99.mp4', $result['fileName']);
    }

    // ============================================================
    // handleMediaGroup
    // ============================================================

    public function testHandleMediaGroupDoesNothingWithoutMediaGroupId(): void
    {
        $this->stateFileHandler->shouldNotReceive('setStateField');

        $this->handler->handleMediaGroup('123', [], '');
    }

    public function testHandleMediaGroupDoesNothingWithoutSupportedFiles(): void
    {
        $this->stateFileHandler
            ->shouldReceive('getStateField')
            ->with('123', 'file_links')
            ->andReturn([]);

        $this->stateFileHandler
            ->shouldReceive('setStateField')
            ->once()
            ->with('123', 'file_links', []);

        $this->handler->handleMediaGroup(
            '123',
            ['media_group_id' => 'abc'],
            ''
        );
    }
}