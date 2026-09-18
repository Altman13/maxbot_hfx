<?php

declare(strict_types=1);

namespace App\Application\Helpers;

use App\Application\Handlers\StateFileHandler;
use CURLFile;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;


class OkDeskHelper
{
    private Client      $httpClient;
    protected string    $okDeskApi;
    protected string    $okDeskIssueType;
    protected string    $okDeskIssueTitle;
    protected string    $okDeskPlotterActivationTitle;
    protected string    $okDeskCutRequestTitle;


    protected LoggerInterface $logger;
    private array $okDeskIssueTypesMap = [];

    public function __construct(LoggerInterface $logger,)
    {
        $this->logger                            =       $logger;
        $this->okDeskApi                         =       sprintf('api_token=%s', $_ENV['OKDESK_API_KEY']);

        $this->okDeskIssueType                   =       $_ENV['OKDESK_ISSUE_TYPE'] ?? 'hydroflex_new';
        $this->okDeskIssueTitle                  =       $_ENV['ISSUE_TITLE'] ?? 'Гидрофлекс (локальная разработка)';
        $this->okDeskPlotterActivationTitle      =       $_ENV['PLOTTER_ACTIVATION_TITLE'] ?? 'Активация плоттера (локальная разработка)';
        $this->okDeskCutRequestTitle             =       $_ENV['QR_CUT_REQUEST_TITLE'] ?? 'Оффлайн рез (локальная разработка)';

        $this->httpClient               = new Client([
            'base_uri' => 'https://insitech.okdesk.ru/api/v1/',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        $this->okDeskIssueTypesMap = [
            $_ENV['ISSUE_TITLE']            => 'hydroflex_new',
            $_ENV['PLOTTER_ACTIVATION_TITLE']     => 'HF_PLOTTER_ACTIVATION_TITLE',
            $_ENV['QR_CUT_REQUEST_TITLE']         => 'qrCutRequest',

        ];
    }
    public function findUserByPhone(string $phone)
    {
        $phone = $this->normalizePhoneNumber($phone);
        if (!$this->validatePhoneNumber($phone)) {
            return null;
        }
        try {
            $url = 'contacts?' . $this->okDeskApi . '&phone=' . $phone;
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $phoneNumber = json_decode($data, true);

            return $phoneNumber ?? null;
        } catch (GuzzleException $e) {
            return null;
        }
    }
    public function validatePhoneNumber(string $phone): bool
    {
        return preg_match('/^(7\d{10}|375\d{9}|374\d{8}|998\d{9})$/', $phone) === 1;
    }

    private function normalizePhoneNumber(string $phone): string
    {
        return ltrim($phone, '+');
    }
    public function getAddressByUserPhone(string $phone)
    {
        $user = $this->findUserByPhone($phone);

        if ($user && isset($user['company_id']) && !is_null($user['company_id'])) {
            return $this->getAddressByCompanyId($user['company_id']);
        }
        return false;
    }
    public function getAddressByInventoryNumber(string $inventoryNumber, int $maintenance_entity_id)
    {
        try {
            $url = $this->constructUrl($inventoryNumber, $maintenance_entity_id);
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();

            $decodedResponse = json_decode($data, true);
            if ($decodedResponse === null) {
                error_log("Failed to decode JSON: " . json_last_error_msg());
                return false;
            }

            if (is_array($decodedResponse)) {
                return $this->filterEquipmentsByInventoryNumber($decodedResponse, $inventoryNumber);
            } else {
                return $decodedResponse;
            }
        } catch (GuzzleException $e) {
            return false;
        }
    }


    private function constructUrl(string $inventoryNumber, int $maintenance_entity_id): string
    {
        if ($maintenance_entity_id && $inventoryNumber === "") {
            return 'equipments/list?' . $this->okDeskApi . '&maintenance_entity_ids[]=' . $maintenance_entity_id;
        } else {
            return 'equipments/list?' . $this->okDeskApi . '&maintenance_entity_ids[]=' . $maintenance_entity_id . '&inventory_number=' . urlencode($inventoryNumber);
        }
    }

    private function filterEquipmentsByInventoryNumber(array $equipments, string $inventoryNumber): array
    {
        $filteredEquipments = array_filter($equipments, function ($equipment) use ($inventoryNumber) {
            return isset($equipment['inventory_number']) && $equipment['inventory_number'] === $inventoryNumber;
        });

        $filteredEquipment = reset($filteredEquipments);

        if ($filteredEquipment) {
            return [
                'id' => $filteredEquipment['id'],
                'address' => $filteredEquipment['maintenance_entity']['name'],
                'maintenance_entity_id' => $filteredEquipment['maintenance_entity']['id'],
                'company_id' => $filteredEquipment['company']['id']
            ];
        }

        return [];
    }

    public function getEquipmentIdByInventoryNumber(string $inventoryNumber)
    {
        try {
            $url = 'https://insitech.okdesk.ru/api/v1/equipments/?inventory_number=' . urlencode($inventoryNumber) . '&' . $this->okDeskApi;
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            if (is_array($decodedResponse) && !empty($decodedResponse)) {
                return $decodedResponse['id'];
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            $this->logger->error('Error in getEquipmentIdByInventoryNumber method: ' . $e->getMessage());
            return false;
        }
    }



    public function getMaintenanceIdByInventoryNumber(string $inventoryNumber)
    {
        try {
            $url = 'https://insitech.okdesk.ru/api/v1/equipments/?inventory_number='
                . urlencode($inventoryNumber)
                . '&' . $this->okDeskApi;

            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            if (is_array($decodedResponse) && !empty($decodedResponse)) {
                return $decodedResponse["maintenance_entity_id"] ?? null;
            }

            return false;
        } catch (GuzzleException $e) {
            return false;
        }
    }


    public function getAddressByCompanyId(string $companyId)
    {
        try {
            $url = 'maintenance_entities/list?' . $this->okDeskApi . '&company_id=' . $companyId;
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);
            $filteredData = array_filter($decodedResponse, function ($item) use ($companyId) {
                return isset($item['company_id']) && $item['company_id'] == $companyId;
            });

            return $filteredData;
        } catch (GuzzleException $e) {
            // Log the error message if needed
            // $this->logger->error('Error in getAddressByCompanyId method: ' . $e->getMessage());
            return false;
        }
    }

    public function getDataByMaintenceName(string $id)
    {
        try {
            $url = 'maintenance_entities/' . $id . '/?' . $this->okDeskApi;
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);
            return $decodedResponse;
        } catch (GuzzleException $e) {
            // Log the error message if needed
            // $this->logger->error('Error in getAddressByCompanyId method: ' . $e->getMessage());
            return false;
        }
    }
    public function getCompanyIdByName(string $name)
    {
        try {
            $url = 'maintenance_entities/list?' . $this->okDeskApi . '&name=' . urlencode($name);
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            foreach ($decodedResponse as $item) {
                if (isset($item['name']) && $item['name'] === $name) {
                    return $item;
                }
            }

            return false;
        } catch (GuzzleException $e) {
            // Log the error message if needed
            // $this->logger->error('Error in getCompanyIdByName method: ' . $e->getMessage());
            return false;
        }
    }
    public function getMaintenanceEntityByName(string $name)
    {
        try {
            $url = 'maintenance_entities?' . $this->okDeskApi . '&search_string=' . urlencode($name);
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            if (!is_array($decodedResponse)) {
                return false;
            }

            foreach ($decodedResponse as $entity) {
                if (isset($entity['name']) && $entity['name'] === $name) {
                    return $entity; // точное совпадение
                }
            }

            return null; // если точного совпадения нет
        } catch (GuzzleException $e) {
            return false;
        }
    }

    public function getMaintenanceEntityByShopCode(string $shopCode): ?array
    {
        try {
            $url = 'maintenance_entities?' . $this->okDeskApi . '&search_string=' . urlencode($shopCode);
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            // Проверяем что получили массив и он не пустой
            if (!is_array($decodedResponse) || empty($decodedResponse)) {
                return null;
            }

            // Возвращаем первый элемент
            return $decodedResponse[0];
        } catch (GuzzleException $e) {
            // Логирование ошибки при необходимости
            // error_log('Error fetching maintenance entity: ' . $e->getMessage());
            return null;
        }
    }


    public function getMaintenanceEntityIdByEnvetoryNumber(string $enventoryNumber)
    {
        try {
            $url = 'equipments?' . $this->okDeskApi . '&inventory_number=' . urlencode($enventoryNumber);
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $decodedResponse = json_decode($data, true);

            return $decodedResponse;
        } catch (GuzzleException $e) {
            // Log the error message if needed
            // $this->logger->error('Error in getMaintenanceEntityByName method: ' . $e->getMessage());
            return false;
        }
    }
    public function getAllEquipmentsMaintenceId(int $id): array
    {
        if (!$id) {
            return [];
        }

        $url = 'equipments/list?maintenance_entity_ids[]=' . $id . '&' . $this->okDeskApi;

        try {
            $response = $this->httpClient->get($url);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to fetch equipment maintenance IDs');
            }
            $decodedResponse = json_decode($response->getBody()->getContents(), true);

            if (!empty($decodedResponse)) {
                return $decodedResponse;
            }

            return [];
        } catch (GuzzleException $e) {
            // Log the error or handle as needed
            error_log('Error in getAllEquipmentsMaintenceId: ' . $e->getMessage());
            return [];
        } catch (\Exception $e) {
            // Log the error or handle as needed
            error_log('Error in getAllEquipmentsMaintenceId: ' . $e->getMessage());
            return [];
        }
    }
    public function createNewUser(array $data, string $phone, string $first_name, string $last_name)
    {
        try {
            $url = 'contacts/?' . $this->okDeskApi;
            $postData = [
                'contact' => [
                    'first_name' => $first_name,
                    'last_name' => "Не указано",
                    'phone' => $phone,
                    'custom_parameters' => $data,
                ],
            ];
            $response = $this->httpClient->post($url, [
                'json' => $postData,
            ]);
            $decodedResponse = json_decode($response->getBody()->getContents(), true);
            return $decodedResponse ?? false;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Summary of createIssueRequest
     * @param string $companyId
     * @param string $chatId
     * @param string $contactId
     * @param string $text
     * @param mixed $maintenance_entity_id
     * @param mixed $issueType
     * @return array|bool
     */
public function createIssueRequest(
    string $companyId,
    string $chatId,
    string $contactId,
    string $text,
    ?string $issueTitle = null,
    ?string $issueType = null,
    ?string $maintenance_entity_id = null,
    ?string $inventoryNumber = null,
) {
    // Создаём уникальный ключ блокировки для этого чата
    $lockKey = 'create_issue_request_lock_' . $chatId;
    $lockFile = sys_get_temp_dir() . '/' . $lockKey . '.lock';
    
    // Пытаемся получить эксклюзивную блокировку
    $fp = fopen($lockFile, 'w');
    if (!$fp) {
        $this->logger->error('Не удалось создать файл блокировки');
        return false;
    }
    
    // Ждем освобождения блокировки до 30 секунд
    $maxWaitTime = 30; // секунд
    $startTime = time();
    $lockAcquired = false;
    
    while (time() - $startTime < $maxWaitTime) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $lockAcquired = true;
            break;
        }
        // Ждем 100мс перед следующей попыткой
        usleep(100000);
    }
    
    if (!$lockAcquired) {
        $this->logger->error('Не удалось получить блокировку за ' . $maxWaitTime . ' секунд', [
            'chatId' => $chatId
        ]);
        fclose($fp);
        return false;
    }
    
    try {
        // Проверяем, не создана ли уже заявка (если есть StateFileHandler)

        $stateFileHandler = new StateFileHandler();
        $existingIssueId = $stateFileHandler->getStateField($chatId, 'issueId');
        if ($existingIssueId) {
            $this->logger->warning('Заявка уже существует, пропускаем создание', [
                'chatId' => $chatId,
                'existingIssueId' => $existingIssueId
            ]);
            return ['id' => $existingIssueId];
        }
        
        // Получение данных обслуживания
        $maintenanceData = $this->getDataByMaintenanceId($maintenance_entity_id);
        $maintenceData = !empty($maintenance_entity_id)
            ? $this->getDataByMaintenceName($maintenance_entity_id)
            : null;
        
        $equipment_ids = isset($maintenceData['equipments_ids'])
            ? array_values((array) $maintenceData['equipments_ids'])
            : [];
        
        /*
        создает заявку с пустым номером плоттера если не указывать ТТ
        фактически заявка получается пустая только с description
        */
        
        if (!empty($inventoryNumber)) {
            // Поиск по явно переданному инвентарному номеру
            $equipmentId = $this->getEquipmentIdByInventoryNumber($inventoryNumber);
            $equipment_ids = array_values(array_filter($equipment_ids, fn($id) => $id == $equipmentId));
        } elseif (!empty($text)) {
            // Поиск по тексту только если нет inventoryNumber
            $equipmentId = $this->getEquipmentIdByInventoryNumber($text);
            $equipment_ids = $equipmentId !== false
                ? array_values(array_filter($equipment_ids, fn($id) => $id == $equipmentId))
                : $equipment_ids;
        }
        
        $postData = [
            'title'                 => $issueTitle ?? $this->okDeskIssueTitle,
            'description'           => $text,
            'equipment_ids'         => $equipment_ids,
            'type'                  => $issueType ?? $this->okDeskIssueType,
            'author'                => [
                'id'   => $contactId,
                'type' => 'contact',
            ],
            'observer_ids'          => array_column($maintenanceData['observers'] ?? [], 'id'),
            'observer_group_ids'    => array_column($maintenanceData['observer_groups'] ?? [], 'id'),
            //TODO: Заменить на переменную из env для признака бота
            'custom_parameters'     => [
                'chatId' => $chatId,
                'bot_tg_or_max' => 'max'
            ],
        ];
        
        // Передаем company_id только если он не пустая строка
        if ($companyId !== "") {
            $postData['company_id'] = $maintenanceData['company_id'] ?? $companyId;
        }
        
        if (!empty($maintenanceData['id'])) {
            $postData['maintenance_entity_id'] = (string) $maintenanceData['id'];
        }
        
        if (!empty($maintenanceData['default_assignee_id'])) {
            $postData['assignee_id'] = (string) $maintenanceData['default_assignee_id'];
        }
        
        if (!empty($maintenanceData['maintenance_entity_id'])) {
            $postData['maintenance_entity_id'] = (string) $maintenanceData['maintenance_entity_id'];
        }
        if (!empty($maintenanceData['default_assignee_group_id'])) {
            $postData['assignee_group_id'] = $maintenanceData['default_assignee_group_id'];
        }
        
        /**
         * если пользователь ввел плоттер непривязанный к ТТ 
         * и потом написал не номер плоттера, а какой-то комментарий
         */
        
        //если передали номер плоттера на активацию
        if (empty($equipment_ids) && $inventoryNumber != "") {
            $postData['description'] = ($maintenanceData['description'] ?? '') .
                " Номер плоттера: " . $inventoryNumber . " " . $text;
        }
        
        // если создали заявку на ТТ на которой находится несколько плоттеров
        if (!empty($equipment_ids)) {
            $postData['equipment_ids'] = $maintenanceData['equipments_ids'];
            $postData['description'] = "Номер плоттера в завяке: " . ($text ?: $inventoryNumber);
        }
        
        return $this->sendIssueRequest($postData);
        
    } catch (\Exception $e) {
        $this->logger->error('Error in createIssueRequest: ' . $e->getMessage());
        return false;
    } finally {
        // Всегда снимаем блокировку
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);
    }
}

    public function createIssue($text, $companyId, $chatId, $contactId, ?string $maintenance_entity_id = null)
    {
        // Создаём уникальный ключ блокировки для этого чата
        $lockKey = 'create_issue_lock_' . $chatId;
        $lockFile = sys_get_temp_dir() . '/' . $lockKey . '.lock';

        // Пытаемся получить эксклюзивную блокировку
        $fp = fopen($lockFile, 'w');
        if (!$fp) {
            $this->logger->error('Не удалось создать файл блокировки');
            return false;
        }

        // Ждем освобождения блокировки до 30 секунд
        $maxWaitTime = 30; // секунд
        $startTime = time();
        $lockAcquired = false;

        while (time() - $startTime < $maxWaitTime) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $lockAcquired = true;
                break;
            }
            // Ждем 100мс перед следующей попыткой
            usleep(100000);
        }

        if (!$lockAcquired) {
            $this->logger->error('Не удалось получить блокировку за ' . $maxWaitTime . ' секунд', [
                'chatId' => $chatId
            ]);
            fclose($fp);
            return false;
        }

        try {
            // Проверяем, не создана ли уже заявка
            $stateFileHandler = new StateFileHandler();
            $existingIssueId = $stateFileHandler->getStateField($chatId, 'issueId');

            if ($existingIssueId) {
                $this->logger->warning('Заявка уже существует, пропускаем создание', [
                    'chatId' => $chatId,
                    'existingIssueId' => $existingIssueId
                ]);
                return ['id' => $existingIssueId];
            }

            $maintenceData = $this->getDataByMaintenceName($maintenance_entity_id);
            $equipment_ids = isset($maintenceData['equipments_ids'])
                ? array_values((array)$maintenceData['equipments_ids'])
                : [];

            // Обработка состояния
            $inventory_number = $stateFileHandler->getStateField($chatId, 'inventory_number');

            // Фильтрация по инвентарному номеру
            $equipmentId = $this->getEquipmentIdByInventoryNumber((string)$inventory_number);
            if ($inventory_number) {
                $equipment_ids = array_values(array_filter($equipment_ids, fn($id) => $id == $equipmentId));
            }

            $assigneeId = $maintenceData['default_assignee_id'] ?? null;
            $assigneeGroupId = $maintenceData['default_assignee_group_id'] ?? null;
            $observerIds = array_column($maintenceData['observers'] ?? [], 'id');
            $observerGroupIds = array_column($maintenceData['observer_groups'] ?? [], 'id');

            $postData = [
                'title'                 => $this->okDeskIssueTitle,
                'description'           => $text,
                'company_id'            => $maintenceData['company_id'] ?? $companyId,
                'maintenance_entity_id' => $maintenceData['maintenance_entity_id'] ?? $maintenance_entity_id,
                'equipment_ids'         => $equipment_ids,
                'type'                  => $this->okDeskIssueType,
                'contact_id'            => $contactId,
                "author"                => [
                    "id"    => (string)$contactId,
                    "type"  => "contact"
                ],
                'observer_ids'          => $observerIds ?? [],
                'observer_group_ids'    => $observerGroupIds ?? [],
                //TODO: Заменить на переменную из env для признака бота
                'custom_parameters'     => [
                    'chatId' => $chatId,
                    'bot_tg_or_max' => 'max'
                ],
            ];

            if ($assigneeId !== null) {
                $postData['assignee_id'] = (string)$assigneeId;
            }
            if ($assigneeGroupId !== null) {
                $postData['assignee_group_id'] = $assigneeGroupId;
            }

            $url = 'issues/?' . $this->okDeskApi;
            $response = $this->httpClient->post($url, ['json' => $postData]);
            $responseBody = $response->getBody()->getContents();
            $decodedResponse = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to decode response: " . json_last_error_msg());
            }

            // Двойная проверка: убеждаемся, что заявка всё ещё не создана
            $finalCheckIssueId = $stateFileHandler->getStateField($chatId, 'issueId');
            if ($finalCheckIssueId && isset($decodedResponse['id']) && $finalCheckIssueId != $decodedResponse['id']) {
                $this->logger->warning('Заявка была создана параллельно, отменяем создание новой', [
                    'attempted_issueId' => $decodedResponse['id'],
                    'existing_issueId' => $finalCheckIssueId
                ]);
                return ['id' => $finalCheckIssueId];
            }

            return $decodedResponse;
        } catch (GuzzleException $e) {
            $this->logger->error('Error in createIssue method: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Exception in createIssue method: ' . $e->getMessage());
            return false;
        } finally {
            // Всегда снимаем блокировку
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
    }

    private function sendIssueRequest(array $postData): array|false
    {
        try {
            $url      = 'issues/?' . $this->okDeskApi;
            $response = $this->httpClient->post($url, ['json' => $postData]);
            $body     = $response->getBody()->getContents();

            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to decode response: " . json_last_error_msg());
            }

            return $decoded;
        } catch (GuzzleException $e) {
            $this->logger->error('HTTP error in sendIssueRequest: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Exception in sendIssueRequest: ' . $e->getMessage());
            return false;
        }
    }


    public function issueChangeStatus($issue, $status)
    {
        try {
            $sendData = [
                'code' => $status
            ];

            $url = 'issues/' . $issue . '/statuses?' . $this->okDeskApi;

            $response = $this->httpClient->post($url, ['json' => $sendData]);
            $responseBody = $response->getBody()->getContents();
            $decodedResponse = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to decode response: " . json_last_error_msg());
            }

            return $decodedResponse;
        } catch (GuzzleException $e) {
            // Handle Guzzle HTTP client exceptions
            // Log the error message if needed
            $this->logger->error('Error in issueChangeStatus method: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            // Handle other exceptions
            // Log the error message if needed
            $this->logger->error('Exception in issueChangeStatus method: ' . $e->getMessage());
            return false;
        }
    }
    public function rateIssue(int $issueId, string $rate)
    {
        try {
            $url = 'issues/' . $issueId . '/rates?' . $this->okDeskApi;
            $postData = [
                'issue' => [
                    'rate' => $rate,
                ],
            ];

            $response = $this->httpClient->post($url, [
                'json' => $postData,
            ]);
            $decodedResponse = json_decode($response->getBody()->getContents(), true);
            return $decodedResponse;
        } catch (GuzzleException $e) {
            return false;
        }
    }
    public function addCommentToIssue(int $issueId, string $content, int $authorId, string $authorType = 'contact', bool $public = true, array $attachments = [])
    {
        $url = 'issues/' . $issueId . '/comments?' . $this->okDeskApi;

        // Prepare the data array
        $data = [
            'comment' => [
                'content' => $content,
                'author_id' => $authorId,
                'author_type' => $authorType,
                'public' => $public,
            ],
        ];

        // Add attachments if any
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $data['comment']['attachments'][] = [
                    'attachment' => base64_encode(file_get_contents($attachment['path'])),
                    'description' => $attachment['description'] ?? null,
                    'filename' => basename($attachment['path']),
                ];
            }
        }

        try {
            // Send the request as JSON
            $response = $this->httpClient->post($url, [
                'json' => $data
            ]);

            $decodedResponse = json_decode($response->getBody()->getContents(), true);
            return $decodedResponse;
        } catch (GuzzleException $e) {
            // Handle Guzzle HTTP client exceptions
            // Log the error message if needed
            // $this->logger->error('Error in addCommentToIssue method: ' . $e->getMessage());
            return false;
        }
    }

    public function fetchIssueDetails(int $issueId): ?array
    {
        try {
            $url = 'issues/' . $issueId . '?' . $this->okDeskApi;
            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $issueDetails = json_decode($data, true);

            return $issueDetails ?? null;
        } catch (GuzzleException $e) {
            // Log or handle the error as needed
            return null;
        }
    }

    public function formatCreatedAt(string $createdAt): string
    {
        if ($createdAt) {
            $date = new DateTime($createdAt);
            return $date->format('d/m/Y');
        }
        return $createdAt;
    }

    // Функции обертки для конкретных запросов в OKdesk

    //обратиться в тп
    public function createContactSupportRequest(string $companyId, string $chatId, string $contactId, string $text, ?string $maintenance_entity_id = null)
    {
        return $this->createIssueRequest($companyId, $chatId, $contactId, $text, $this->okDeskIssueTitle, $maintenance_entity_id);
    }

    // активировать плоттер
    public function createPlotterActivationRequest(
        string $companyId,
        string $chatId,
        string $contactId,
        string $text,
        ?string $issueTitle = null,
        ?string $issueType = null,
        ?string $maintenance_entity_id = null,
        ?string $inventory_number = null
    ) {

        return $this->createIssueRequest($companyId, $chatId, $contactId, $text, $issueTitle, $issueType, $maintenance_entity_id, $inventory_number);
    }

    // разблокировать рез (оффлайн рез)
    public function createCutUnlockRequest(string $companyId, string $chatId, string $contactId, string $text, ?string $maintenance_entity_id = null)
    {
        return $this->createIssueRequest($companyId, $chatId, $contactId, $text, $this->okDeskCutRequestTitle, $maintenance_entity_id);
    }


    public function getDataByMaintenanceId(?string $maintenance_entity_id)
    {
        if (empty($maintenance_entity_id)) {
            return null;
        }
        $url = 'maintenance_entities/' . $maintenance_entity_id . '?' . $this->okDeskApi;
        try {
            $response = $this->httpClient->get($url);
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            // Log error or handle the exception as needed
            return null;
        }
    }

    public function handleFileAddition(string $text, $fileData, $currentIssue, $contactId): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://insitech.okdesk.ru/api/v1/issues/{$currentIssue}/comments/?" . $this->okDeskApi);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: multipart/form-data"
        ]);

        // Если текст пустой, добавляем дефолтный
        $text = $text !== "" ? $text : "добавлены файлы";

        $postFields = [
            'comment[content]' => $text,
            'comment[public]' => 'true',
            'comment[author_id]' => $contactId,
            'comment[author_type]' => 'contact',
        ];

        $createCurlFile = function ($filePath, $customFileName = null) {
            // Проверяем, является ли путь URL
            if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                // Скачиваем файл во временную директорию
                $tempDir = sys_get_temp_dir();
                $tempFile = $tempDir . '/' . uniqid() . '_' . ($customFileName ?? basename(parse_url($filePath, PHP_URL_PATH)));

                // Скачиваем файл
                $fileContent = @file_get_contents($filePath);
                if ($fileContent === false) {
                    throw new \Exception("Failed to download file from URL: $filePath");
                }

                // Сохраняем во временный файл
                if (file_put_contents($tempFile, $fileContent) === false) {
                    throw new \Exception("Failed to save temporary file: $tempFile");
                }

                $filePath = $tempFile;
            }

            // Проверяем существование файла
            if (!file_exists($filePath)) {
                throw new \Exception("File not found: $filePath");
            }

            $fileName = $customFileName ?? basename($filePath);
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            switch ($fileExtension) {
                case 'jpg':
                case 'jpeg':
                    $fileType = 'image/jpeg';
                    break;
                case 'png':
                    $fileType = 'image/png';
                    break;
                case 'gif':
                    $fileType = 'image/gif';
                    break;
                case 'pdf':
                    $fileType = 'application/pdf';
                    break;
                case 'doc':
                case 'docx':
                    $fileType = 'application/msword';
                    break;
                case 'txt':
                    $fileType = 'text/plain';
                    break;
                case 'mp4':
                    $fileType = 'video/mp4';
                    break;
                default:
                    $fileType = 'application/octet-stream';
                    break;
            }

            // Создаем объект CURLFile
            return new \CURLFile($filePath, $fileType, $fileName);
        };

        // Проверяем, что передан один файл или массив
        if (isset($fileData['filePath'])) {
            // Если передан одиночный файл
            $filePath = $fileData['filePath'];
            $fileName = $fileData['fileName'] ?? null;

            // Проверяем, что файл существует
            if ($filePath === '') {
                $this->logger->error("File path is empty.");
                return;
            }

            try {
                $curlFile = $createCurlFile($filePath, $fileName);
                $postFields["comment[attachments][0][attachment]"] = $curlFile;
                $postFields["comment[attachments][0][is_public]"] = 'true';

                // Если это был временный файл - удаляем его после использования
                if (isset($tempFile) && file_exists($tempFile)) {
                    register_shutdown_function(function () use ($tempFile) {
                        @unlink($tempFile);
                    });
                }
            } catch (\Exception $e) {
                $this->logger->error("Error creating CURLFile: " . $e->getMessage());
                return;
            }
        } elseif (is_array($fileData) && !empty($fileData)) {
            // Если передан массив ссылок на файлы
            $tempFiles = [];
            foreach ($fileData as $index => $filePath) {
                // Проверяем, что файл существует
                if (empty($filePath)) {
                    $this->logger->error("File path is empty at index $index.");
                    continue;
                }

                try {
                    $curlFile = $createCurlFile($filePath);
                    $postFields["comment[attachments][$index][attachment]"] = $curlFile;
                    $postFields["comment[attachments][$index][is_public]"] = 'true';
                } catch (\Exception $e) {
                    $this->logger->error("Error creating CURLFile at index $index: " . $e->getMessage());
                    continue;
                }
            }
        } else {
            $this->logger->error("No valid file data provided.");
            return;
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            $this->logger->error("Curl error: $error");
        } else {
            $this->logger->info("File added successfully to issue $currentIssue");

            // Очищаем временные файлы
            if (isset($tempFiles)) {
                foreach ($tempFiles as $tempFile) {
                    @unlink($tempFile);
                }
            }
        }
    }

    /**
     * Получить временный URL для вложения из Okdesk.
     *
     * @param string $issueId
     * @param string $attachmentId
     * @return string|null
     */
    public function getOkdeskAttachmentUrl(string $issueId, string $attachmentId): ?string
    {
        $okdeskUrl = sprintf('issues/%s/attachments/%s?%s', $issueId, $attachmentId, $this->okDeskApi);

        try {
            $response = $this->httpClient->get($okdeskUrl);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['attachment_url'])) {
                return $data['attachment_url'];
            }

            return null;
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to get attachment URL from Okdesk: " . $e->getMessage());
            return null;
        }
    }
}
