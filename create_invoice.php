<?php
// api/create_invoice.php
require_once '../db.php';
header('Content-Type: application/json; charset=utf-8');

// Данные мерчанта FreeKassa
$merchant_id = '72404'; 
$secret_1 = 'nZk7H?NK?q^m]!h'; 

// Читаем JSON-данные от фронтенда
$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? number_format(floatval($input['amount']), 2, '.', '') : '0.00';
$user_id = intval($input['user_id'] ?? 0);

// Валидация входных данных
if ($amount < 10 || !$user_id) {
    echo json_encode(['success' => false, 'message' => 'Минимальная сумма пополнения — 10 ₽']);
    exit;
}

$order_id = time(); // Уникальный ID заказа на основе таймстампа
$currency = 'RUB';

// Формируем MD5-подпись для FreeKassa
$sign = md5($merchant_id . ':' . $amount . ':' . $secret_1 . ':' . $currency . ':' . $order_id);

// Формируем параметры запроса
$params = [
    'm' => $merchant_id,
    'oa' => $amount,
    'o' => $order_id,
    's' => $sign,
    'currency' => $currency,
    'us_user' => $user_id, // Передаем ID пользователя в кастомном поле (для обработчика)
    'lang' => 'ru'
];

// Генерируем URL кассы
$pay_url = "https://pay.freekassa.ru/?" . http_build_query($params);

echo json_encode(['success' => true, 'url' => $pay_url]);
?>