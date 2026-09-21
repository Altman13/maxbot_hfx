<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Helpers;

use App\Application\Helpers\OkDeskHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OkDeskHelperTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private array $envBackup = [];
    /** @var array<int, array{request: Request, options: array}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->history = [];

        $envKeys = [
            'OKDESK_API_KEY',
            'OKDESK_ISSUE_TYPE',
            'ISSUE_TITLE',
            'PLOTTER_ACTIVATION_TITLE',
            'QR_CUT_REQUEST_TITLE',
        ];

        foreach ($envKeys as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['OKDESK_API_KEY']              = 'test-key';
        $_ENV['OKDESK_ISSUE_TYPE']           = 'hydroflex_new';
        $_ENV['ISSUE_TITLE']                 = 'Test Issue Title';
        $_ENV['PLOTTER_ACTIVATION_TITLE']    = 'Plotter Activation';
        $_ENV['QR_CUT_REQUEST_TITLE']        = 'QR Cut Request';
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
     * Создаёт OkDeskHelper с подменённым HTTP-клиентом.
     */
    private function createHelperWithMockedClient(array $responses): OkDeskHelper
    {
        $helper = new OkDeskHelper($this->logger);

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://insitech.okdesk.ru/api/v1/',
        ]);

        $reflection = new \ReflectionClass($helper);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($helper, $client);

        return $helper;
    }

    // ---------------------------------------------------------------
    // Валидация и нормализация телефона
    // ---------------------------------------------------------------

    public function testValidatePhoneNumber(): void
    {
        $helper = new OkDeskHelper($this->logger);

        $this->assertTrue($helper->validatePhoneNumber('79991234567'));
        $this->assertTrue($helper->validatePhoneNumber('375123456789'));
        $this->assertFalse($helper->validatePhoneNumber('123'));
        $this->assertFalse($helper->validatePhoneNumber('+79991234567'));
    }

    // ---------------------------------------------------------------
    // findUserByPhone
    // ---------------------------------------------------------------

    public function testFindUserByPhoneReturnsData(): void
    {
        $contact = ['id' => 1, 'company_id' => 42];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode($contact)),
        ]);

        $this->assertSame($contact, $helper->findUserByPhone('+79991234567'));
    }

    public function testFindUserByPhoneReturnsNullOnInvalidPhone(): void
    {
        $helper = $this->createHelperWithMockedClient([]);
        $this->assertNull($helper->findUserByPhone('123'));
    }

    public function testFindUserByPhoneReturnsNullOnGuzzleException(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('error', new Request('GET', 'contacts')),
        ]);

        $this->assertNull($helper->findUserByPhone('79991234567'));
    }

    // ---------------------------------------------------------------
    // getAddressByCompanyId
    // ---------------------------------------------------------------

    public function testGetAddressByCompanyIdFiltersByCompany(): void
    {
        $entities = [
            ['id' => 1, 'company_id' => 42, 'name' => 'Address 1'],
            ['id' => 2, 'company_id' => 43, 'name' => 'Address 2'],
        ];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode($entities)),
        ]);

        $result = $helper->getAddressByCompanyId('42');

        $this->assertCount(1, $result);
        $this->assertSame('Address 1', reset($result)['name']);
    }

    public function testGetAddressByCompanyIdReturnsFalseOnException(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('GET', 'maintenance_entities')),
        ]);

        $this->assertFalse($helper->getAddressByCompanyId('42'));
    }

    // ---------------------------------------------------------------
    // getDataByMaintenceName
    // ---------------------------------------------------------------

    public function testGetDataByMaintenceName(): void
    {
        $data = ['id' => 5, 'name' => 'Entity'];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode($data)),
        ]);

        $this->assertSame($data, $helper->getDataByMaintenceName('5'));
    }

    public function testGetDataByMaintenceNameReturnsFalseOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('GET', 'maintenance_entities')),
        ]);

        $this->assertFalse($helper->getDataByMaintenceName('5'));
    }

    // ---------------------------------------------------------------
    // getMaintenanceEntityByName
    // ---------------------------------------------------------------

    public function testGetMaintenanceEntityByNameReturnsExactMatch(): void
    {
        $entities = [
            ['id' => 1, 'name' => 'Other'],
            ['id' => 2, 'name' => 'Target'],
        ];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode($entities)),
        ]);

        $result = $helper->getMaintenanceEntityByName('Target');

        $this->assertSame(2, $result['id']);
    }

    public function testGetMaintenanceEntityByNameReturnsNullWhenNoExactMatch(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([['id' => 1, 'name' => 'Other']])),
        ]);

        $this->assertNull($helper->getMaintenanceEntityByName('Target'));
    }

    public function testGetMaintenanceEntityByNameReturnsFalseWhenNotArray(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode('not-array')),
        ]);

        $this->assertFalse($helper->getMaintenanceEntityByName('Target'));
    }

    // ---------------------------------------------------------------
    // getMaintenanceEntityByShopCode
    // ---------------------------------------------------------------

    public function testGetMaintenanceEntityByShopCodeReturnsFirst(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([['id' => 10, 'name' => 'Shop 1']])),
        ]);

        $result = $helper->getMaintenanceEntityByShopCode('SHOP1');

        $this->assertSame(10, $result['id']);
    }

    public function testGetMaintenanceEntityByShopCodeReturnsNullOnEmpty(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $this->assertNull($helper->getMaintenanceEntityByShopCode('SHOP1'));
    }

    // ---------------------------------------------------------------
    // createIssue (с блокировкой)
    // ---------------------------------------------------------------

    public function testCreateIssueCreatesIssueSuccessfully(): void
    {
        $maintenceData = [
            'id' => 5,
            'company_id' => 42,
            'maintenance_entity_id' => 5,
            'equipments_ids' => [100, 101],
            'observers' => [['id' => 1]],
            'observer_groups' => [['id' => 2]],
            'default_assignee_id' => 7,
            'default_assignee_group_id' => 8,
        ];

        $helper = $this->createHelperWithMockedClient([
            // getDataByMaintenceName
            new Response(200, [], json_encode($maintenceData)),
            // getEquipmentIdByInventoryNumber
            new Response(200, [], json_encode(['id' => 100])),
            // POST issues
            new Response(200, [], json_encode(['id' => 999])),
        ]);

        // Изолируем state-файл, чтобы getStateField вернул null
        $chatId = 'chat-test-' . uniqid();

        $result = $helper->createIssue(
            'Test description',
            '42',
            $chatId,
            '555',
            '5'
        );

        $this->assertIsArray($result);
        $this->assertSame(999, $result['id']);
    }

    // ---------------------------------------------------------------
    // issueChangeStatus
    // ---------------------------------------------------------------

    public function testIssueChangeStatusSuccess(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $result = $helper->issueChangeStatus(999, 'completed');

        $this->assertSame(['status' => 'ok'], $result);

        $request = $this->history[0]['request'];
        $this->assertStringContainsString('issues/999/statuses', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        $this->assertSame('completed', $body['code']);
    }

    public function testIssueChangeStatusReturnsFalseOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('POST', 'issues/1/statuses')),
        ]);

        $this->assertFalse($helper->issueChangeStatus(1, 'closed'));
    }

    // ---------------------------------------------------------------
    // rateIssue
    // ---------------------------------------------------------------

    public function testRateIssueSuccess(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $result = $helper->rateIssue(999, '5');

        $this->assertSame(['ok' => true], $result);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('5', $body['issue']['rate']);
    }

    public function testRateIssueReturnsFalseOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('POST', 'issues/1/rates')),
        ]);

        $this->assertFalse($helper->rateIssue(1, '4'));
    }

    // ---------------------------------------------------------------
    // fetchIssueDetails
    // ---------------------------------------------------------------

    public function testFetchIssueDetails(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['id' => 1, 'title' => 'Test'])),
        ]);

        $result = $helper->fetchIssueDetails(1);

        $this->assertSame(['id' => 1, 'title' => 'Test'], $result);
    }

    public function testFetchIssueDetailsReturnsNullOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('GET', 'issues/1')),
        ]);

        $this->assertNull($helper->fetchIssueDetails(1));
    }

    // ---------------------------------------------------------------
    // addCommentToIssue
    // ---------------------------------------------------------------

    public function testAddCommentToIssueSuccess(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['id' => 77])),
        ]);

        $result = $helper->addCommentToIssue(1, 'Hello', 555);

        $this->assertSame(['id' => 77], $result);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('Hello', $body['comment']['content']);
        $this->assertSame(555, $body['comment']['author_id']);
        $this->assertSame('contact', $body['comment']['author_type']);
        $this->assertTrue($body['comment']['public']);
    }

    public function testAddCommentToIssueReturnsFalseOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('POST', 'issues/1/comments')),
        ]);

        $this->assertFalse($helper->addCommentToIssue(1, 'Hello', 1));
    }

    // ---------------------------------------------------------------
    // formatCreatedAt
    // ---------------------------------------------------------------

    public function testFormatCreatedAt(): void
    {
        $helper = new OkDeskHelper($this->logger);

        $this->assertSame('15/03/2024', $helper->formatCreatedAt('2024-03-15 10:30:00'));
    }

    public function testFormatCreatedAtReturnsEmptyStringForEmptyInput(): void
    {
        $helper = new OkDeskHelper($this->logger);

        $this->assertSame('', $helper->formatCreatedAt(''));
    }

    // ---------------------------------------------------------------
    // getDataByMaintenanceId
    // ---------------------------------------------------------------

    public function testGetDataByMaintenanceIdReturnsNullForEmptyId(): void
    {
        $helper = new OkDeskHelper($this->logger);

        $this->assertNull($helper->getDataByMaintenanceId(null));
        $this->assertNull($helper->getDataByMaintenanceId(''));
    }

    public function testGetDataByMaintenanceIdSuccess(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['id' => 5])),
        ]);

        $this->assertSame(['id' => 5], $helper->getDataByMaintenanceId('5'));
    }

    public function testGetDataByMaintenanceIdReturnsNullOnError(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('err', new Request('GET', 'maintenance_entities/5')),
        ]);

        $this->assertNull($helper->getDataByMaintenanceId('5'));
    }

    // ---------------------------------------------------------------
    // createContactSupportRequest / createPlotterActivationRequest / createCutUnlockRequest
    // (проверяем, что делегируют в createIssueRequest с правильными title)
    // ---------------------------------------------------------------

    public function testCreateContactSupportRequestUsesCorrectTitle(): void
    {
        $maintenceData = [
            'id' => 5,
            'company_id' => 42,
            'maintenance_entity_id' => 5,
            'equipments_ids' => [],
        ];

        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode($maintenceData)), // getDataByMaintenanceId
            new Response(200, [], json_encode($maintenceData)), // getDataByMaintenceName
            new Response(200, [], json_encode(['id' => 111])),  // POST issues
        ]);

        $chatId = 'chat-' . uniqid();
        $result = $helper->createContactSupportRequest('42', $chatId, '555', 'Text', '5');

        $this->assertIsArray($result);

        // Найдем запрос POST issues
        $issueRequest = null;
        foreach ($this->history as $entry) {
            if (str_contains((string)$entry['request']->getUri(), 'issues/')) {
                $issueRequest = $entry['request'];
                break;
            }
        }

        $this->assertNotNull($issueRequest);
        $body = json_decode((string)$issueRequest->getBody(), true);
        $this->assertSame('Test Issue Title', $body['title']);
    }
}