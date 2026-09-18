<?php

declare(strict_types=1);

namespace App\Application\Actions\OkDeskCallBack;

use Exception;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Application\State\State;
use Max\Bot\FileUpload\InputFile;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Handlers\StateFileHandler;
use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\ResponseMessage\ResponseMessage;
use Psr\Http\Message\ResponseInterface as Response;
use App\Application\Helpers\MaxBotHelper;

class ListOkDeskCallBackAction extends OkDeskCallBackAction
{
    protected const MAX_MESSAGE_LENGTH = 2000;
    protected const CONTACT_TYPE = 'contact';

    protected MaxBotHelper $maxBotHelper;
    protected StateFileHandler $stateFileHandler;
    protected MenuCreateAction $menuCreateAction;
    protected OkDeskHelper $okdeskHelper;


    public function __construct(
        LoggerInterface $logger,
        MaxBotHelper $maxBotHelper,
        MenuCreateAction $menuCreateAction,
        StateFileHandler $stateFileHandler,
        OkDeskHelper $okdeskHelper,
    ) {
        parent::__construct($logger);
        $this->maxBotHelper = $maxBotHelper;
        $this->menuCreateAction = $menuCreateAction;
        $this->stateFileHandler = $stateFileHandler;
        $this->okdeskHelper = $okdeskHelper;
    }

    /**
     * {@inheritdoc}
     */
    protected function action(): Response
    {
        try {
            $jsonData = $this->getRequestBodyAsArray();
            $this->sendMessage($jsonData);
        } catch (Exception $e) {
            $this->logger->error('Ошибка в методе action: ' . $e->getMessage());
        }
        return $this->respondWithData();
    }

    /**
     * Получить тело запроса в виде ассоциативного массива.
     *
     * @return array
     */
    private function getRequestBodyAsArray(): array
    {
        $postData = file_get_contents("php://input");
        $jsonData = json_decode($postData, true);

        return is_array($jsonData) ? $jsonData : [];
    }

    /**
     * Отправить сообщение и прикрепленный файл, если он существует.
     *
     * @param array $data
     * @return void
     */
    protected function sendMessage(array $data): void
    {
        $chatId = $this->extractChatId($data);

        if (!$chatId) {
            return;
        }

        $currentIssue = (int)$this->stateFileHandler->getStateField($chatId, 'issueId');
        $dataIssue = $data['issue']['id'] ?? null;

        /*
            если сообщения не по текущей заявке ранний выход - нужно добавить во все чатботы для отработки только своих заявок
            если пользователь работал с несколькими ботами, то от одкеск приходит ответ во все боты где есть его chatid
        */
        if (isset($currentIssue) && $dataIssue !== $currentIssue) return;

        $this->debug($data);

        // Проверяем, что комментарий публичный
        if (isset($data['event']['comment']['is_public']) && $data['event']['comment']['is_public'] === false) {
            return; // Если комментарий не публичный, не отправляем сообщение
        }

        // Проверка наличие файла не в публичном комментарии
        if (isset($data['event']['attachments'][0]['is_public']) && $data['event']['attachments'][0]['is_public'] === false) {
            return; // Если файл находится не в публичном комментарии не отправляем его
        }
        $isResolved = isset($data['event']['new_status']['name']) && $data['event']['new_status']['name'] === 'Решено';

        $isRejected = isset($data['event']['new_status']['name']) && $data['event']['new_status']['name'] === 'Рез отклонен';

        //если заявка была  на паузе
        if (
            isset($data['event']['old_status']['code']) && $data['event']['old_status']['code'] === 'In_progress_tp1_pause'
            && isset($data['event']['new_status']['name']) && $data['event']['new_status']['name'] === 'В работе (ТП-1)'
        ) {

            $this->sendMaxMessage($chatId, ResponseMessage::Return_To_Request->value);
            return;
        }

        if (isset($data['event']['new_status']['name']) && $data['event']['new_status']['name'] === 'В работе (ТП-1)') {
            $this->sendMaxMessage($chatId, ResponseMessage::Request_In_Progress->value);
            return;
        }




        if ((isset($data['issue']['status']['name']) && $data['issue']['status']['name'] === 'В работе (ТП1)') &&
            isset($data['event']['comment']['content']) &&
            $data['event']['comment']['content'] !== 'Здравствуйте! Специалист технической поддержки подключился к чату'
            && $data['event']['author']['type'] !== 'contact'
        ) {

            $this->sendMaxMessage($chatId, $data['event']['comment']['content']);
            return;
        }

        //file_put_contents('debug_data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (
            isset($data['issue']['status']['name']) && $data['issue']['status']['name'] === 'В работе (ТП1)' && $data['event']['author']['type'] !== 'contact'
            && empty($data['event']['attachments'][0])
        ) {

            $this->sendMaxMessage($chatId, ResponseMessage::Request_In_Progress->value);
            return;
        }


        if (isset($data['event']['comment']['content']) && $data['event']['comment']['content'] === 'Здравствуйте! Специалист технической поддержки подключился к чату') {
            return;
        }


        $issueDetails = $this->okdeskHelper->fetchIssueDetails($currentIssue);

        $issueNumber = $issueDetails['id'] ?? $currentIssue;
        $issueDate = $this->okdeskHelper->formatCreatedAt($issueDetails['created_at'] ?? '');

        $responseMessage =  str_replace(
            ['{{номер}}', '{{дата}}'],
            [$issueNumber, $issueDate],
            ResponseMessage::Manually_Closing_Request->value
        );

        if (
            $chatId &&
            $this->isIssueCreated($chatId) &&
            $isRejected
        ) {
            // Отправляем сообщение об отклонении заявки
            $rejectionMessage = "Заявка №{$issueNumber} отклонена.\n\n";

            $this->maxBotHelper->sendMessage((int)$chatId, $rejectionMessage);

            // Сбрасываем состояние заявки
            $this->stateFileHandler->clearStateKey($chatId, 'issueId');
            $this->stateFileHandler->clearStateKey($chatId, 'lastComment');
            $this->stateFileHandler->clearStateKey($chatId, 'attachmentFileName');

            // Возвращаем пользователя в главное меню
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType($chatId, State::Main_Menu, ResponseMessage::Main_Menu->value);
            $this->maxBotHelper->sendMenu($menu);

            return;
        }

        // сообщение пользователю о закрытии заявки ТП
        if (
            $chatId &&
            $this->isIssueCreated($chatId) &&
            $isResolved
        ) {

            $this->maxBotHelper->sendMessage((int)$chatId, $responseMessage);

            //если заявка закрыта сотрудником ТП скинули меню на главное

            $this->stateFileHandler->setStateField($chatId, 'state', State::Finish_Request->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType($chatId, State::Main_Menu, ResponseMessage::Main_Menu->value);
            $this->maxBotHelper->sendMenu($menu);

            $this->stateFileHandler->clearStateKey($chatId, 'issueId');
            $this->stateFileHandler->clearStateKey($chatId, 'lastComment');
            $this->stateFileHandler->clearStateKey($chatId, 'attachmentFileName');
            return;
        }

        if (!$chatId || !$this->isIssueCreated($chatId) || $this->isCommentFromContact($data)) {
            return;
        }


        // Обработка сообщения
        $message = $this->extractMessage($data);
        if ($message) {
            // Заменяем теги <br> на пробелы
            $message = str_replace('<br>', ' ', $message);
            $message = str_replace('<br/>', ' ', $message);
            $message = str_replace('<br />', ' ', $message);
            $message = str_replace('&nbsp;', '', $message);
            $message = strip_tags($message, '<img>'); // Оставляем только теги <img>

            $pattern = '/<img\s+src="([^"]+)"[^>]*>|([^<]+)/is';

            // Пытаемся найти изображения и текст
            if (preg_match_all($pattern, $message, $matches, PREG_SET_ORDER)) {
                $text = '';

                foreach ($matches as $match) {
                    if (!empty($match[1])) {
                        // Найдено изображение
                        if (!empty(trim($text))) {
                            $this->sendMaxMessage($chatId, strip_tags($text));
                            $text = '';
                        }
                        $this->sendMaxPhoto($chatId, $match[1], '');
                    } elseif (!empty($match[2])) {
                        // Найден текст
                        $text .= ' ' . trim($match[2]);
                    }
                }

                // Отправить оставшийся текст после всех изображений
                if (!empty(trim($text))) {
                    $this->sendMaxMessage($chatId, strip_tags($text));
                }
            }
        }

        // Получаем текущий счетчик из состояния, если он есть
        $attachmentProcessCounter = $this->stateFileHandler->getStateField($chatId, 'attachmentProcessCounter') ?? 0;

        if ($this->hasAttachments($data)) {
            $attachmentCount = count($data['event']['attachments']); // Количество файлов в данных

            // Если файлов один, обрабатываем сразу
            if ($attachmentCount === 1) {
                $this->processAttachments($chatId, $data);

                // Сбрасываем счетчик в состояние после обработки
                $this->stateFileHandler->setStateField($chatId, 'attachmentProcessCounter', 0);
                return;
            }

            // Если файлов больше одного, увеличиваем счетчик
            $attachmentProcessCounter++;

            // Если файлы уже были обработаны (счетчик больше 1), начинаем обработку
            if ($attachmentProcessCounter > 1) {
                $this->processAttachments($chatId, $data);

                // Сбрасываем счетчик в состояние после обработки
                $this->stateFileHandler->setStateField($chatId, 'attachmentProcessCounter', 0);
            } else {
                // Сохраняем новый счетчик в состояние для будущих вызовов
                $this->stateFileHandler->setStateField($chatId, 'attachmentProcessCounter', $attachmentProcessCounter);
            }
        }
    }

    /**
     * Отправка файла через MAX бот.
     *
     * @param string $chatId
     * @param string $issueId
     * @param string $attachmentId
     * @param string $attachmentFileName
     * @return void
     */
    private function sendMaxFile(string $chatId, string $issueId, string $attachmentId, string $attachmentFileName): void
    {
        $tempFile = null;
        $attachmentUrl = null;

        try {
            // 1. ПОЛУЧАЕМ ССЫЛКУ
            $this->logger->info("=== НАЧАЛО ОБРАБОТКИ ФАЙЛА ===");
            $this->logger->info("ChatId: $chatId");
            $this->logger->info("IssueId: $issueId");
            $this->logger->info("AttachmentId: $attachmentId");
            $this->logger->info("FileName: $attachmentFileName");

            $attachmentUrl = $this->okdeskHelper->getOkdeskAttachmentUrl($issueId, $attachmentId);
            $this->logger->info("Получен URL от OkDesk: " . ($attachmentUrl ?: 'NULL'));

            if (!$attachmentUrl) {
                throw new Exception("Не удалось получить URL для attachment ID: $attachmentId");
            }

            // 2. ПРОВЕРЯЕМ ДОСТУПНОСТЬ URL
            $this->logger->info("Проверяем доступность URL...");

            $ch = curl_init($attachmentUrl);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->logger->info("HTTP код проверки: $httpCode");

            if ($httpCode >= 400) {
                throw new Exception("URL недоступен (HTTP $httpCode)");
            }

            // 3. СОЗДАЕМ ВРЕМЕННЫЙ ФАЙЛ
            $tempFile = tempnam(sys_get_temp_dir(), 'okdesk_');
            $this->logger->info("Временный файл создан: $tempFile");

            // 4. СКАЧИВАЕМ ФАЙЛ
            $this->logger->info("Начинаем скачивание файла...");

            $ch = curl_init($attachmentUrl);
            $fp = fopen($tempFile, 'w');

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_FAILONERROR => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $sizeDownload = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

            curl_close($ch);
            fclose($fp);

            $this->logger->info("Результаты скачивания:");
            $this->logger->info("- Успех: " . ($success ? 'Да' : 'Нет'));
            $this->logger->info("- HTTP код: $httpCode");
            $this->logger->info("- Ошибка: " . ($error ?: 'Нет'));

            if (!$success || $httpCode >= 400) {
                throw new Exception("Ошибка скачивания: $error (HTTP код: $httpCode)");
            }

            // Проверяем размер скачанного файла
            clearstatcache(true, $tempFile);
            $fileSize = filesize($tempFile);
            $this->logger->info("Размер временного файла: $fileSize байт");

            if ($fileSize === 0) {
                throw new Exception("Скачанный файл пуст (0 байт)");
            }

            $this->logger->info("Файл успешно скачан");

            // 5. ОПРЕДЕЛЯЕМ ТИП ФАЙЛА
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);
            $this->logger->info("MIME-тип файла: $mimeType");

            $extension = $this->getFileExtension($tempFile, $attachmentFileName);
            $this->logger->info("Определено расширение: $extension");

            $finalTempFile = $tempFile . '.' . $extension;
            rename($tempFile, $finalTempFile);
            $tempFile = $finalTempFile;

            // 6. ОПРЕДЕЛЯЕМ ТИП ДЛЯ MAX API
            $fileType = $this->determineFileType($attachmentFileName, $tempFile);
            $this->logger->info("Тип для MAX API: $fileType");

            // 7. ПЫТАЕМСЯ ОТПРАВИТЬ В MAX
            $caption = "📎 {$attachmentFileName}";
            $this->logger->info("Отправляем файл в MAX...");

            $sendSuccess = false;

            try {
                if ($fileType === 'image') {
                    $result = $this->maxBotHelper->uploadAndSendPhoto((int)$chatId, $tempFile, $caption);
                } else {
                    $result = $this->maxBotHelper->uploadAndSendFile((int)$chatId, $tempFile, $fileType, $caption);
                }

                if (!empty($result)) {
                    $sendSuccess = true;
                    $this->logger->info("Файл успешно отправлен в MAX. Ответ: " . json_encode($result, JSON_UNESCAPED_UNICODE));
                }
            } catch (Exception $e) {
                $this->logger->warning("Не удалось отправить файл напрямую: " . $e->getMessage());
                $sendSuccess = false;
            }

            // 8. ЕСЛИ НЕ УДАЛОСЬ ОТПРАВИТЬ, ОТПРАВЛЯЕМ ССЫЛКУ
            if (!$sendSuccess) {
                $this->logger->info("Отправляем ссылку на файл как запасной вариант");

                $fileSizeMB = round($fileSize / 1024 / 1024, 2);
                $fileExtension = strtoupper(pathinfo($attachmentFileName, PATHINFO_EXTENSION));

                $message = "❌ Не удалось загрузить файл напрямую в чат.\n\n"
                    . "📎 **Файл:** {$attachmentFileName}\n"
                    . "📦 **Тип:** " . ($fileExtension ?: "Неизвестный") . "\n"
                    . "📊 **Размер:** {$fileSizeMB} MB\n\n"
                    . "🔗 **Ссылка для скачивания:**\n{$attachmentUrl}\n\n"
                    . "⚠️ Ссылка действительна ограниченное время (около 30 секунд).\n"
                    . "Пожалуйста, скачайте файл как можно скорее.";

                $this->maxBotHelper->sendMessage((int)$chatId, $message);
                $this->logger->info("Отправлена ссылка на файл из OkDesk");
            }

            $this->logger->info("=== ОБРАБОТКА ФАЙЛА ЗАВЕРШЕНА УСПЕШНО ===");
        } catch (Exception $e) {
            $this->logger->error("=== ОШИБКА ПРИ ОБРАБОТКЕ ФАЙЛА ===");
            $this->logger->error("Сообщение: " . $e->getMessage());

            // ВСЕГДА отправляем ссылку при ошибке, если URL получен
            if ($attachmentUrl) {
                try {
                    $fileSizeMB = isset($fileSize) ? round($fileSize / 1024 / 1024, 2) : 'неизвестно';

                    $message = "❌ Не удалось загрузить файл \"{$attachmentFileName}\".\n\n"
                        . "📎 **Файл:** {$attachmentFileName}\n"
                        . "📊 **Размер:** {$fileSizeMB} MB\n\n"
                        . "🔗 **Ссылка для скачивания:**\n{$attachmentUrl}\n\n"
                        . "⚠️ Ссылка действительна ограниченное время (около 30 секунд).\n"
                        . "Пожалуйста, скачайте файл как можно скорее.";

                    $this->maxBotHelper->sendMessage((int)$chatId, $message);
                    $this->logger->info("Отправлена ссылка на файл из OkDesk (после ошибки)");
                } catch (Exception $msgError) {
                    $this->logger->error('Не удалось отправить ссылку: ' . $msgError->getMessage());
                }
            } else {
                // Если даже URL не получили, отправляем общее сообщение
                try {
                    $this->maxBotHelper->sendMessage(
                        (int)$chatId,
                        "❌ Не удалось получить доступ к файлу \"{$attachmentFileName}\".\n\n"
                            . "Пожалуйста, попросите оператора прислать файл заново."
                    );
                } catch (Exception $msgError) {
                    $this->logger->error('Не удалось отправить сообщение об ошибке: ' . $msgError->getMessage());
                }
            }

            $this->logger->error("=== ОБРАБОТКА ФАЙЛА ЗАВЕРШЕНА С ОШИБКОЙ ===");
        } finally {
            // Удаляем временный файл
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
                $this->logger->info("Временный файл удален: $tempFile");
            }
        }
    }

    private function getFileExtension(string $filePath, string $originalFileName): string
    {
        // Сначала пробуем из оригинального имени
        $ext = pathinfo($originalFileName, PATHINFO_EXTENSION);
        if (!empty($ext)) {
            return $ext;
        }

        // Если нет - определяем по MIME-типу
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $this->getExtensionFromMimeType($mimeType);
    }

    /**
     * Расширенная карта MIME-типов
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/x-tar' => 'tar',
            'application/gzip' => 'gz',
            'video/mp4' => 'mp4',
            'video/x-msvideo' => 'avi',
            'video/quicktime' => 'mov',
            'video/x-ms-wmv' => 'wmv',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
        ];

        return $mimeMap[$mimeType] ?? 'bin';
    }

    /**
     * Определяет тип файла для MAX API
     */
    private function determineFileType(string $fileName, string $filePath): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // Расширенный список типов
        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];
        $videoExt = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg'];
        $audioExt = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];

        if (in_array($extension, $imageExt)) return 'image';
        if (in_array($extension, $videoExt)) return 'video';
        if (in_array($extension, $audioExt)) return 'audio';

        // Проверяем MIME-тип
        if (file_exists($filePath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);

            if (strpos($mimeType, 'image/') === 0) return 'image';
            if (strpos($mimeType, 'video/') === 0) return 'video';
            if (strpos($mimeType, 'audio/') === 0) return 'audio';
        }

        return 'file';
    }
    /**
     * Проверка, создана ли заявка.
     *
     * @param string $chatId
     * @return bool
     */
    private function isIssueCreated(string $chatId): bool
    {
        return !empty($this->stateFileHandler->getStateField($chatId, 'issueId'));
    }

    /**
     * Проверка, является ли комментарий от контакта.
     *
     * @param array $data
     * @return bool
     */
    private function isCommentFromContact(array $data): bool
    {
        return isset($data['event']['author']['type']) && $data['event']['author']['type'] === self::CONTACT_TYPE;
    }


    /**
     * Обработка и отправка сообщения.
     *
     * @param string $chatId
     * @param string $message
     * @return string
     */
    private function processMessage(string $chatId, string $message): string
    {
        // Получаем последнее сообщение, если оно существует
        $lastComment = $this->stateFileHandler->getStateField($chatId, 'lastComment');

        if (empty($lastComment)) {
            // Устанавливаем новое сообщение как lastComment
            $this->stateFileHandler->setStateField($chatId, 'lastComment', trim($message));
            return $message;  // Возвращаем сообщение, если оно новое
        }

        if ($lastComment !== trim($message)) {
            $this->stateFileHandler->setStateField($chatId, 'lastComment', trim($message));
            return $message;
        }
        return '';
    }



    /**
     * Извлечь ID чата из данных.
     *
     * @param array $data
     * @return string|null
     */
    private function extractChatId(array $data): ?string
    {
        if (isset($data['issue']['parameters']) && is_array($data['issue']['parameters'])) {
            foreach ($data['issue']['parameters'] as $parameter) {
                if (($parameter['code'] ?? '') === 'chatId') {
                    return $parameter['value'] ?? null;
                }
            }
        }
        return null;
    }

    /**
     * Извлечь содержание сообщения из предоставленных данных.
     *
     * @param array $data
     * @return string|null
     */
    private function extractMessage(array $data): ?string
    {
        return $data['event']['comment']['content'] ?? null;
    }

    /**
     * Преобразовать строковое значение состояния в экземпляр enum State.
     *
     * @param string $stateValue
     * @return State
     */
    public function getStateEnumFromString(string $stateValue): State
    {
        foreach (State::cases() as $state) {
            if ($state->value === $stateValue) {
                return $state;
            }
        }

        throw new InvalidArgumentException("Невалидное значение состояния: $stateValue");
    }

    /**
     * Отправка сообщения через Max.
     *
     * @param string $chatId
     * @param string $message
     * @return void
     */
    private function sendMaxMessage(string $chatId, string $message): void
    {
        $message = $this->processMessage($chatId, $message);
        if ($message != "") {
            $maxLength = 3999;

            // Split message if it's longer than maxLength
            if (mb_strlen($message) > $maxLength) {
                $parts = mb_str_split($message, $maxLength);
                foreach ($parts as $part) {
                    $this->maxBotHelper->sendMessage((int)$chatId, $part);
                }
            } else {
                $this->maxBotHelper->sendMessage((int)$chatId, $message);
            }
        }
    }
    /**
     * Отправка изображения через MAX бот.
     *
     * @param string $chatId
     * @param string $imageUrl
     * @return void
     */
    private function sendMaxPhoto(string $chatId, string $imageUrl): void
    {
        $tempFile = null;

        try {
            // Создаем временный файл
            $tempFile = tempnam(sys_get_temp_dir(), 'img_');

            // Скачиваем изображение
            $imageContent = file_get_contents($imageUrl);
            if ($imageContent === false) {
                throw new Exception("Не удалось скачать изображение по URL: $imageUrl");
            }

            file_put_contents($tempFile, $imageContent);

            // Определяем расширение файла по MIME-типу
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempFile);
            finfo_close($finfo);

            $extension = $this->getExtensionFromMimeType($mimeType);

            // Переименовываем файл с правильным расширением
            $newTempFile = $tempFile . '.' . $extension;
            rename($tempFile, $newTempFile);
            $tempFile = $newTempFile;

            // Используем существующий метод uploadAndSendPhoto из MaxBotHelper
            $this->maxBotHelper->uploadAndSendPhoto((int)$chatId, $tempFile);
        } catch (Exception $e) {
            $this->logger->error('Ошибка отправки изображения в MAX: ' . $e->getMessage());

            // В случае ошибки отправляем сообщение об ошибке
            try {
                $this->maxBotHelper->sendMessage(
                    (int)$chatId,
                    "❌ Не удалось загрузить изображение. Пожалуйста, попросите оператора прислать файл заново."
                );
            } catch (Exception $fallbackError) {
                $this->logger->error('Ошибка отправки сообщения об ошибке: ' . $fallbackError->getMessage());
            }
        } finally {
            // Удаляем временный файл
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Обработка и отправка вложений.
     *
     * @param string $chatId
     * @param array $data
     * @return void
     */
    private function processAttachments(string $chatId, array $data): void
    {
        foreach ($data['event']['attachments'] as $attachment) {
            if (!empty($attachment['id'])) {
                $attachmentId = (string)$attachment['id'];
                $issueId = (string)$data['issue']['id'];

                // Получаем имя последнего отправленного файла из состояния
                $lastAttachmentFileName = $this->stateFileHandler->getStateField($chatId, 'lastAttachmentFileName');

                // Проверка на отправку файла единожды
                if ($lastAttachmentFileName != $attachment['attachment_file_name']) {
                    $this->stateFileHandler->setStateField($chatId, 'lastAttachmentFileName', $attachment['attachment_file_name']);
                    $this->sendMaxFile($chatId, $issueId, $attachmentId, $attachment['attachment_file_name']);
                }
            }
        }
    }

    /**
     * Проверяет, есть ли вложения в данных.
     *
     * @param array $data
     * @return bool
     */
    private function hasAttachments(array $data): bool
    {
        return !empty($data['event']['attachments']) && is_array($data['event']['attachments']);
    }

    function debug(array $message)
    {
        $log = date('[H:i:s] ') . "\n";
        $log .= print_r($message, true) . "\n";
        $log .= str_repeat('=', 50) . "\n";
        $logPath = dirname(__DIR__, 4) . '/debug/callback_debug.log';
        $debugDir = dirname(__DIR__, 4) . '/debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777, true);
        }

        file_put_contents($logPath, $log, LOCK_EX);
    }
}
