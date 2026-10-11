<?php
// auth_handler.php
require 'db.php';
require_once 'tg_notify.php';
header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (empty($data['code']) || empty($data['device_id'])) {
    echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
    exit;
}

$app_id = '54553872'; 
$service_key = 'fopL6altjBBJDLeW0fUE'; 

$params = [
    'grant_type'    => 'upgrade_protocol',
    'client_id'     => $app_id,
    'client_secret' => $service_key,
    'code'          => $data['code'],
    'device_id'     => $data['device_id'],
    'v'             => '5.131'
];

$ch = curl_init('https://api.vk.com/method/auth.exchangeSilentToken?' . http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$authResponse = json_decode(curl_exec($ch), true);
curl_close($ch);

$access_token = $authResponse['response']['access_token'] ?? '';
if (!$access_token) {
    echo json_encode(['success' => false, 'message' => 'Ошибка обмена токена']);
    exit;
}

$userRes = json_decode(file_get_contents('https://api.vk.com/method/users.get?access_token='.$access_token.'&v=5.131'), true);
if (!isset($userRes['response'][0])) {
    echo json_encode(['success' => false, 'message' => 'Ошибка данных пользователя']);
    exit;
}

$vkUser = $userRes['response'][0];
$name = $vkUser['first_name'] . ' ' . $vkUser['last_name'];
$vk_id_source = $vkUser['id']; // ОПРЕДЕЛЯЕМ ПЕРЕМЕННУЮ ЗДЕСЬ

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$name]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($u) {
        $id = $u['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, created_at, tariff, expiry_date, balance) VALUES (?, ?, NOW(), 'Trial', DATE_ADD(NOW(), INTERVAL 1 DAY), 0.00)");
        $stmt->execute([$name, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
        $id = $pdo->lastInsertId();
        
        // Уведомление сработает, так как $vk_id_source теперь определен
        sendVkAuthNotification($vk_id_source, $name);
    }

    echo json_encode([
        'success' => true,
        'user' => $name,
        'id' => (int)$id,
        'token' => bin2hex(random_bytes(16))
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
}