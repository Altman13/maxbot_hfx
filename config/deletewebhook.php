<?php
// public/delete_all_webhooks.php

$token = ''; // Ваш токен

// Получить список подписок
$listUrl = "https://platform-api.max.ru/subscriptions";
$ch = curl_init($listUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$subscriptions = json_decode($response, true);

if (isset($subscriptions['subscriptions'])) {
    foreach ($subscriptions['subscriptions'] as $sub) {
        $url = "https://platform-api.max.ru/subscriptions?url=" . urlencode($sub['url']);
        echo "Удаляем: " . $sub['url'] . "\n";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }
    echo "✅ Все вебхуки удалены\n";
}