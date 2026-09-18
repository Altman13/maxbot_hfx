<?php

declare(strict_types=1);

namespace App\Application\Handlers;

use App\Application\State\State;

class StateFileHandler
{
    protected string $stateDir;

    public function __construct()
    {
        // Получаем реальный абсолютный путь
        $relativePath = __DIR__ . '/../../../storage';
        $this->stateDir = $this->getRealPath($relativePath);
    }
    
    /**
     * Получает реальный абсолютный путь
     * 
     * @param string $path Путь с любыми разделителями
     * @return string Абсолютный нормализованный путь
     */
    private function getRealPath(string $path): string
    {
        // Сначала нормализуем слеши
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        
        // Преобразуем в реальный абсолютный путь
        $realPath = realpath($normalizedPath);
        
        if ($realPath === false) {
            // Если realpath не сработал (директория не существует), создаем её
            $realPath = $normalizedPath;
            if (!is_dir($realPath)) {
                mkdir($realPath, 0777, true);
            }
            $realPath = realpath($realPath);
        }
        
        return $realPath ?: $normalizedPath;
    }
    
    /**
     * Нормализует путь для текущей ОС
     * 
     * @param string $path Путь с любыми разделителями
     * @return string Нормализованный путь
     */
    private function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
    
    public function getStateDirectory(): string
    {
        return $this->stateDir;
    }

    public function saveStateToFile(string $chat_id, array $stateData): void
    {
        $stateFile = $this->getStateFilePath($chat_id);
        $existingData = $this->getStateFromFile($chat_id);

        // Объединяем существующие данные с новыми
        $mergedData = array_merge($existingData, $stateData);

        $jsonData = json_encode($mergedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($stateFile, $jsonData);
    }

    public function getStateFromFile(string $chat_id): array
    {
        $stateFile = $this->getStateFilePath($chat_id);

        if (!file_exists($stateFile)) {
            return [];
        }

        $jsonState = file_get_contents($stateFile);
        return json_decode($jsonState, true) ?? [];
    }

    public function getStateField(string $chat_id, string $field)
    {
        $stateData = $this->getStateFromFile($chat_id);
        return $stateData[$field] ?? "";
    }

    // Метод для получения состояния полей
    public function getStateFields($chatId)
    {
        $stateFields = ['companyId', 'contactId', 'issueId'];
        $fields = array_map(fn($field) => (string)$this->getStateField($chatId, $field), $stateFields);
        return $fields;
    }

    public function setStateField(string $chat_id, string $field, $value): void
    {
        $stateData = $this->getStateFromFile($chat_id);
        $stateData[$field] = $value;
        $this->saveStateToFile($chat_id, $stateData);
    }

    protected function getStateFilePath(string $chat_id): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . $chat_id . '.json';
    }
    
    public function clearStateKeys(string $chat_id): void
    {
        $stateFile = $this->getStateFilePath($chat_id);
        $stateData = $this->getStateFromFile($chat_id);

        $keysToClear = ['inventory_number', 'maintenance_entity_id', 'attachmentFileName'];

        foreach ($keysToClear as $key) {
            if (isset($stateData[$key])) {
                unset($stateData[$key]);
            }
        }

        $jsonData = json_encode($stateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($stateFile, $jsonData);
    }
    
    public function clearStateKey(string $chat_id, string $key): void
    {
        $stateFile = $this->getStateFilePath($chat_id);
        $stateData = $this->getStateFromFile($chat_id);

        if (isset($stateData[$key])) {
            unset($stateData[$key]);

            $jsonData = json_encode($stateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            file_put_contents($stateFile, $jsonData);
        }
    }

    //TODO: отрефакторить и передавать в массиве ключи которые хотим почистить
    public function clearStateKeyIssueId(string $chat_id): void
    {
        $stateFile = $this->getStateFilePath($chat_id);
        $stateData = $this->getStateFromFile($chat_id);

        $keysToClear = ['issueId'];

        foreach ($keysToClear as $key) {
            if (isset($stateData[$key])) {
                unset($stateData[$key]);
            }
        }

        $jsonData = json_encode($stateData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($stateFile, $jsonData);
    }
    
    public function clearState(string $chat_id): void
    {
        $stateFile = $this->getStateFilePath($chat_id);

        if (file_exists($stateFile)) {
            $stateData = json_decode(file_get_contents($stateFile), true);

            $filteredData = [
                'state' => State::Main_Menu->value,
                'role' => $stateData['role'] ?? null,
                'companyId' => $stateData['companyId'] ?? null,
                'contactId' => $stateData['contactId'] ?? null,
                'phone_number' => $stateData['phone_number'] ?? null,
            ];

            file_put_contents($stateFile, json_encode($filteredData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
    
    public function clearAllState(string $chat_id): void
    {
        $stateFile = $this->getStateFilePath($chat_id);
        if (file_exists($stateFile)) {
            file_put_contents($stateFile, '');
        }
    }
}