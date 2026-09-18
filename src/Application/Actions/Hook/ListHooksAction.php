<?php

declare(strict_types=1);

namespace App\Application\Actions\Hook;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Handlers\StateManagerHandler;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\State\State;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Тонкий фасад обработки хуков от MaxBot/Telegram.
 *
 * Задача класса — принять входящий webhook, распарсить его и последовательно
 * прокинуть через цепочку хендлеров, получаемых из DI-контейнера.
 * Вся бизнес-логика живёт в хендлерах:
 *  - botStartedHandler       — первый запуск бота
 *  - commandHandler          — служебные команды (/start, /main, /create, /close, /delete)
 *  - registrationHandler     — регистрация по контакту
 *  - initialRegistrationHandler — пустое состояние
 *  - unsupportedTypeHandler  — неподдерживаемые типы сообщений
 *  - fileHandler             — парсинг и валидация файлов из webhook
 *  - fileAttachmentHandler   — прикрепление файлов к заявкам в OkDesk
 *  - commentHandler          — комментарии к заявке
 *  - finishRequestHandler    — завершение заявки
 *  - issueCloseHandler       — ручное закрытие заявки
 *  - menuTransitionHandler   — переходы по меню и стейтам
 *  - unknownTextHandler      — неизвестный текст
 *
 * @package App\Application\Actions\Hook
 */
class ListHooksAction extends HookAction
{
    /** @var ContainerInterface DI-контейнер для ленивого получения хендлеров */
    protected ContainerInterface $container;

    protected MaxBotHelper $maxBot;
    protected StateFileHandler $stateFileHandler;
    protected MenuCreateAction $menuCreateAction;
    protected OkDeskHelper $okdeskHelper;
    protected StateManagerHandler $stateManagerHandler;
    protected QrCodeHelper $qrCodeHelperNew;

    /**
     * Состояния, в которых разрешено добавлять комментарий к заявке.
     *
     * @var array<string>
     */
    protected array $validCurrentStates = [
        State::Request_Created->value,
    ];

    /**
     * Служебные команды — трактуются как команды, а не как текстовые сообщения.
     *
     * @var array<string>
     */
    protected array $commandStates = [
        State::Main_Command->value,
        State::Create_Command->value,
        State::Close_Command->value,
        State::Delete_Command->value,
    ];

    /**
     * Тексты, которые запрещено трактовать как комментарий —
     * совпадают со стейтами, но должны обрабатываться как команды/переходы.
     *
     * @var array<string>
     */
    protected array $invalidTextStates = [
        State::Finish_Request->value,
        State::Stay->value,
        State::Main_Command->value,
        State::Create_Command->value,
        State::Close_Command->value,
        State::Start_Command->value,
    ];

    private const KEYBOARD_TYPE_INLINE = 'inline';

    public function __construct(
        LoggerInterface $logger,
        ContainerInterface $container,
        MaxBotHelper $maxBot,
        MenuCreateAction $menuCreateAction,
        StateFileHandler $stateFileHandler,
        OkDeskHelper $okdeskHelper,
        StateManagerHandler $stateManagerHandler,
        QrCodeHelper $qrCodeHelperNew,
    ) {
        parent::__construct($logger);
        $this->container = $container;
        $this->maxBot = $maxBot;
        $this->menuCreateAction = $menuCreateAction;
        $this->stateFileHandler = $stateFileHandler;
        $this->okdeskHelper = $okdeskHelper;
        $this->stateManagerHandler = $stateManagerHandler;
        $this->qrCodeHelperNew = $qrCodeHelperNew;
    }

    /**
     * Получить хендлер из DI-контейнера по строковому ключу.
     *
     * @param string $id Ключ контейнера (например, 'fileHandler', 'commandHandler')
     * @return mixed
     */
    protected function get(string $id)
    {
        return $this->container->get($id);
    }

    /**
     * Точка входа хука. Получает JSON из php://input, обрабатывает callback_query
     * отдельно, иначе — парсит файлы и передаёт всё в processMessage().
     * Все исключения ловятся и логируются, чтобы не ронять webhook.
     *
     * @return Response
     */
    protected function action(): Response
    {
        $this->logFunctionName();

        try {
            $jsonData = $this->getRequestData();

            $this->debug($jsonData);

            // Callback query от inline-кнопок — обрабатывается отдельной веткой
            if (isset($jsonData['callback_query'])) {
                $this->saveMessageId($jsonData);
                $this->processCallbackQuery($jsonData['callback_query']);
                return $this->respondWithData();
            }

            // Обычное сообщение — сначала парсим файлы через FileHandler
            $fileHandler = $this->get('fileHandler');
            $filesInfo = $fileHandler->processFileData($jsonData ?? []);

            $this->processMessage($jsonData, $filesInfo);
        } catch (\Exception $e) {
            $message = $jsonData['message'] ?? [];
            $chatId  = $message['chat']['id'] ?? null;
            $text    = $message['text'] ?? null;

            $this->logger->error('Bot error', [
                'timestamp'   => (new \DateTime('now', new \DateTimeZone('+3')))->format('Y-m-d H:i:s'),
                'user_id'     => $chatId,
                'error_type'  => get_class($e),
                'description' => $e->getMessage(),
                'last_action' => $text,
            ]);
        }

        return $this->respondWithData();
    }

    /**
     * Записать входящий JSON в debug/debug.log для отладки.
     * Формат: время, дамп данных, разделитель.
     *
     * @param mixed $message
     * @return void
     */
    function debug($message)
    {
        $log = date('[H:i:s] ') . "\n";
        $log .= print_r($message, true) . "\n";
        $log .= str_repeat('=', 50) . "\n";
        $logPath = dirname(__DIR__, 4) . '/debug/debug.log';
        $debugDir = dirname(__DIR__, 4) . '/debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777, true);
        }

        file_put_contents($logPath, $log, LOCK_EX);
    }

    /**
     * Сохранить message_id последнего сообщения в стейт пользователя.
     * Нужно, чтобы потом можно было редактировать/удалять сообщения.
     *
     * @param array $jsonData
     * @return void
     */
    private function saveMessageId(array $jsonData): void
    {
        $message = $jsonData['message'] ?? $jsonData['callback_query']['message'] ?? null;

        if (!$message) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if ($chatId && $messageId) {
            $this->stateFileHandler->setStateField((string)$chatId, 'messageId', (string)$messageId);
        }
    }

    /**
     * Обработать callback_query от inline-кнопки.
     * Достаёт текст кнопки и chatId, валидирует длину и передаёт в handleMessage().
     *
     * @param array $callbackQuery
     * @param array $fileInfo
     * @return void
     */
    private function processCallbackQuery(array $callbackQuery, array $fileInfo = []): void
    {
        $text = $callbackQuery['data'] ?? '';
        $message = $callbackQuery['message'] ?? [];
        $chatId = (string)($message['chat']['id'] ?? '');

        if (empty($text) || empty($chatId)) {
            return;
        }

        // Защита от слишком длинных callback'ов
        if (mb_strlen($text) > 4000) {
            $this->logger->warning('Callback query text exceeds maximum length', [
                'chatId' => $chatId,
                'length' => mb_strlen($text),
                'maxLength' => 4000
            ]);
            $this->sendMessage($chatId, 'Недопустимая длинна текста');
            return;
        }

        $this->handleMessage($text, $chatId, $fileInfo, $message);
    }

    /**
     * Извлечь chatId и текст из полного JSON webhook и передать в handleMessage().
     * Поддерживает несколько вариантов структуры (message, callback, attachments с кнопками).
     *
     * @param array $fullData Полный JSON webhook
     * @param array $filesInfo Файлы, уже распарсенные FileHandler
     * @return void
     */
    private function processMessage(array $fullData, array $filesInfo): void
    {
        $this->debug($fullData);

        $chatId = (string)($fullData['message']['recipient']['chat_id'] ??
            $fullData['callback']['user']['user_id'] ?? '');

        // Приоритет извлечения текста: callback → кнопка в attachment → text → caption
        $text = $fullData['callback']['payload']
            ?? $fullData['message']['body']['attachments'][0]['payload']['buttons'][0][0]['payload']
            ?? $fullData['message']['body']['text']
            ?? $fullData['message']['caption']
            ?? '';

        $this->handleMessage($text, $chatId, $filesInfo, $fullData);
    }

    /**
     * Получить JSON из php://input.
     *
     * @return array
     */
    private function getRequestData(): array
    {
        $postData = file_get_contents("php://input");
        return json_decode($postData, true) ?? [];
    }

    /**
     * Залогировать имя вызванной функции (только при DEBUG_ENV).
     *
     * @return void
     */
    private function logFunctionName(): void
    {
        if (!empty($_ENV['DEBUG_ENV'])) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $functionName = $backtrace[1]['function'] ?? __FUNCTION__;
            $this->logger->info("Function $functionName was executed.");
        }
    }

    /**
     * Главный роутер сообщений.
     *
     * Последовательно вызывает хендлеры из DI-контейнера. Первый, который вернёт true,
     * останавливает дальнейшую обработку. Порядок вызовов важен — соответствует
     * оригинальной логике ListHooksAction.
     *
     * @param string $text     Текст сообщения (или payload кнопки)
     * @param string $chatId   ID чата
     * @param array  $filesInfo Файлы из webhook
     * @param array  $message  Полный JSON webhook
     * @return void
     */
    protected function handleMessage(string $text, string $chatId, array $filesInfo, array $message = []): void
    {
        $this->logFunctionName();

        // 1. Первый запуск бота (bot_started)
        $this->get('botStartedHandler')->handle($message, $chatId);

        // Подготовка контекста
        $currentState = $this->getCurrentState($chatId);
        $text = $this->normalizeText($text, $message);
        $fileLinks = $this->getFileLinks($chatId);

        // 2. /start и /delete
        if ($this->get('commandHandler')->handleStartAndDelete($text, $chatId)) return;

        // 3. Регистрация по контакту + 4. пустое состояние
        if ($this->get('registrationHandler')->handleContactMessage($message, $chatId, $currentState)) return;
        if ($this->get('initialRegistrationHandler')->handle($currentState, $chatId)) return;

        // 5. Неподдерживаемые типы (голосовые, стикеры, пересланные и т.п.)
        if ($this->get('unsupportedTypeHandler')->handle($text, $message, $chatId, $currentState)) return;

        // 6. Файлы в контексте заявки
        if ($this->get('fileAttachmentHandler')->addFileAttachmentsToIssue($text, $chatId, $filesInfo, $message, $currentState, $this->validCurrentStates)) return;
        if ($this->get('fileAttachmentHandler')->handleTechSupportFile($text, $chatId, $filesInfo, $currentState)) return;
        if ($this->get('fileAttachmentHandler')->handleReturnToStart($chatId, $filesInfo, $currentState)) return;

        // 7. Комментарии и завершение заявки
        if ($this->get('commentHandler')->handleComment($text, $chatId, $currentState, $this->validCurrentStates, $this->invalidTextStates)) return;
        if ($this->get('finishRequestHandler')->handle($text, $chatId)) return;

        // 8. Ограничения при файлах в определённых состояниях
        if ($this->get('fileAttachmentHandler')->handleBackState($chatId, $filesInfo, $currentState)) return;
        if ($this->get('fileAttachmentHandler')->handleFileRestriction($filesInfo, $currentState, $chatId)) return;

        // 9. Служебные команды без привязки к плоттеру
        if ($this->get('commandHandler')->handleCommandIfRequestWithoutAuth($text, $currentState, $chatId)) return;

        // 10. Ручное закрытие заявки (Yes/No в Issue_AlReady_Exist)
        if ($this->get('issueCloseHandler')->handleManuallyCloseRequest($text, $currentState, $chatId)) return;

        // 11. Переходы по меню
        if ($this->get('menuTransitionHandler')->handle($text, $chatId, $currentState, $filesInfo, $fileLinks)) return;

        // 12. Создание новой заявки
        if ($this->get('menuTransitionHandler')->handleCreateNew($text, $filesInfo, $chatId)) return;

        // 13. OK в определённых состояниях
        if ($this->get('commandHandler')->handleOkForStateCommand($text, $message, $chatId, $currentState, $this->commandStates)) return;

        // 14. Команды при наличии открытой заявки
        if ($this->get('commandHandler')->handleCommandIfRequestExist($text, $currentState, $chatId)) return;

        // 15. Оставшиеся команды
        if ($this->get('commandHandler')->handleCommands($text, $chatId)) return;

        // 16. Неизвестный текст при открытой заявке
        if ($this->get('commandHandler')->handleUnknownTextIfRequestExist($text, $chatId)) return;

        // 17. Финальный fallback
        $this->processStateFromText($chatId, $text);
        $this->get('unknownTextHandler')->handle($text, $chatId);
    }

    /**
     * Получить текущий стейт пользователя из файла состояния.
     *
     * @param string $chatId
     * @return string
     */
    private function getCurrentState(string $chatId): string
    {
        $stateData = $this->stateFileHandler->getStateFromFile($chatId);
        return $stateData['state'] ?? '';
    }

    /**
     * Нормализовать текст: если есть подпись (caption), использовать её.
     *
     * @param string $text
     * @param array  $message
     * @return string
     */
    private function normalizeText(string $text, array $message): string
    {
        return $message['caption'] ?? $text;
    }

    /**
     * Получить массив ссылок на файлы из стейта пользователя.
     *
     * @param string $chatId
     * @return array
     */
    private function getFileLinks(string $chatId): array
    {
        $links = $this->stateFileHandler->getStateField($chatId, 'file_links') ?? [];
        return is_array($links) ? $links : [];
    }

    /**
     * Если текст совпадает со стейтом — перевести пользователя в этот стейт.
     * Финальный fallback, когда ни один хендлер не сработал.
     *
     * @param string $chatId
     * @param string $text
     * @return void
     */
    private function processStateFromText(string $chatId, string $text): void
    {
        $this->logFunctionName();
        foreach (State::cases() as $state) {
            if ($state->value === $text) {
                $this->stateManagerHandler->processState($chatId, $state);
                return;
            }
        }
    }

    /**
     * Отправить сообщение пользователю (или текст, или файл).
     *
     * @param string      $chatId   ID чата
     * @param string|array $message Текст или массив строк
     * @param string|null $filePath Путь к файлу (опционально)
     * @return void
     */
    protected function sendMessage(string $chatId, $message, $filePath = null): void
    {
        if ($filePath === null) {
            if (is_array($message)) {
                $message = implode("\n", $message);
            }

            $this->maxBot->sendMessage((int)$chatId, $message);
        } else {
            if (!file_exists($filePath)) {
                $this->maxBot->sendMessage((int)$chatId, "Error: File not found.");
                return;
            }
        }
    }
}