<?php

declare(strict_types=1);

namespace App\Application\Helpers;

use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use Psr\Log\LoggerInterface;
use App\Application\Helpers\MaxBotHelper;

class QrCodeHelper
{
    private $maxBotHelper;
    private $logger;

    public function __construct(LoggerInterface $logger, MaxBotHelper $maxBotHelper)
    {
        $this->logger = $logger;
        $this->maxBotHelper = $maxBotHelper;
    }

    /**
     * Отправить сообщение с QR сканером через MaxBot
     * 
     * @param int|string $chatId ID чата в MaxBot
     * @param string $message Текст сообщения
     * @return array|null Ответ от API или null в случае ошибки
     */
    public function sendQRCodeScanner($chatId, string $message): ?array
    {
        try {
            $chatId = (int)$chatId;

            // Прямой запрос в MAX API без конвертаций
            $data = [
                'text' => $message,
                'attachments' => [
                    [
                        'type' => 'inline_keyboard',
                        'payload' => [
                            'buttons' => [
                                [
                                    [
                                        'type' => 'callback',
                                        'text' => State::Return_To_Start->value,
                                        'payload' => State::Return_To_Start->value
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $result = $this->maxBotHelper->request('POST', 'messages?chat_id=' . $chatId, $data);

            $this->logger->info("QR Scanner link sent to chat: $chatId");
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Error: " . $e->getMessage());
            return null;
        }
    }
}
