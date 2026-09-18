<?php

declare(strict_types=1);

namespace App\Application\Helpers;

use App\Application\ResponseMessage\ResponseMessage;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\GuzzleException;



class ArmHelper
{
    private Client $httpClient;
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->httpClient = new Client([
            'base_uri' => 'url',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $_ENV['ARM_API_KEY'],
            ],
        ]);
    }

    public function findWorkerByPhone(string $phone): bool
    {
        $phone = $this->normalizePhoneNumber($phone);

        // Return false if phone number validation fails
        if (!$this->validatePhoneNumber($phone)) {
            return false;
        }

        try {
            $url = 'v1/helpdesk/worker';

            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $workers = json_decode($data, true);

            if (isset($workers['list']) && is_array($workers['list'])) {
                foreach ($workers['list'] as $worker) {
                    if (
                        isset($worker['phoneEmployee']) &&
                        $this->normalizePhoneNumber($worker['phoneEmployee']) === $phone
                    ) {
                        return true;
                    }
                }
            }

            return false;
        } catch (GuzzleException $e) {
            $this->logger->error('Error in findWorkerByPhone method: ' . $e->getMessage());
            return false;
        }
    }

    public function getAllWorkers(): array
    {
        try {
            $url = 'v2/helpdesk/worker';

            $response = $this->httpClient->get($url);
            $data = $response->getBody()->getContents();
            $workers = json_decode($data, true);

            if (isset($workers['list']) && is_array($workers['list'])) {
                return $workers['list'];
            }

            return [];
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->logger->error('Error in getAllWorkers: ' . $e->getMessage());
            return [];
        }
    }

    private function validatePhoneNumber(string $phone): bool
    {
        return preg_match('/^(7\d{10}|375\d{9}|374\d{8}|998\d{9})$/', $phone) === 1;
    }

    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        return preg_replace('/\D/', '', $phone);
    }

    public function getWorkerAccessData(string $objectName): string
    {
        try {
            $workerData = $this->fetchWorkerDataByObjectName($objectName);

            if (isset($workerData['objectAnswerableName']) && !empty($workerData['objectAnswerableName'])) {
                $nameParts = $this->splitFullName($workerData['objectAnswerableName']);
            } else {
                return json_encode([
                    'status' => 'error',
                    'message' => 'Имя сотрудника не указано',
                ]);
            }

            $filteredWorker = $this->findWorkerByName($nameParts);

            if ($filteredWorker) {
                return json_encode([
                    'status' => 'success',
                    'firstName' => $filteredWorker['firstName'],
                    'secondName' => $filteredWorker['secondName'],
                    'thirdName' => $filteredWorker['thirdName'],
                    'phoneEmployee' => $filteredWorker['phoneEmployee'] ?? '',
                    'emailEmployee' => $filteredWorker['emailEmployee'] ?? '',
                ], JSON_UNESCAPED_UNICODE);
            }

            return json_encode([
                'status' => 'error',
                'message' => 'Сотрудник не найден',
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Ошибка в методе getWorkerAccessData: ' . $e->getMessage());
            return json_encode([
                'status' => 'error',
                'message' => 'Не удалось получить данные с API: ' . $e->getMessage(),
            ]);
        }
    }

    private function fetchWorkerDataByObjectName(string $objectName): array
    {
        $url = 'v1/helpdesk/shop/index';
        $requestData = ['objectName' => $objectName];
        //TODO: дописать переменные в env
        $headers = [
            'Authorization' => 'Bearer ' . $_ENV['ADMIN_API_KEY'] ?? ''
        ];

        $response = $this->httpClient->post($url, [
            'headers' => $headers,
            'json' => $requestData,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['list']) || empty($data['list'])) {
            return [
                'status' => 'error',
                'message' => 'Нет данных в ответе от API',
            ];
        }

        return $data['list'][0] ?? [];
    }

    private function splitFullName(string $fullName): array
    {
        $nameParts = explode(' ', $fullName);
        return [
            'secondName' => trim($nameParts[0] ?? ''),
            'firstName' => trim($nameParts[1] ?? ''),
            'thirdName' => trim($nameParts[2] ?? ''),
        ];
    }

    private function findWorkerByName(array $nameParts): ?array
    {
        $workerUrl = 'v1/helpdesk/worker/';
        $workerResponse = $this->httpClient->get($workerUrl);
        $workerDataList = json_decode($workerResponse->getBody()->getContents(), true)['list'] ?? [];

        foreach ($workerDataList as $worker) {
            if (
                trim($worker['firstName']) === $nameParts['firstName'] &&
                trim($worker['secondName']) === $nameParts['secondName'] &&
                trim($worker['thirdName']) === $nameParts['thirdName']
            ) {
                return $worker;
            }
        }

        return null;
    }
        public function processOfflineCut(string $telephone, string $qr): array
    {
        try {
            $url = 'v1/helpdesk/checks/offline';

            $requestData = [
                'telephone' => $telephone,
                'qr' => $qr,
            ];

            $response = $this->httpClient->post($url, [
                'json' => $requestData,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() === 200 && isset($responseData['Result']) && $responseData['Result'] === 'Ok') {
                return [
                    'status' => 'success',
                    'message' => $responseData['Message'] ?? 'Offline cut processed successfully.',
                    'context' => $responseData['Context'] ?? null,
                    'qr' => $responseData['QR'] ?? null,
                ];
            }

            if ($response->getStatusCode() === 400) {
                return [
                    'status' => 'error',
                    'message' => $responseData['Message'] ?? 'Invalid parameters. Please check telephone or QR code.',
                    'context' => $responseData['Context'] ?? null,
                    'qr' => $responseData['QR'] ?? null,
                ];
            }

            if ($response->getStatusCode() === 500) {
                return [
                    'status' => 'error',
                    'message' => 'Internal Server Error: ' . ($responseData['Message'] ?? 'Failed to process offline cut.'),
                    'context' => $responseData['Context'] ?? null,
                    'qr' => $responseData['QR'] ?? null,
                ];
            }

            return [
                'status' => 'error',
                'message' => $responseData['Message'] ?? null,
                'context' => $responseData['Context'] ?? null,
                'qr' => $responseData['QR'] ?? null,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('Error in processOfflineCut method: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Failed to process offline cut: ' . $e->getMessage(),
                'context' => null,
            ];
        }
    }
}
