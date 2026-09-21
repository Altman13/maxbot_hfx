<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Helpers;

use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\State\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QrCodeHelperTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private MaxBotHelper|MockObject $maxBotHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->maxBotHelper = $this->createMock(MaxBotHelper::class);
    }

    public function testSendQRCodeScannerSendsMessageWithReturnButton(): void
    {
        $expectedPayload = null;

        $this->maxBotHelper->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'messages?chat_id=42',
                $this->callback(function (array $data) use (&$expectedPayload) {
                    $expectedPayload = $data;
                    return true;
                })
            )
            ->willReturn(['message_id' => '1']);

        $this->logger->expects($this->once())->method('info');

        $helper = new QrCodeHelper($this->logger, $this->maxBotHelper);
        $result = $helper->sendQRCodeScanner(42, 'Scan QR');

        $this->assertSame(['message_id' => '1'], $result);

        $this->assertSame('Scan QR', $expectedPayload['text']);
        $this->assertSame('inline_keyboard', $expectedPayload['attachments'][0]['type']);

        $buttons = $expectedPayload['attachments'][0]['payload']['buttons'];
        $this->assertSame('callback', $buttons[0][0]['type']);
        $this->assertSame(State::Return_To_Start->value, $buttons[0][0]['text']);
        $this->assertSame(State::Return_To_Start->value, $buttons[0][0]['payload']);
    }

    public function testSendQRCodeScannerConvertsChatIdToInt(): void
    {
        $this->maxBotHelper->expects($this->once())
            ->method('request')
            ->with('POST', 'messages?chat_id=99', $this->anything())
            ->willReturn([]);

        $helper = new QrCodeHelper($this->logger, $this->maxBotHelper);
        $helper->sendQRCodeScanner('99', 'msg');
    }

    public function testSendQRCodeScannerReturnsNullOnException(): void
    {
        $this->maxBotHelper->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('API down'));

        $this->logger->expects($this->once())->method('error');

        $helper = new QrCodeHelper($this->logger, $this->maxBotHelper);
        $result = $helper->sendQRCodeScanner(42, 'msg');

        $this->assertNull($result);
    }
}