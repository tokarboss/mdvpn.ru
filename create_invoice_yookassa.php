<?php
// api/create_invoice_yookassa.php
require_once '../db.php';
header('Content-Type: application/json; charset=utf-8');

// Данные магазина ЮKassa
$shop_id = '1334509';
$secret_key = 'live_pqtAUr__8y50L-GIXk9uPZWowwFxZSAtIKZwbN8NWZQ';

// Читаем JSON-данные от фронтенда
$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? number_format(floatval($input['amount']), 2, '.', '') : '0.00';
$user_id = intval($input['user_id'] ?? 0);
$is_auto_save = !empty($input['auto_renew']) ? true : false; // Флаг: сохранять ли карту для автоплатежей

// Валидация входных данных
if ($amount < 10 || !$user_id) {
    echo json_encode(['success' => false, 'message' => 'Минимальная сумма пополнения — 10 ₽']);
    exit;
}

// Формируем тело запроса к API ЮKassa
$data = [
    'amount' => [
        'value' => $amount,
        'currency' => 'RUB',
    ],
    'confirmation' => [
        'type' => 'redirect',
        'return_url' => 'https://mdvpn.ru/lk/', // Куда вернуть пользователя после оплаты
    ],
    'capture' => true,
    'description' => "Пополнение баланса MDVPN (ID пользователя: $user_id)",
    'metadata' => [
        'user_id' => $user_id, // Передаем ID для обработчика платежа
        'auto_renew_flag' => $is_auto_save ? '1' : '0'
    ]
];

// ЕСЛИ пользователь выбрал автопродление, просим ЮKassa сохранить платежный метод (карту)
if ($is_auto_save) {
    $data['save_payment_method'] = true;
}

// Инициализируем cURL для отправки запроса в ЮKassa
$ch = curl_init('https://api.yookassa.ru/v3/payments');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Idempotence-Key: ' . uniqid(), // Уникальный ключ идемпотентности
    'Authorization: Basic ' . base64_encode($shop_id . ':' . $secret_key)
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res_data = json_decode($response, true);

// Если ЮKassa успешно создала платеж, отдаем ссылку на фронтенд
if ($http_code === 200 && isset($res_data['confirmation']['confirmation_url'])) {
    echo json_encode([
        'success' => true, 
        'url' => $res_data['confirmation']['confirmation_url']
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Ошибка платежной системы ЮKassa. Попробуйте позже.',
        'error_detail' => $res_data['description'] ?? 'Unknown error'
    ]);
}
?>