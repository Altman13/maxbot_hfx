<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Helpers;

use App\Application\Helpers\ArmHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArmHelperTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);

        // Бэкапим и устанавливаем переменные окружения
        $this->envBackup['ARM_API_KEY']   = $_ENV['ARM_API_KEY'] ?? null;
        $this->envBackup['ADMIN_API_KEY'] = $_ENV['ADMIN_API_KEY'] ?? null;

        $_ENV['ARM_API_KEY']   = 'test-arm-key';
        $_ENV['ADMIN_API_KEY'] = 'test-admin-key';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /**
     * Создаёт ArmHelper с подменённым HTTP-клиентом через рефлексию.
     */
    private function createHelperWithMockedClient(array $responses): ArmHelper
    {
        $helper = new ArmHelper($this->logger);

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://api-machine.armorjack.ru/',
        ]);

        $reflection = new \ReflectionClass($helper);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($helper, $client);

        return $helper;
    }

    // ---------------------------------------------------------------
    // findWorkerByPhone
    // ---------------------------------------------------------------

    public function testFindWorkerByPhoneReturnsTrueWhenWorkerExists(): void
    {
        $body = json_encode([
            'list' => [
                ['phoneEmployee' => '+7 (999) 123-45-67'],
                ['phoneEmployee' => '79991234568'],
            ],
        ]);

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], $body),
        ]);

        $this->assertTrue($helper->findWorkerByPhone('79991234567'));
    }

    public function testFindWorkerByPhoneReturnsFalseWhenWorkerNotFound(): void
    {
        $body = json_encode([
            'list' => [
                ['phoneEmployee' => '79990000000'],
            ],
        ]);

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], $body),
        ]);

        $this->assertFalse($helper->findWorkerByPhone('79991234567'));
    }

    public function testFindWorkerByPhoneReturnsFalseOnInvalidPhone(): void
    {
        // Невалидный номер — запрос не должен выполняться
        $helper = $this->createHelperWithMockedClient([]);

        $this->assertFalse($helper->findWorkerByPhone('123'));
    }

    public function testFindWorkerByPhoneReturnsFalseOnGuzzleException(): void
    {
        $this->logger->expects($this->once())
            ->method('error');

        $helper = $this->createHelperWithMockedClient([
            new ConnectException('Connection error', new Request('GET', 'v1/helpdesk/worker')),
        ]);

        $this->assertFalse($helper->findWorkerByPhone('79991234567'));
    }

    public function testFindWorkerByPhoneHandlesEmptyList(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['list' => []])),
        ]);

        $this->assertFalse($helper->findWorkerByPhone('79991234567'));
    }

    // ---------------------------------------------------------------
    // getAllWorkers
    // ---------------------------------------------------------------

    public function testGetAllWorkersReturnsList(): void
    {
        $workers = [
            ['id' => 1, 'firstName' => 'Ivan'],
            ['id' => 2, 'firstName' => 'Petr'],
        ];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['list' => $workers])),
        ]);

        $this->assertSame($workers, $helper->getAllWorkers());
    }

    public function testGetAllWorkersReturnsEmptyArrayOnError(): void
    {
        $this->logger->expects($this->once())->method('error');

        $helper = $this->createHelperWithMockedClient([
            new ConnectException('error', new Request('GET', 'v2/helpdesk/worker')),
        ]);

        $this->assertSame([], $helper->getAllWorkers());
    }

    public function testGetAllWorkersReturnsEmptyArrayWhenNoList(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['foo' => 'bar'])),
        ]);

        $this->assertSame([], $helper->getAllWorkers());
    }

    // ---------------------------------------------------------------
    // processOfflineCut
    // ---------------------------------------------------------------

    public function testProcessOfflineCutSuccess(): void
    {
        $body = json_encode([
            'Result'  => 'Ok',
            'Message' => 'Done',
            'Context' => ['foo' => 'bar'],
            'QR'      => 'qr-123',
        ]);

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], $body),
        ]);

        $result = $helper->processOfflineCut('79991234567', 'qr-123');

        $this->assertSame('success', $result['status']);
        $this->assertSame('Done', $result['message']);
        $this->assertSame(['foo' => 'bar'], $result['context']);
        $this->assertSame('qr-123', $result['qr']);
    }

    public function testProcessOfflineCutReturnsErrorOn400(): void
    {
        $body = json_encode([
            'Message' => 'Invalid parameters',
        ]);

        $helper = $this->createHelperWithMockedClient([
            new Response(400, [], $body),
        ]);

        $result = $helper->processOfflineCut('invalid', 'qr');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Invalid parameters', $result['message']);
    }

    public function testProcessOfflineCutReturnsErrorOn500(): void
    {
        $body = json_encode(['Message' => 'Server error']);

        $helper = $this->createHelperWithMockedClient([
            new Response(500, [], $body),
        ]);

        $result = $helper->processOfflineCut('79991234567', 'qr');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Internal Server Error', $result['message']);
    }

    public function testProcessOfflineCutHandlesGuzzleException(): void
    {
        $this->logger->expects($this->once())->method('error');

        $helper = $this->createHelperWithMockedClient([
            new ConnectException('timeout', new Request('POST', 'v1/helpdesk/checks/offline')),
        ]);

        $result = $helper->processOfflineCut('79991234567', 'qr');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Failed to process offline cut', $result['message']);
    }

    // ---------------------------------------------------------------
    // getWorkerAccessData (через рефлексию на приватные методы)
    // ---------------------------------------------------------------

    public function testSplitFullName(): void
    {
        $helper = new ArmHelper($this->logger);
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('splitFullName');
        $method->setAccessible(true);

        $result = $method->invoke($helper, 'Иванов Иван Иванович');

        $this->assertSame('Иванов', $result['secondName']);
        $this->assertSame('Иван', $result['firstName']);
        $this->assertSame('Иванович', $result['thirdName']);
    }

    public function testSplitFullNameHandlesPartialName(): void
    {
        $helper = new ArmHelper($this->logger);
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('splitFullName');
        $method->setAccessible(true);

        $result = $method->invoke($helper, 'Иванов');

        $this->assertSame('Иванов', $result['secondName']);
        $this->assertSame('', $result['firstName']);
        $this->assertSame('', $result['thirdName']);
    }

    public function testValidatePhoneNumber(): void
    {
        $helper = new ArmHelper($this->logger);
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('validatePhoneNumber');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($helper, '79991234567'));
        $this->assertTrue($method->invoke($helper, '375123456789'));
        $this->assertTrue($method->invoke($helper, '37412345678'));
        $this->assertTrue($method->invoke($helper, '998123456789'));

        $this->assertFalse($method->invoke($helper, '123'));
        $this->assertFalse($method->invoke($helper, '89991234567'));
        $this->assertFalse($method->invoke($helper, ''));
    }

    public function testNormalizePhoneNumber(): void
    {
        $helper = new ArmHelper($this->logger);
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('normalizePhoneNumber');
        $method->setAccessible(true);

        $this->assertSame('79991234567', $method->invoke($helper, '+7 (999) 123-45-67'));
        $this->assertSame('79991234567', $method->invoke($helper, '7-999-123-45-67'));
    }
}