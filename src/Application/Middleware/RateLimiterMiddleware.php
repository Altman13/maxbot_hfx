<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Response as SlimResponse;
use Telegram\Bot\Api;

class RateLimiterMiddleware implements MiddlewareInterface
{
    private const RATE_LIMIT = 5;
    private const TIME_WINDOW = 60; // in seconds
    private const RATE_LIMIT_FILE = __DIR__ . '/../../../storage/rate_limit.json';

    protected Api $telegramBot;

    public function __construct(Api $telegramBot)
    {
        $this->telegramBot = $telegramBot;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $requestBody = $request->getParsedBody();
        $chatId = $requestBody['message']['chat']['id'] ?? null;

        if ($chatId && $this->rateLimitExceeded((string) $chatId)) {
            $this->sendRateLimitExceededMessageOnce((string) $chatId);
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Rate limit exceeded. Please try again later.']));
            return $response->withStatus(429)
                            ->withHeader('Content-Type', 'application/json')
                            ->withHeader('Access-Control-Allow-Origin', '*'); // Add CORS header if necessary
        }

        return $handler->handle($request);
    }

    public function rateLimitExceeded(string $chatId): bool
    {
        $rateLimitData = $this->loadRateLimitData();
        $currentTime = time();

        if (!isset($rateLimitData[$chatId])) {
            $rateLimitData[$chatId] = ['timestamps' => [], 'notified' => false];
        }

        if (!isset($rateLimitData[$chatId]['timestamps']) || !is_array($rateLimitData[$chatId]['timestamps'])) {
            $rateLimitData[$chatId]['timestamps'] = [];
        }

        $rateLimitData[$chatId]['timestamps'] = array_filter($rateLimitData[$chatId]['timestamps'], function ($timestamp) use ($currentTime) {
            return ($currentTime - $timestamp) < self::TIME_WINDOW;
        });

        if (count($rateLimitData[$chatId]['timestamps']) >= self::RATE_LIMIT) {
            if (!$rateLimitData[$chatId]['notified']) {
                $this->saveRateLimitData($rateLimitData);
            }
            return true;
        }

        $rateLimitData[$chatId]['timestamps'][] = $currentTime;
        $rateLimitData[$chatId]['notified'] = false;
        $this->saveRateLimitData($rateLimitData);

        return false;
    }

    private function loadRateLimitData(): array
    {
        if (file_exists(self::RATE_LIMIT_FILE)) {
            $data = json_decode(file_get_contents(self::RATE_LIMIT_FILE), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    private function saveRateLimitData(array $data): void
    {
        file_put_contents(self::RATE_LIMIT_FILE, json_encode($data));
    }

    private function sendRateLimitExceededMessageOnce(string $chatId): void
    {
        $rateLimitData = $this->loadRateLimitData();

        if ($rateLimitData[$chatId]['notified']!==false) {
            return;
        }

        $message = "Ограничение скорости. Пожалуйста, повторите попытку позже.";
        $this->telegramBot->sendMessage([
            'chat_id' => $chatId,
            'text' => $message
        ]);

        $rateLimitData[$chatId]['notified'] = true;
        $this->saveRateLimitData($rateLimitData);
    }
}
