<?php

declare(strict_types=1);

namespace App\Application\Actions\QrCode;

use App\Application\Actions\Menu\MenuCreateAction;
use App\Application\Handlers\StateFileHandler;
use App\Application\Helpers\ArmHelper;
use App\Application\Helpers\MaxBotHelper;
use App\Application\Helpers\OkDeskHelper;
use App\Application\Helpers\QrCodeHelper;
use App\Application\ResponseMessage\ResponseMessage;
use App\Application\State\State;
use CURLFile;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Telegram\Bot\Api;

class ProcessQrCodeAction
{
    private QrCodeHelper $qrCodeHelper;
    private StateFileHandler $stateFileHandler;
    private ArmHelper $armHelper;
    private OkDeskHelper $okDeskHelper;
    private MaxBotHelper $maxBotHelper;
    protected MenuCreateAction $menuCreateAction;
    protected LoggerInterface $logger;
    protected string $issueTitle = "";
    protected string $issueType = "";

    public function __construct(
        QrCodeHelper $qrCodeHelper,
        StateFileHandler $stateFileHandler,
        ArmHelper $armHelper,
        OkDeskHelper $okDeskHelper,
        MaxBotHelper $maxBotHelper,
        MenuCreateAction $menuCreateAction,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->qrCodeHelper = $qrCodeHelper;
        $this->stateFileHandler = $stateFileHandler;
        $this->armHelper = $armHelper;
        $this->okDeskHelper = $okDeskHelper;
        $this->maxBotHelper = $maxBotHelper;
        $this->menuCreateAction = $menuCreateAction;
    }


    public function __invoke(Request $request, Response $response): Response
    {
        $data = json_decode((string) $request->getBody(), true);

        if (empty($data['init_data_unsafe']['user']['id']) || empty($data['qrcode_data'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid payload']));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        }
        $chatId = (string)$data['init_data_unsafe']['chat']['id'] ?? null;
        // передаём весь массив данных
        $result = $this->handleQrCode($chatId, $data);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function handleQrCode(string $chatId, array $data): array
    {

        $qrCode = $data['qrcode_data']['value'];

        // достаем номер из qrcode_data
        $plotterNumber = $this->extractPlotterNumber($qrCode);
        $this->stateFileHandler->setStateField($chatId, 'inventory_number', $plotterNumber);
        $state = $this->stateFileHandler->getStateField($chatId, "state");

        if ($plotterNumber === null && $state == State::Scan_Plotter_QR_Code->value) {
            $this->stateFileHandler->setStateField($chatId, 'state', State::Create_Request_Without_Auth->value);
            $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
                $chatId,
                State::Create_Request_Without_Auth,
                ResponseMessage::Plotter_Not_Found->value
            );
            $this->stateFileHandler->setStateField($chatId, 'plotter_not_found', 'true');
            $this->maxBotHelper->sendMenu($menu);
            return ['error' => 'Hardware number not found in QR data'];
        }

        switch ($state) {
            case State::Unlock_Cutter->value:
                $this->issueTitle = $_ENV["QR_CUT_REQUEST_TITLE"];
                $this->issueType = $_ENV["ISSUE_TYPE_QR_OFFLINE_CUT"];
                return $this->handleUnlockCutter($chatId, $qrCode, $plotterNumber);

            case State::Scan_Plotter_QR_Code->value:
                $this->issueTitle = $_ENV["ISSUE_TITLE"];
                $this->issueType = $_ENV["ISSUE_TYPE"];
                return $this->handleScanPlotterQRCode($chatId, $qrCode, $plotterNumber);

            default:
                return ['error' => "Unexpected state: $state"];
        }
    }

    /**
     * Обработка состояния Разблокировать Рез
     */
    private function handleUnlockCutter(string $chatId, string $qrCode, ?string $plotterNumber): array
    {
        $phoneNumber = $this->stateFileHandler->getStateField($chatId, 'phone_number');
        $response = $this->armHelper->processOfflineCut($phoneNumber, $qrCode);

        if ($response['status'] === 'error') {
            $this->stateFileHandler->setStateField($chatId, 'plotter_not_found', true);
            $this->handleErrorResponse($chatId, $response, $plotterNumber);

            return ['status' => 'QR processed', 'result' => 'error'];
        }

        if ($response['status'] === 'success') {
            $this->handleSuccessResponse($chatId, $response);
            return ['status' => 'QR processed', 'result' => 'success'];
        }

        return ['status' => 'QR processed', 'result' => 'unknown'];
    }

    private function handleErrorResponse(string $chatId, array $response, ?string $plotterNumber): void
    {
        $issueText = $this->buildIssueText($response);

        $this->sendMessage($chatId, ResponseMessage::Check_Not_Found->value);

        $maintenanceData = $this->extractMaintenanceData($response);
        $maintenanceId = isset($maintenanceData['id'])
            ? (string) $maintenanceData['id']
            : null;
        $this->createIssue($chatId, $plotterNumber, $issueText, $maintenanceId);
    }

    private function handleSuccessResponse(string $chatId, array $response): void
    {
        $code = $this->extractCodeFromMessage($response['message']);
        $message = str_replace('{{КОД}}', $code, ResponseMessage::Check_Found->value);

        $this->maxBotHelper->sendMessage((int)$chatId, $message);
        $this->sendMainMenu($chatId);
    }

    private function buildIssueText(array $response): string
    {
        $issueText = $response['message'] ?? 'Неизвестная ошибка';

        if (!empty($response['context'])) {
            $issueText .= "\nКонтекст: " . $response['context'];
        }

        if (!empty($response['qr'])) {
            $qrData = $this->parseQrData($response['qr']);
            $issueText .= "\nДанные из QR:\n" . $this->formatQrDataForTicket($qrData);
        }

        return $issueText;
    }

    private function extractMaintenanceData(array $response): array
    {
        if (empty($response['qr'])) {
            return [];
        }

        $qrData = $this->parseQrData($response['qr']);

        if (empty($qrData['shopCode'])) {
            return [];
        }

        return $this->okDeskHelper->getMaintenanceEntityByShopCode($qrData['shopCode']);
    }

    private function extractCodeFromMessage(string $message): string
    {
        preg_match('/\d+/', $message, $matches);
        return $matches[0] ?? '';
    }

    private function sendMainMenu(string $chatId): void
    {
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Main_Menu,
            ResponseMessage::Main_Menu->value
        );

        $this->maxBotHelper->sendMenu($menu);
    }
    /**
     * Парсинг данных из QR-кода
     */
    private function parseQrData(string $qrString): array
    {
        $parts = explode('::', $qrString);

        // Новая версия (11 параметров)
        if (count($parts) >= 11) {
            return [
                'version' => $parts[0] ?? '',      // "2" - Версия (игнорируется)
                'shopCode' => $parts[1] ?? '',     // "HFBot" - Код в партнёрской сети
                'partnerId' => $parts[2] ?? '',    // "195" - ID партнёра
                'chequeNum' => $parts[3] ?? '',    // "0142483-0987798630" - Номер чека
                'art' => $parts[4] ?? '',          // "0100-501605" - Артикул в чеке
                'task' => $parts[5] ?? '',         // "0" - Тип выбранного задания для реза
                'type' => $parts[6] ?? '',         // "7" - Тип выбранной плёнки для реза
                'color_id' => $parts[7] ?? '',     // "0" - Тип выбранной цветной плёнки для реза
                'patternId' => $parts[8] ?? '',    // "4024" - Лекало
                'orderNumber' => $parts[9] ?? '',  // "" - Номер заказа конфигуратора (может быть пустым)
                'code' => $parts[10] ?? '',        // "213715" - Код-разрешение
            ];
        }

        // Старая версия (9 параметров)
        if (count($parts) === 9) {
            return [
                'version' => '',                   // Пусто для старой версии
                'shopCode' => $parts[0] ?? '',     // "HFBot" - Код в партнёрской сети
                'partnerId' => $parts[1] ?? '',    // "195" - ID партнёра
                'chequeNum' => $parts[2] ?? '',    // "0142483-0987798630" - Номер чека
                'art' => $parts[3] ?? '',          // "0100-501605" - Артикул в чеке
                'task' => $parts[4] ?? '',         // "0" - Тип выбранного задания для реза
                'type' => $parts[5] ?? '',         // "7" - Тип выбранной плёнки для реза
                'color_id' => $parts[6] ?? '',     // "0" - Тип выбранной цветной плёнки для реза
                'patternId' => $parts[7] ?? '',    // "4024" - Лекало
                'orderNumber' => '',               // Пусто для старой версии
                'code' => $parts[8] ?? '',         // "213715" - Код-разрешение
            ];
        }

        // Если формат неизвестен - возвращаем сырую строку
        return ['raw' => $qrString];
    }
    /**
     * Форматирование данных QR для заявки
     */
    private function formatQrDataForTicket(array $qrData): string
    {
        if (empty($qrData['shopCode'])) {
            return $qrData['raw'] ?? 'Не удалось распарсить QR';
        }

        // Преобразуем тип задания в читаемый вид
        $taskTypes = [
            '0' => 'Продажа',
            '1' => 'Брак',
            '2' => 'Гарантия'
        ];

        $taskText = $taskTypes[$qrData['task']] ?? 'Неизвестно';

        return
            "Код торговой точки: " . $qrData['shopCode'] . "\n" .
            "ID партнёра: " . $qrData['partnerId'] . "\n" .
            "Номер чека: " . $qrData['chequeNum'] . "\n" .
            "Артикул: " . $qrData['art'] . "\n" .
            "Задание: " . $taskText . "\n" .
            "Тип материала: " . $qrData['type'] . "\n" .
            "Тип цветной плёнки: " . $qrData['color_id'] . "\n" .
            "ID лекала: " . $qrData['patternId'];
    }
    /**
     * Обработка состояния Сканировать qr плоттера
     */
    private function handleScanPlotterQRCode(string $chatId, string $qrCode, string $plotterNumber): array
    {
        $parsedQrCode = $this->parseQrCode($qrCode);

        if ($this->isValidPlotterNumberWithShop($parsedQrCode)) {
            return $this->createIssueRequestByPlotterNumberWithShopCode($chatId, $plotterNumber);
        }
        if ($this->isValidPlotterNumberWithoutShopCode($parsedQrCode)) {
            return $this->handlePlotterNumberWithoutShopCode($chatId, $plotterNumber, $parsedQrCode);
        }

        //невалидные данные
        return ['status' => 'error'];
    }


    private function parseQrCode(string $qrCode): array
    {
        $result = [];
        $lines = explode("\n", trim($qrCode));

        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $result[trim($key)] = trim($value);
            }
        }

        return $result;
    }

    private function isValidPlotterNumberWithShop(array $parsedQrCode): bool
    {
        return !empty($parsedQrCode['hardwareNum'] ?? null)
            && !empty($parsedQrCode['shopCode'] ?? null);
    }
    private function isValidPlotterNumberWithoutShopCode(array $parsedQrCode): bool
    {
        return !empty($parsedQrCode['hardwareNum'] ?? null)
            && empty($parsedQrCode['shopCode'] ?? null);
    }

    private function createIssueRequestByPlotterNumberWithShopCode(string $chatId, string $plotterNumber): array
    {
        //TODO: уточнить описание в заявке

        $text = "";
        $this->createIssue($chatId, $plotterNumber, $text);
        return ['status' => 'QR processed - issue created'];
    }

    private function handlePlotterNumberWithoutShopCode(string $chatId, string $hardwareNum, array $parsedQrCode): array
    {
        $this->stateFileHandler->setStateField($chatId, 'inventory_number', $hardwareNum);

        $hardwareNumber = $parsedQrCode['hardwareNum'] ?? $hardwareNum;
        $message = str_replace('{{номер}}', $hardwareNumber, ResponseMessage::Plotter_Activation_Required->value);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Expected_Plotter_Number);

        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType(
            $chatId,
            State::Create_Request_Without_Auth,
            $message
        );

        $this->maxBotHelper->sendMenu($menu);

        return ['status' => 'QR processed - activation required'];
    }

    /**
     * Создание тикета в OkDesk и отправка сообщения
     */
    private function createIssue(string $chatId, ?string $inventoryNumber, $text, ?string  $maintenanceId = null): void
    {
        if ($inventoryNumber) {
            $maintenance   = $this->okDeskHelper->getMaintenanceEntityIdByEnvetoryNumber($inventoryNumber);
            $maintenanceId = $maintenance['maintenance_entity_id'] ?? null;
        }

        $companyId = (string) $this->stateFileHandler->getStateField($chatId, 'companyId');
        $contactId = (string) $this->stateFileHandler->getStateField($chatId, 'contactId');

        //TODO: уточнить текст заявки и описание
        $this->createIssueAndSendMessage(
            $text,
            $chatId,
            $companyId,
            $contactId,
            $maintenanceId ? (string) $maintenanceId : '',
            $inventoryNumber
        );
    }


    protected function sendMessage(string $chatId, $message, $filePath = null): void
    {
        // If no file is provided, send a text message
        if ($filePath === null) {
            if (is_array($message)) {
                $message = implode("\n", $message);
            }

            $this->maxBotHelper->sendMessage((int)$chatId,$message);
        } else {
            // If a file path is provided, send it as a document
            if (!file_exists($filePath)) {
                $this->maxBotHelper->sendMessage((int)$chatId,"Error: File not found.");
                return;
            }

            // Prepare and send the document
            $url = "https://api.telegram.org/bot" . $_ENV['TELEGRAM_API_KEY'] . "/sendDocument";
            $postFields = [
                'chat_id' => $chatId,
                'caption' => is_string($message) ? $message : implode("\n", $message),
                'document' => new CURLFile(realpath($filePath))
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_exec($ch);
            curl_close($ch);
        }
    }
    private function createIssueAndSendMessage(
        string $text,
        string $chatId,
        string $companyId,
        string $contactId,
        string $maintenanceEntityId,
        ?string $inventoryNumber = null
    ): void {
        //TODO : добавить в переменные окружения заголовок и тип заявки
        $responseData = $this->okDeskHelper->createIssueRequest(
            $companyId,
            $chatId,
            (string)$contactId,
            $text,
            $this->issueTitle,
            $this->issueType,
            $maintenanceEntityId,
            $inventoryNumber
        );

        $issueId = $responseData['id'] ?? 'номер неизвестен';

        if ($issueId === 'неизвестен') {
            $errorContext = [
                'chatId' => $chatId,
                'companyId' => $companyId,
                'contactId' => $contactId,
                'responseData' => $responseData,
                'text' => $text
            ];

            $this->logger->error('Ошибка создания заявки', $errorContext);

            // Отправляем сообщение об ошибке и выходим из функции
            $this->sendMessage($chatId, ResponseMessage::Error_Occurred->value);
            return;
        }

        $plotterNotFound = $this->stateFileHandler->getStateField($chatId, 'plotter_not_found');
        $finalRessponseMessage = $plotterNotFound
            ? ResponseMessage::Request_Created_Notification->value
            : ResponseMessage::Request_Created_Notification_Plotter_Activation->value;

        $message = str_replace('{{номер}}', (string)$issueId, $finalRessponseMessage);

        $this->stateFileHandler->setStateField($chatId, 'issueId', $issueId);
        $this->stateFileHandler->setStateField($chatId, 'state', State::Request_Created->value);
        $menu = $this->menuCreateAction->createMenuLogicWithKeyboardType($chatId, State::Request_Created, $message);
        $this->maxBotHelper->sendMenu($menu);
    }

    /**
     * Извлекает hardwareNum=XXXX из строки QR
     */
    private function extractPlotterNumber(string $qrCode): ?string
    {
        if (preg_match('/hardwareNum=(\d+)/', $qrCode, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
