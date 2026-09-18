<?php
// MaxBotHelper.php
declare(strict_types=1);

namespace App\Application\Helpers;

use CURLFile;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
/**
 * Класс-хелпер для работы с MAX Bot API
 */
class MaxBotHelper
{
    private Client $http;
    private string $token;
    private array $config;

    /**
     * @param string $token Токен бота
     * @param array $config Дополнительные настройки
     */
    public function __construct(string $token, array $config = [])
    {
        //TODO: перенести токен в env
        $this->token = $_ENV['MAXBOT_API_KEY'];
        $this->config = array_merge([
            'base_uri' => 'https://platform-api.max.ru/',
            'timeout' => 30,
            'verify' => false,
        ], $config);

        $this->http = new Client([
            'base_uri' => $this->config['base_uri'],
            'timeout' => $this->config['timeout'],
            'verify' => $this->config['verify'],
            'headers' => [
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * Получить информацию о боте
     */
    public function getMe(): array
    {
        return $this->request('GET', 'me');
    }

    /**
     * Отправить текстовое сообщение
     */
    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $data = array_merge([
            'text' => $text
        ], $options);

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить меню, созданное через MenuCreateAction
     * 
     * @param array $menuData Массив, возвращенный из MenuCreateAction::createMenuLogic()
     * @return array Ответ от API
     */
    public function sendMenu(array $menuData): array
    {
        $chatId = $menuData['chat_id'];
        $text = $menuData['text'];

        // Проверяем, есть ли клавиатура в reply_markup
        if (isset($menuData['reply_markup']) && !empty($menuData['reply_markup'])) {
            // Конвертируем reply_markup в формат Max бота
            return $this->sendKeyboard((int)$chatId, $text, $menuData['reply_markup']);
        }

        // Если нет клавиатуры, отправляем простое сообщение
        return $this->sendMessage((int)$chatId, $text);
    }

    /**
     * Отправить сообщение с клавиатурой
     */
    public function sendKeyboard(int $chatId, string $text, array $keyboardData, array $options = []): array
    {

        // Преобразуем клавиатуру из формата Telegram в формат Max
        $buttons = $this->convertKeyboardToMaxFormat($keyboardData);
        $attachments = [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => $buttons
                ]
            ]
        ];

        $data = array_merge([
            'text' => $text,
            'attachments' => $attachments
        ], $options);


        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Конвертирует клавиатуру из формата Telegram в формат Max
     */
    private function convertKeyboardToMaxFormat(array $keyboardData): array
    {
        $maxButtons = [];

        // Если клавиатура в формате Telegram (keyboard или inline_keyboard)
        if (isset($keyboardData['keyboard'])) {
            // Reply клавиатура
            foreach ($keyboardData['keyboard'] as $row) {
                $rowButtons = [];
                foreach ($row as $button) {
                    if (is_array($button)) {
                        $rowButtons[] = $this->convertButtonToMaxFormat($button);
                    } else {
                        $rowButtons[] = $this->convertButtonToMaxFormat(['text' => $button]);
                    }
                }
                $maxButtons[] = $rowButtons;
            }
        } elseif (isset($keyboardData['inline_keyboard'])) {
            // Inline клавиатура
            foreach ($keyboardData['inline_keyboard'] as $row) {
                $rowButtons = [];
                foreach ($row as $button) {
                    $rowButtons[] = $this->convertButtonToMaxFormat($button);
                }
                $maxButtons[] = $rowButtons;
            }
        } else {
            // Простой массив кнопок
            foreach ($keyboardData as $row) {
                $rowButtons = [];
                if (is_array($row)) {
                    foreach ($row as $button) {
                        $rowButtons[] = $this->convertButtonToMaxFormat($button);
                    }
                } else {
                    $rowButtons[] = $this->convertButtonToMaxFormat(['text' => $row]);
                }
                $maxButtons[] = $rowButtons;
            }
        }

        return $maxButtons;
    }

    /**
     * Конвертирует одну кнопку в формат Max
     */
    private function convertButtonToMaxFormat(array $button): array
    {
        // Если уже в формате Max
        if (isset($button['type'])) {
            return $button;
        }

        // Определяем тип кнопки по наличию специальных полей
        if (isset($button['request_contact']) && $button['request_contact']) {
            return self::contactButton($button['text']);
        }

        if (isset($button['request_location']) && $button['request_location']) {
            return self::geoButton($button['text']);
        }

        if (isset($button['url'])) {
            return self::linkButton($button['text'], $button['url']);
        }

        // ДОБАВЛЯЕМ: Обработка Web App кнопки (Telegram формат)
        if (isset($button['web_app'])) {
            $webAppUrl = is_array($button['web_app'])
                ? ($button['web_app']['url'] ?? '')
                : $button['web_app'];

            return [
                'type' => 'open_app',
                'text' => $button['text'] ?? '',
                'webApp' => $webAppUrl
            ];
        }

        // По умолчанию - callback кнопка
        $callbackData = $button['callback_data'] ?? $button['text'];
        return self::callbackButton($button['text'], $callbackData);
    }

    /**
     * Отправить сообщение с кнопками в несколько рядов
     */
    public function sendKeyboardRows(int $chatId, string $text, array $rows, array $options = []): array
    {
        $buttons = [];
        foreach ($rows as $row) {
            $rowButtons = [];
            foreach ($row as $btn) {
                $rowButtons[] = $btn;
            }
            $buttons[] = $rowButtons;
        }

        return $this->sendKeyboard($chatId, $text, $buttons, $options);
    }

    /**
     * Ответить на callback
     */
    public function answerCallback(string $callbackId, ?string $notification = null, ?array $newMessage = null): array
    {
        $data = [];
        if ($notification) {
            $data['notification'] = $notification;
        }
        if ($newMessage) {
            $data['message'] = $newMessage;
        }
        return $this->request('POST', 'answers?callback_id=' . urlencode($callbackId), $data);
    }

    /**
     * Получить обновления (Long Polling)
     */
    public function getUpdates(?int $marker = null, int $limit = 100, int $timeout = 30, ?array $types = null): array
    {
        $query = "limit={$limit}&timeout={$timeout}";
        if ($marker) {
            $query .= "&marker={$marker}";
        }
        if ($types) {
            $query .= "&types=" . implode(',', $types);
        }

        return $this->request('GET', 'updates?' . $query);
    }

    /**
     * Отправить фото по URL
     */
    public function sendPhoto(int $chatId, string $photoUrl, ?string $caption = null): array
    {
        $attachments = [
            [
                'type' => 'image',
                'payload' => [
                    'url' => $photoUrl
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($caption) {
            $data['text'] = $caption;
        }

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить видео по URL
     */
    public function sendVideo(int $chatId, string $videoUrl, ?string $caption = null): array
    {
        $attachments = [
            [
                'type' => 'video',
                'payload' => [
                    'url' => $videoUrl
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($caption) {
            $data['text'] = $caption;
        }
        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить геолокацию
     */
    public function sendLocation(int $chatId, float $lat, float $lon, ?string $text = null): array
    {
        $attachments = [
            [
                'type' => 'location',
                'payload' => [
                    'latitude' => $lat,
                    'longitude' => $lon
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($text) {
            $data['text'] = $text;
        }

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить контакт
     */
    public function sendContact(int $chatId, string $phone, string $firstName, ?string $lastName = null): array
    {
        $attachments = [
            [
                'type' => 'contact',
                'payload' => [
                    'phone_number' => $phone,
                    'first_name' => $firstName,
                    'last_name' => $lastName
                ]
            ]
        ];

        return $this->request('POST', 'messages?chat_id=' . $chatId, ['attachments' => $attachments]);
    }

    /**
     * Редактировать сообщение
     */
    public function editMessage(string $messageId, string $newText, ?array $newAttachments = null): array
    {
        $data = ['text' => $newText];
        if ($newAttachments) {
            $data['attachments'] = $newAttachments;
        }

        return $this->request('PUT', 'messages?message_id=' . urlencode($messageId), $data);
    }

    /**
     * Удалить сообщение
     */
    public function deleteMessage(string $messageId): array
    {
        return $this->request('DELETE', 'messages?message_id=' . urlencode($messageId));
    }

    /**
     * Получить информацию о чате
     */
    public function getChat(int $chatId): array
    {
        return $this->request('GET', 'chats/' . $chatId);
    }

    /**
     * Получить список чатов
     */
    public function getChats(int $count = 50, ?int $marker = null): array
    {
        $query = "count={$count}";
        if ($marker) {
            $query .= "&marker={$marker}";
        }

        return $this->request('GET', 'chats?' . $query);
    }

    /**
     * Базовый метод для запросов
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $options = [];
            if (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->http->request($method, $endpoint, $options);
            $content = $response->getBody()->getContents();

            return json_decode($content, true) ?: [];
        } catch (GuzzleException $e) {
            throw new RuntimeException("API Error: " . $e->getMessage());
        }
    }

    /**
     * Создать кнопку-ссылку
     */
    public static function linkButton(string $text, string $url): array
    {
        return [
            'type' => 'link',
            'text' => $text,
            'url' => $url
        ];
    }

    /**
     * Создать callback кнопку
     */
    public static function callbackButton(string $text, string $callbackData, ?string $intent = null): array
    {
        $btn = [
            'type' => 'callback',
            'text' => $text,
            'payload' => $callbackData
        ];

        if ($intent) {
            $btn['intent'] = $intent;
        }

        return $btn;
    }

    /**
     * Создать кнопку для перманентного меню
     */
    public static function menuButton(string $text, string $payload): array
    {
        return [
            'type' => 'text',
            'text' => $text,
            'payload' => $payload
        ];
    }

    /**
     * Установить перманентное меню для пользователя
     * 
     * @param int $chatId ID чата
     * @param array $buttons Массив кнопок меню (может быть с группировкой по рядам)
     * @param string $welcomeText Приветственный текст (опционально)
     * @return array Ответ от API
     */
    public function setPersistentMenu(int $chatId, array $buttons, string $welcomeText = ' '): array
    {
        $attachments = [
            [
                'type' => 'menu',
                'payload' => [
                    'buttons' => $buttons
                ]
            ]
        ];

        $data = [
            'text' => $welcomeText,
            'attachments' => $attachments
        ];

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Обновить перманентное меню (отправка нового меню заменяет старое)
     */
    public function updatePersistentMenu(int $chatId, array $buttons, ?string $welcomeText = null): array
    {
        return $this->setPersistentMenu($chatId, $buttons, $welcomeText ?? 'Меню обновлено');
    }

    /**
     * Удалить перманентное меню (отправить пустое меню)
     */
    public function removePersistentMenu(int $chatId, string $message = 'Меню удалено'): array
    {
        return $this->setPersistentMenu($chatId, [], $message);
    }

    /**
     * Создать кнопку запроса геолокации
     */
    public static function geoButton(string $text, bool $quick = true): array
    {
        return [
            'type' => 'request_geo',
            'text' => $text,
            'quick' => $quick
        ];
    }

    /**
     * Создать кнопку запроса контакта
     */
    public static function contactButton(string $text, bool $quick = true): array
    {
        return [
            'type' => 'request_contact',
            'text' => $text,
            'quick' => $quick
        ];
    }

    /**
     * Отправить запрос на получение контакта пользователя
     * 
     * @param int $chatId ID чата
     * @param string $text Текст сообщения
     * @return array Ответ от API
     */
    public function requestContact(int $chatId, string $text = 'Поделитесь вашим контактом'): array
    {
        $buttons = [
            [
                [
                    'type' => 'request_contact',
                    'text' => '📱 Поделиться контактом',
                    'quick' => true
                ]
            ],
        ];

        $attachments = [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => $buttons
                ]
            ]
        ];

        $data = [
            'text' => $text,
            'attachments' => $attachments
        ];

        error_log("Sending contact request: " . json_encode($data, JSON_UNESCAPED_UNICODE));

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить запрос геолокации
     */
    public function requestLocation(int $chatId, string $text = 'Поделитесь вашей геолокацией'): array
    {
        $buttons = [
            [
                [
                    'type' => 'request_geo_location',
                    'text' => '📍 Поделиться местоположением',
                    'quick' => true
                ]
            ],
            [
                [
                    'type' => 'callback',
                    'text' => '❌ Отмена',
                    'payload' => 'cancel_location'
                ]
            ]
        ];

        $attachments = [
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => $buttons
                ]
            ]
        ];

        $data = [
            'text' => $text,
            'attachments' => $attachments
        ];

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Извлечь данные контакта из входящего JSON
     */
    public function extractContactData(array $jsonData): array
    {
        $result = [
            'phone' => null,
            'first_name' => null,
            'last_name' => null,
            'full_name' => null,
            'user_id' => null
        ];

        if (
            isset($jsonData['body']['attachments'][0]['type']) &&
            $jsonData['body']['attachments'][0]['type'] === 'contact'
        ) {

            $payload = $jsonData['body']['attachments'][0]['payload'] ?? [];

            // 1. Извлекаем из max_info (данные пользователя MAX)
            if (isset($payload['max_info'])) {
                $result['user_id'] = $payload['max_info']['user_id'] ?? null;
                $result['first_name'] = $payload['max_info']['first_name'] ?? null;
                $result['last_name'] = $payload['max_info']['last_name'] ?? null;
                $result['full_name'] = $payload['max_info']['name'] ?? null;
            }

            // 2. Извлекаем телефон из vcf_info
            if (isset($payload['vcf_info'])) {
                $vcf = $payload['vcf_info'];

                // Ищем телефон
                if (preg_match('/TEL[^:]*:([0-9+]+)/', $vcf, $matches)) {
                    $result['phone'] = $matches[1];
                }

                // Если имя не получено из max_info, пробуем из vcf
                if (!$result['full_name'] && preg_match('/FN:(.+)/', $vcf, $matches)) {
                    $result['full_name'] = trim($matches[1]);

                    // Разбиваем полное имя на имя и фамилию
                    $nameParts = explode(' ', $result['full_name'], 2);
                    $result['first_name'] = $nameParts[0] ?? '';
                    $result['last_name'] = $nameParts[1] ?? '';
                }
            }
        }

        return $result;
    }

    /**
     * Отправить фото по токену (правильный способ для MAX API)
     * 
     * @param int $chatId ID чата
     * @param string $token Токен изображения, полученный после загрузки
     * @param string|null $caption Подпись к фото
     * @return array Ответ от API
     */
    public function sendPhotoByToken(int $chatId, string $token, ?string $caption = null): array
    {
        $attachments = [
            [
                'type' => 'image',
                'payload' => [
                    'token' => $token
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($caption) {
            $data['text'] = $caption;
        }

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Отправить фото по URL (резервный метод)
     */
    public function sendPhotoByUrl(int $chatId, string $photoUrl, ?string $caption = null): array
    {
        $attachments = [
            [
                'type' => 'image',
                'payload' => [
                    'url' => $photoUrl
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($caption) {
            $data['text'] = $caption;
        }

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Получить URL для загрузки файла
     * 
     * @param string $type Тип файла (image, video, audio, file)
     * @return string URL для загрузки
     */
    public function getUploadUrl(string $type = 'image'): string
    {
        $response = $this->request('POST', 'uploads?type=' . $type);

        if (!isset($response['url'])) {
            throw new RuntimeException("Не удалось получить URL для загрузки: " . json_encode($response));
        }

        return $response['url'];
    }

    /**
     * Загрузить файл по URL
     */
    public function uploadFileToUrl(string $uploadUrl, string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Файл не найден: $filePath");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        $ch = curl_init();
        $curlFile = new CURLFile($filePath, $mimeType, basename($filePath));

        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => ['data' => $curlFile],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->token,
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("Ошибка загрузки: HTTP $httpCode, Response: $response");
        }

        return json_decode($response, true) ?: [];
    }

    /**
     * Загрузить и отправить файл (ГЛАВНЫЙ ИСПРАВЛЕННЫЙ МЕТОД)
     */
    public function uploadAndSendFile(int $chatId, string $filePath, string $type = 'file', ?string $caption = null): array
    {
        $token = null;
        
        if ($type === 'video' || $type === 'audio') {
            // Для video/audio: token из первого запроса
            $uploadResponse = $this->request('POST', 'uploads?type=' . $type);
            if (!isset($uploadResponse['url']) || !isset($uploadResponse['token'])) {
                throw new RuntimeException("Не удалось получить URL или token для $type");
            }
            $uploadUrl = $uploadResponse['url'];
            $token = $uploadResponse['token'];
        } else {
            // Для image/file: получаем только URL
            $uploadUrl = $this->getUploadUrl($type);
        }
        
        $uploadResult = $this->uploadFileToUrl($uploadUrl, $filePath);
        
        if ($type === 'image' || $type === 'file') {
            $token = $uploadResult['token'] ?? null;
        }
        
        if (!$token) {
            error_log('Не получен token от MAX API');
        }
        
        sleep(1); // Пауза согласно документации
        
        // Отправляем с токеном
        $attachments = [['type' => $type, 'payload' => ['token' => $token]]];
        $data = ['attachments' => $attachments];
        if ($caption) $data['text'] = $caption;
        
        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }

    /**
     * Загрузить и отправить фото
     */
    /**
     * Загрузить и отправить фото (полный цикл)
     * 
     * @param int $chatId ID чата
     * @param string $photoPath Путь к файлу изображения
     * @param string|null $caption Подпись к фото
     * @return array Ответ от API
     */
    public function uploadAndSendPhoto(int $chatId, string $photoPath, ?string $caption = null): array
    {
        try {
            // ШАГ 1: Получаем URL для загрузки
            $uploadUrl = $this->getUploadUrl('image');

            // ШАГ 2: Загружаем файл
            $uploadResult = $this->uploadFileToUrl($uploadUrl, $photoPath);

            // ШАГ 3: Проверяем, что получили токен или другие данные
            if (isset($uploadResult['token'])) {
                // Есть токен - используем sendPhotoByToken
                sleep(1); // Пауза для обработки
                return $this->sendPhotoByToken($chatId, $uploadResult['token'], $caption);
            } elseif (isset($uploadResult['url'])) {
                // Есть URL - используем sendPhotoByUrl
                return $this->sendPhotoByUrl($chatId, $uploadResult['url'], $caption);
            } elseif (!empty($uploadResult)) {
                // Если вернули какие-то данные, пробуем отправить их как payload
                $attachments = [
                    [
                        'type' => 'image',
                        'payload' => $uploadResult
                    ]
                ];

                $data = ['attachments' => $attachments];
                if ($caption) {
                    $data['text'] = $caption;
                }

                return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
            } else {
                throw new RuntimeException("Не удалось загрузить изображение: пустой ответ");
            }
        } catch (\Exception $e) {
            error_log("Ошибка в uploadAndSendPhoto: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Пример использования перманентного меню в контексте заявки
     * Этот метод можно вызвать из другого класса
     */
    public function setRequestPersistentMenu(int $chatId): array
    {
        $menuButtons = [
            [ // Первый ряд
                self::menuButton('📋 Статус заявки', 'check_status'),
                self::menuButton('💬 Комментарий', 'add_comment')
            ],
            [ // Второй ряд
                self::menuButton('📎 Прикрепить файл', 'attach_file'),
                self::menuButton('❌ Закрыть заявку', 'close_request')
            ]
        ];

        return $this->setPersistentMenu(
            $chatId,
            $menuButtons,
            '🔍 Выберите действие:'
        );
    }

    /**
     * Отправить видео из локального файла
     */
    public function sendLocalVideo(int $chatId, string $videoPath, ?string $caption = null): array
    {
        try {
            if (!file_exists($videoPath)) {
                throw new RuntimeException("Файл не найден: $videoPath");
            }

            // ШАГ 1: Получаем URL и токен для загрузки видео
            $uploadResponse = $this->request('POST', 'uploads?type=video');

            if (!isset($uploadResponse['url']) || !isset($uploadResponse['token'])) {
                throw new RuntimeException("Не удалось получить URL или токен для видео");
            }

            $uploadUrl = $uploadResponse['url'];
            $videoToken = $uploadResponse['token']; // Токен берем здесь!

            // ШАГ 2: Загружаем видео (ответ игнорируем, он придет <retval>1</retval>)
            $this->uploadVideoFile($uploadUrl, $videoPath);

            // ШАГ 3: Отправляем сообщение с токеном из первого запроса
            return $this->sendVideoByToken($chatId, $videoToken, $caption);
        } catch (\Exception $e) {
            error_log("Ошибка отправки видео: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Загрузить видео файл
     */
    private function uploadVideoFile(string $uploadUrl, string $filePath): void
    {
        $ch = curl_init();
        $cfile = new CURLFile($filePath, 'video/mp4', basename($filePath));

        curl_setopt_array($ch, [
            CURLOPT_URL => $uploadUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => ['data' => $cfile],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("Ошибка загрузки видео: HTTP $httpCode");
        }

        // <retval>1</retval> - это успех, игнорируем
    }

    /**
     * Отправить видео по токену
     */
    public function sendVideoByToken(int $chatId, string $token, ?string $caption = null): array
    {
        $attachments = [
            [
                'type' => 'video',
                'payload' => [
                    'token' => $token
                ]
            ]
        ];

        $data = ['attachments' => $attachments];
        if ($caption) {
            $data['text'] = $caption;
        }

        return $this->request('POST', 'messages?chat_id=' . $chatId, $data);
    }
}
