<?php
// api/auto_renewal.php
// Скрипт запускается по Cron каждый час
require_once __DIR__ . '/../db.php';

$shop_id = '1334509';
$secret_key = 'live_pqtAUr__8y50L-GIXk9uPZWowwFxZSAtIKZwbN8NWZQ';

// Ищем пользователей, у которых подписка истекает в течение следующего часа, 
// у них есть сохраненная карта и достаточно денег на балансе (или списываем напрямую с карты)
// Простейший вариант: ищем тех, у кого deadline истек или истекает прямо сейчас, и есть payment_method_id
$stmt = $pdo->prepare("
    SELECT id, tariff, payment_method_id 
    FROM users 
    WHERE deadline <= NOW() 
      AND payment_method_id IS NOT NULL 
      AND payment_method_id != ''
      AND auto_renew = 1
");
$stmt->execute();
$usersToRenew = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Стоимость тарифов для продления (должна совпадать с вашими ценами)
$tariffs = [
    'Base'       => 190,
    'Pro'        => 490,
    'Premium'    => 1490,
    'SecretBase' => 95
];

foreach ($usersToRenew as $user) {
    $tariffKey = $user['tariff'] ?? 'Base';
    $price = $tariffs[$tariffKey] ?? 190;
    $amountFormatted = number_format($price, 2, '.', '');
    $savedCardToken = $user['payment_method_id'];
    $user_id = $user['id'];

    // Формируем запрос на автоплатеж (рекуррентный платеж по токену)
    $data = [
        'amount' => [
            'value' => $amountFormatted,
            'currency' => 'RUB'
        ],
        'capture' => true,
        'payment_method_id' => $savedCardToken, // Списываем с привязанной карты без участия пользователя
        'description' => "Автопродление подписки $tariffKey (ID: $user_id)",
        'metadata' => [
            'user_id' => $user_id,
            'is_auto_renew' => true
        ]
    ];

    $ch = curl_init('https://api.yookassa.ru/v3/payments');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Idempotence-Key: ' . uniqid('renew_'),
        'Authorization: Basic ' . base64_encode($shop_id . ':' . $secret_key)
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);

    // Если ЮKassa успешно одобрила списание, вебхук (yookassa_handler.php) сам поймает событие 
    // payment.succeeded и продлит дни в базе данных!
    if ($http_code !== 200 || ($res_data['status'] ?? '') !== 'succeeded') {
        // Если платеж не удался (например, недостаточно средств на карте), 
        // можно временно отключить автопродление или отправить уведомление пользователю
        error_log("Ошибка автопродления для пользователя ID $user_id");
    }
}
echo "Auto-renewal check finished.";