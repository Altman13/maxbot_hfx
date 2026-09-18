<?php

require_once __DIR__ . '/../vendor/autoload.php';
$token = '';

//с 25.06.26 не работают самоподписанные сертификаты

$webhookUrl = '';
$secret = bin2hex(random_bytes(16)); // генерируем случайный секрет

echo "🔧 Установка webhook для MAX бота...\n";
echo "Бот: @id_bot\n";
echo "URL: $webhookUrl\n\n";

// API endpoint для подписок
$url = "https://platform-api.max.ru/subscriptions";

// Данные для подписки
$data = [
    'url' => $webhookUrl,
    'update_types' => [
        'message_created',  // новые сообщения
        'bot_started',      // запуск бота (/start)
        'message_callback', // нажатия на кнопки
        // 'location_received' // получение геолокации
    ],
    'secret' => $secret
];

echo "📡 Отправка запроса к API...\n";
echo "URL: $url\n";
echo "Secret: $secret\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . $token,
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

if ($response) {
    $responseData = json_decode($response, true);
    echo "Ответ: " . json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

if ($error) {
    echo "Ошибка CURL: $error\n";
}

if ($httpCode >= 200 && $httpCode < 300) {
    echo "\n✅ Webhook успешно установлен!\n";
    echo "Секрет для проверки подписей: $secret\n";
    
    // Сохраняем секрет в файл для использования в хуке
    file_put_contents(__DIR__ . '/../storage/webhook_secret.txt', $secret);
    
} elseif ($httpCode === 401) {
    echo "\n❌ Ошибка авторизации. Проверьте токен.\n";
    echo "Токен должен быть правильным и не истекшим.\n";
} elseif ($httpCode === 409) {
    echo "\n⚠️ Подписка уже существует. Обновляем...\n";
    
    // Здесь можно добавить логику обновления существующей подписки
} else {
    echo "\n❌ Не удалось установить webhook. Код ошибки: $httpCode\n";
}

echo "\n📌 Типы событий, на которые подписались:\n";
foreach ($data['update_types'] as $type) {
    echo "  - $type\n";
}