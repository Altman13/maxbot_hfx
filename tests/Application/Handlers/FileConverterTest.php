<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handlers;

use App\Application\Handlers\FileConverter;
use PHPUnit\Framework\TestCase;

class FileConverterTest extends TestCase
{
    private FileConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new FileConverter();
    }

    public function testConvertsImageWithPhotoId(): void
    {
        $attachment = [
            'type'    => 'image',
            'payload' => ['photo_id' => 'photo_123', 'size' => 1024],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('image', $result['type']);
        $this->assertSame('photo_123', $result['fileId']);
        $this->assertSame('image/jpeg', $result['fileType']);
        $this->assertSame('photo_123.jpg', $result['fileName']);
        $this->assertSame(1024, $result['fileSize']);
    }

    public function testConvertsImageByUrlWhenNoPhotoId(): void
    {
        $attachment = [
            'type'    => 'image',
            'payload' => ['url' => 'https://example.com/photo.jpg'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('image', $result['type']);
        $this->assertSame('https://example.com/photo.jpg', $result['filePath']);
        $this->assertSame('photo.jpg', $result['fileName']);
    }

    public function testConvertsVideoWithId(): void
    {
        $attachment = [
            'type'    => 'video',
            'payload' => ['id' => 'vid_777', 'mime_type' => 'video/mp4'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('video', $result['type']);
        $this->assertSame('vid_777', $result['fileId']);
        $this->assertSame('video/mp4', $result['fileType']);
        $this->assertSame('vid_777.mp4', $result['fileName']);
    }

    public function testConvertsVideoFromUrlId(): void
    {
        $attachment = [
            'type'    => 'video',
            'payload' => ['url' => 'https://example.com/file.mp4?foo=bar&id=555'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('555', $result['fileId']);
        $this->assertSame('555.mp4', $result['fileName']);
    }

    public function testConvertsDocumentWithFileId(): void
    {
        $attachment = [
            'type'    => 'document',
            'payload' => ['file_id' => 'doc_42', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('doc_42', $result['fileId']);
        $this->assertSame('application/pdf', $result['fileType']);
        $this->assertSame('doc_42.pdf', $result['fileName']);
    }

    public function testConvertsAudioWithId(): void
    {
        $attachment = [
            'type'    => 'audio',
            'payload' => ['id' => 'aud_1', 'mime_type' => 'audio/mpeg'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('aud_1', $result['fileId']);
        $this->assertSame('aud_1.mp3', $result['fileName']);
    }

    public function testPreservesExplicitFilename(): void
    {
        $attachment = [
            'type'     => 'document',
            'filename' => 'my-custom-name.pdf',
            'payload'  => ['file_id' => 'doc_42', 'mime_type' => 'application/pdf'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('my-custom-name.pdf', $result['fileName']);
    }

    public function testHandlesUnknownTypeWithUrl(): void
    {
        $attachment = [
            'type'    => 'unknown',
            'payload' => ['url' => 'https://example.com/file.bin'],
        ];

        $result = $this->converter->convert($attachment, 0);

        $this->assertSame('unknown', $result['type']);
        $this->assertSame('https://example.com/file.bin', $result['filePath']);
        $this->assertSame('file.bin', $result['fileName']);
    }

    public function testFallbacksToTypeIndexWhenUrlHasNoBasename(): void
    {
        $attachment = [
            'type'    => 'unknown',
            'payload' => ['url' => 'https://example.com/'],
        ];

        $result = $this->converter->convert($attachment, 5);

        $this->assertSame('unknown_5', $result['fileName']);
    }
}