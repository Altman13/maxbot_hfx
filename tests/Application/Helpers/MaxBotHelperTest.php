<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Helpers;

use App\Application\Helpers\MaxBotHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MaxBotHelperTest extends TestCase
{
    private ?string $originalToken = null;
    /** @var array<int, array{request: Request, options: array}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalToken = $_ENV['MAXBOT_API_KEY'] ?? null;
        $_ENV['MAXBOT_API_KEY'] = 'test-token';
        $this->history = [];
    }

    protected function tearDown(): void
    {
        if ($this->originalToken === null) {
            unset($_ENV['MAXBOT_API_KEY']);
        } else {
            $_ENV['MAXBOT_API_KEY'] = $this->originalToken;
        }
        parent::tearDown();
    }

    /**
     * Создаёт MaxBotHelper с подменённым HTTP-клиентом.
     */
    private function createHelperWithMockedClient(array $responses): MaxBotHelper
    {
        $helper = new MaxBotHelper('test-token');

        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://platform-api.max.ru/',
        ]);

        $reflection = new \ReflectionClass($helper);
        $property = $reflection->getProperty('http');
        $property->setAccessible(true);
        $property->setValue($helper, $client);

        return $helper;
    }

    // ---------------------------------------------------------------
    // Конструктор
    // ---------------------------------------------------------------

    public function testConstructorUsesEnvToken(): void
    {
        $helper = new MaxBotHelper('ignored-token');

        $reflection = new \ReflectionClass($helper);
        $prop = $reflection->getProperty('token');
        $prop->setAccessible(true);

        $this->assertSame('test-token', $prop->getValue($helper));
    }

    // ---------------------------------------------------------------
    // request()
    // ---------------------------------------------------------------

    public function testRequestReturnsDecodedJson(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $result = $helper->request('GET', 'me');

        $this->assertSame(['ok' => true], $result);
    }

    public function testRequestThrowsRuntimeExceptionOnGuzzleException(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new ConnectException('timeout', new Request('GET', 'me')),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API Error');

        $helper->request('GET', 'me');
    }

    public function testRequestSendsJsonBody(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->request('POST', 'messages', ['text' => 'hello', 'chat_id' => 1]);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $body = (string)$request->getBody();
        $this->assertJsonStringEqualsJsonString(
            json_encode(['text' => 'hello', 'chat_id' => 1]),
            $body
        );
    }

    // ---------------------------------------------------------------
    // sendMessage
    // ---------------------------------------------------------------

    public function testSendMessage(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['message_id' => '123'])),
        ]);

        $result = $helper->sendMessage(42, 'Hello');

        $this->assertSame(['message_id' => '123'], $result);

        $request = $this->history[0]['request'];
        $this->assertStringContainsString('chat_id=42', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        $this->assertSame('Hello', $body['text']);
    }

    // ---------------------------------------------------------------
    // sendKeyboard / convertKeyboardToMaxFormat
    // ---------------------------------------------------------------

    public function testSendKeyboardConvertsTelegramInlineKeyboard(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $keyboardData = [
            'inline_keyboard' => [
                [
                    ['text' => 'Button 1', 'callback_data' => 'btn1'],
                    ['text' => 'Site', 'url' => 'https://example.com'],
                ],
            ],
        ];

        $helper->sendKeyboard(1, 'Choose', $keyboardData);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);

        $this->assertSame('Choose', $body['text']);
        $this->assertSame('inline_keyboard', $body['attachments'][0]['type']);

        $buttons = $body['attachments'][0]['payload']['buttons'];
        $this->assertSame('callback', $buttons[0][0]['type']);
        $this->assertSame('btn1', $buttons[0][0]['payload']);
        $this->assertSame('link', $buttons[0][1]['type']);
        $this->assertSame('https://example.com', $buttons[0][1]['url']);
    }

    public function testConvertButtonWithContactRequest(): void
    {
        $helper = new MaxBotHelper('test-token');
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('convertButtonToMaxFormat');
        $method->setAccessible(true);

        $result = $method->invoke($helper, [
            'text' => 'Share contact',
            'request_contact' => true,
        ]);

        $this->assertSame('request_contact', $result['type']);
        $this->assertSame('Share contact', $result['text']);
    }

    public function testConvertButtonWithLocationRequest(): void
    {
        $helper = new MaxBotHelper('test-token');
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('convertButtonToMaxFormat');
        $method->setAccessible(true);

        $result = $method->invoke($helper, [
            'text' => 'Share location',
            'request_location' => true,
        ]);

        $this->assertSame('request_geo', $result['type']);
    }

    public function testConvertButtonWithWebApp(): void
    {
        $helper = new MaxBotHelper('test-token');
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('convertButtonToMaxFormat');
        $method->setAccessible(true);

        $result = $method->invoke($helper, [
            'text' => 'Open App',
            'web_app' => ['url' => 'https://app.example.com'],
        ]);

        $this->assertSame('open_app', $result['type']);
        $this->assertSame('https://app.example.com', $result['webApp']);
    }

    public function testConvertButtonAlreadyInMaxFormat(): void
    {
        $helper = new MaxBotHelper('test-token');
        $reflection = new \ReflectionClass($helper);
        $method = $reflection->getMethod('convertButtonToMaxFormat');
        $method->setAccessible(true);

        $button = ['type' => 'callback', 'text' => 'X', 'payload' => 'y'];
        $result = $method->invoke($helper, $button);

        $this->assertSame($button, $result);
    }

    // ---------------------------------------------------------------
    // sendMenu
    // ---------------------------------------------------------------

    public function testSendMenuWithoutKeyboardSendsPlainMessage(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $helper->sendMenu([
            'chat_id' => 5,
            'text' => 'Hello',
        ]);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('Hello', $body['text']);
        $this->assertArrayNotHasKey('attachments', $body);
    }

    public function testSendMenuWithKeyboard(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $helper->sendMenu([
            'chat_id' => 5,
            'text' => 'Hi',
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'Go', 'callback_data' => 'go']],
                ],
            ],
        ]);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertArrayHasKey('attachments', $body);
    }

    // ---------------------------------------------------------------
    // sendPhoto / sendVideo / sendLocation / sendContact
    // ---------------------------------------------------------------

    public function testSendPhotoByUrl(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->sendPhoto(1, 'https://example.com/img.jpg', 'Caption');

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('image', $body['attachments'][0]['type']);
        $this->assertSame('https://example.com/img.jpg', $body['attachments'][0]['payload']['url']);
        $this->assertSame('Caption', $body['text']);
    }

    public function testSendPhotoByToken(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->sendPhotoByToken(1, 'token-abc', 'Cap');

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('token-abc', $body['attachments'][0]['payload']['token']);
    }

    public function testSendVideo(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->sendVideo(1, 'https://example.com/video.mp4');

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('video', $body['attachments'][0]['type']);
    }

    public function testSendLocation(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->sendLocation(1, 55.75, 37.61);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('location', $body['attachments'][0]['type']);
        $this->assertSame(55.75, $body['attachments'][0]['payload']['latitude']);
    }

    public function testSendContact(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->sendContact(1, '+79991234567', 'Ivan');

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('contact', $body['attachments'][0]['type']);
        $this->assertSame('+79991234567', $body['attachments'][0]['payload']['phone_number']);
    }

    // ---------------------------------------------------------------
    // editMessage / deleteMessage
    // ---------------------------------------------------------------

    public function testEditMessage(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->editMessage('msg-1', 'New text');

        $request = $this->history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringContainsString('message_id=msg-1', (string)$request->getUri());
    }

    public function testDeleteMessage(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->deleteMessage('msg-1');

        $request = $this->history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
    }

    // ---------------------------------------------------------------
    // getUpdates / getChats
    // ---------------------------------------------------------------

    public function testGetUpdatesWithoutMarker(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode(['updates' => []])),
        ]);

        $helper->getUpdates();

        $uri = (string)$this->history[0]['request']->getUri();
        $this->assertStringContainsString('limit=100', $uri);
        $this->assertStringContainsString('timeout=30', $uri);
        $this->assertStringNotContainsString('marker=', $uri);
    }

    public function testGetUpdatesWithMarkerAndTypes(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->getUpdates(123, 10, 5, ['message_created', 'message_callback']);

        $uri = (string)$this->history[0]['request']->getUri();
        $this->assertStringContainsString('marker=123', $uri);
        $this->assertStringContainsString('limit=10', $uri);
        $this->assertStringContainsString('types=message_created,message_callback', $uri);
    }

    public function testGetChats(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->getChats(10, 55);

        $uri = (string)$this->history[0]['request']->getUri();
        $this->assertStringContainsString('count=10', $uri);
        $this->assertStringContainsString('marker=55', $uri);
    }

    // ---------------------------------------------------------------
    // answerCallback
    // ---------------------------------------------------------------

    public function testAnswerCallback(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->answerCallback('cb-1', 'Done');

        $request = $this->history[0]['request'];
        $this->assertStringContainsString('callback_id=cb-1', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        $this->assertSame('Done', $body['notification']);
    }

    // ---------------------------------------------------------------
    // setPersistentMenu / removePersistentMenu
    // ---------------------------------------------------------------

    public function testSetPersistentMenu(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $buttons = [
            [MaxBotHelper::menuButton('Btn', 'payload')],
        ];

        $helper->setPersistentMenu(1, $buttons, 'Welcome');

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame('menu', $body['attachments'][0]['type']);
        $this->assertSame('Welcome', $body['text']);
    }

    public function testRemovePersistentMenu(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])),
        ]);

        $helper->removePersistentMenu(1);

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame([], $body['attachments'][0]['payload']['buttons']);
    }

    // ---------------------------------------------------------------
    // Статические конструкторы кнопок
    // ---------------------------------------------------------------

    public function testLinkButton(): void
    {
        $btn = MaxBotHelper::linkButton('Site', 'https://example.com');
        $this->assertSame('link', $btn['type']);
        $this->assertSame('Site', $btn['text']);
        $this->assertSame('https://example.com', $btn['url']);
    }

    public function testCallbackButton(): void
    {
        $btn = MaxBotHelper::callbackButton('Click', 'data', 'positive');
        $this->assertSame('callback', $btn['type']);
        $this->assertSame('data', $btn['payload']);
        $this->assertSame('positive', $btn['intent']);
    }

    public function testCallbackButtonWithoutIntent(): void
    {
        $btn = MaxBotHelper::callbackButton('Click', 'data');
        $this->assertArrayNotHasKey('intent', $btn);
    }

    public function testContactButton(): void
    {
        $btn = MaxBotHelper::contactButton('Share');
        $this->assertSame('request_contact', $btn['type']);
        $this->assertTrue($btn['quick']);
    }

    public function testGeoButton(): void
    {
        $btn = MaxBotHelper::geoButton('Share');
        $this->assertSame('request_geo', $btn['type']);
    }

    // ---------------------------------------------------------------
    // extractContactData
    // ---------------------------------------------------------------

    public function testExtractContactDataFromMaxInfoAndVcf(): void
    {
        $helper = new MaxBotHelper('test-token');

        $jsonData = [
            'body' => [
                'attachments' => [
                    [
                        'type' => 'contact',
                        'payload' => [
                            'max_info' => [
                                'user_id'    => 123,
                                'first_name' => 'Ivan',
                                'last_name'  => 'Ivanov',
                                'name'       => 'Ivan Ivanov',
                            ],
                            'vcf_info' => "BEGIN:VCARD\nTEL;TYPE=CELL:+79991234567\nFN:Ivan Ivanov\nEND:VCARD",
                        ],
                    ],
                ],
            ],
        ];

        $result = $helper->extractContactData($jsonData);

        $this->assertSame(123, $result['user_id']);
        $this->assertSame('Ivan', $result['first_name']);
        $this->assertSame('Ivanov', $result['last_name']);
        $this->assertSame('Ivan Ivanov', $result['full_name']);
        $this->assertSame('+79991234567', $result['phone']);
    }

    public function testExtractContactDataWithoutAttachment(): void
    {
        $helper = new MaxBotHelper('test-token');

        $result = $helper->extractContactData(['body' => []]);

        $this->assertNull($result['phone']);
        $this->assertNull($result['user_id']);
    }

    // ---------------------------------------------------------------
    // uploadAndSendFile — проверка ошибок
    // ---------------------------------------------------------------

    public function testUploadAndSendFileThrowsWhenVideoUploadUrlMissing(): void
    {
        $helper = $this->createHelperWithMockedClient([
            new Response(200, [], json_encode([])), // нет url/token
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не удалось получить URL или token');

        $helper->uploadAndSendFile(1, '/tmp/file.mp4', 'video');
    }
}