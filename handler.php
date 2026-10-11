<?php
session_start();

// Полное открытие ворот для игрового поддомена
header("Access-Control-Allow-Origin: https://game.mdvpn.ru");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Если это preflight-запрос (OPTIONS), сразу отдаем статус 200
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit(0);
}

// Выключаем вывод ошибок в поток, чтобы не ломать JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
require_once '../db.php';

// Универсальный перехват входящих данных (JSON payload или обычный POST)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

// Определение экшена
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

function getDeviceLimit($tariff) {
    $limits = [
        'trial'      => 1,
        'base'       => 1,
        'secretbase' => 1,
        'pro'        => 3,
        'premium'    => 5
    ];
    $tariff = strtolower(trim($tariff));
    return $limits[$tariff] ?? 1;
}

// --- ФУНКЦИИ ДЛЯ РАБОТЫ С 3X-UI ---

function generateUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function generateSubId() {
    return bin2hex(random_bytes(8));
}

function request3xUi($serverData, $endpoint, $method = 'POST', $postData = null) {
    $baseUrl = rtrim(trim($serverData['api_url']), '/');
    if (preg_match('/\/panel$/i', $baseUrl)) {
        $baseUrl = substr($baseUrl, 0, -6);
    }
    $url = $baseUrl . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $headers = [
        "Accept: application/json",
        "Authorization: Bearer " . ($serverData['api_token'] ?? ''),
        "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($postData) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("API Error $httpCode. URL: $url | Response: " . ($response ?: $curlError));
    }

    $result = json_decode($response, true);
    return $result;
}

// --- 1. ИНФО О ПОЛЬЗОВАТЕЛЕ ---
if ($action === 'get_user_info') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT username, balance, tariff, deadline, trial_used, vk_id, auto_renew, avatar, bridge_deadline FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($res) {
        echo json_encode(['success' => true, 'data' => $res]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
    }
    exit;
}

// --- 2. Привязка VK ID (Для уже залогиненных) ---
if ($action === 'link_vk_direct') {
    $user_id = intval($input['user_id'] ?? 0);
    $vk_uid = (string)($input['vk_id'] ?? '');

    if (!$user_id || !$vk_uid) {
        echo json_encode(['success' => false, 'message' => 'Ошибка передачи данных']); exit;
    }

    $check = $pdo->prepare("SELECT id FROM users WHERE vk_id = ? AND id != ?");
    $check->execute([$vk_uid, $user_id]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Этот VK уже привязан к другому аккаунту']); exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET vk_id = ? WHERE id = ?");
    $stmt->execute([$vk_uid, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

// --- 3. ВХОД И РЕГИСТРАЦИЯ ЧЕРЕЗ VK ID (С ПОДДЕРЖКОЙ РЕФЕРАЛОВ) ---
if ($action === 'vk_login') {
    $vk_id = '';
    if (isset($input['user_id'])) {
        $vk_id = (string)$input['user_id'];
    } elseif (!empty($input['id_token'])) {
        $parts = explode('.', $input['id_token']);
        if (isset($parts[1])) {
            $payload = json_decode(base64_decode($parts[1]), true);
            $vk_id = (string)($payload['user_id'] ?? $payload['sub'] ?? '');
        }
    }
    if (empty($vk_id) && !empty($input['uuid'])) {
        $vk_id = (string)$input['uuid'];
    }

    if (empty($vk_id) || $vk_id === '0') {
        echo json_encode(['success' => false, 'message' => 'ВК не передал идентификатор пользователя.']);
        exit;
    }

    $ref_id = intval($input['ref_id'] ?? 0);
    $real_name = '';
    $access_token = $input['access_token'] ?? '';

    if (!empty($access_token)) {
        $vk_api_url = "https://api.vk.com/method/users.get?user_ids={$vk_id}&access_token={$access_token}&v=5.131";
        $vk_response_json = @file_get_contents($vk_api_url);
        
        if ($vk_response_json === false && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $vk_api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $vk_response_json = curl_exec($ch);
            curl_close($ch);
        }

        if ($vk_response_json) {
            $vk_data = json_decode($vk_response_json, true);
            if (isset($vk_data['response'][0])) {
                $first_name = $vk_data['response'][0]['first_name'] ?? '';
                $last_name = $vk_data['response'][0]['last_name'] ?? '';
                
                if (!empty($first_name) || !empty($last_name)) {
                    $real_name = trim($first_name . ' ' . $last_name);
                }
            }
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE vk_id = ?");
    $stmt->execute([$vk_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['success' => true, 'user_id' => $user['id']]);
    } else {
        if (empty($real_name)) {
            $clean_id = preg_replace('/[^0-9a-zA-Z]/', '', $vk_id);
            $real_name = "User_" . substr($clean_id, -5); 
        }
        
        $invited_by = null;
        if ($ref_id > 0) {
            $chkRef = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $chkRef->execute([$ref_id]);
            if ($chkRef->fetch()) {
                $invited_by = $ref_id;
            }
        }
        
        $stmt_ins = $pdo->prepare("INSERT INTO users (username, vk_id, balance, tariff, deadline, status, invited_by) VALUES (?, ?, 0, 'Base', NOW(), 'active', ?)");
        $stmt_ins->execute([$real_name, $vk_id, $invited_by]); 
        
        $new_id = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'user_id' => $new_id]);
    }
    exit;
}

// --- АКТИВАЦИЯ ТЕСТОВОГО ПЕРИОДА С ПРОВЕРКОЙ ПОДПИСКИ ВК ---
if ($action === 'activate_trial') {
    $user_id = intval($_GET['user_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) { echo json_encode(['success' => false, 'message' => 'Пользователь не найден']); exit; }
    if ($user['trial_used'] == 1) { echo json_encode(['success' => false, 'message' => 'Тестовый период уже использован']); exit; }
    if (empty($user['vk_id']) || $user['vk_id'] === '0') { echo json_encode(['success' => false, 'message' => 'Сначала привяжите VK']); exit; }

    $vk_group_id = 'mdvpn_ru'; 
    $vk_service_token = 'vk1.a.CHutuNVv8GJddpZwBSfHdc7ShRZBXyqhxsUO8a2rYIRl74nFeQ6incdl3RDmH0S1XwFSoiofsbem-EaURzvwXL8xAwrnYFkKgArP5o6-gIBEuY8FK20Zz5LFq7l0SSbhljIqIyIXL55qRYXblW6FdiMhCGXWmkCQ9tF3UP6Qr0j39JDV6D2QtRkAdAWQEmAx6Mee3cIKDg3k3i9e8yhJYQ';
    $vk_api_url = "https://api.vk.com/method/groups.isMember?group_id={$vk_group_id}&user_id={$user['vk_id']}&access_token={$vk_service_token}&v=5.199";
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $vk_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $vk_response = curl_exec($ch);
        curl_close($ch);
    } else {
        $vk_response = @file_get_contents($vk_api_url);
    }
    
    $vk_data = json_decode($vk_response, true);

    if (isset($vk_data['error'])) {
        echo json_encode(['success' => false, 'message' => 'Ошибка API ВК: ' . $vk_data['error']['error_msg']]);
        exit;
    }

    if (!isset($vk_data['response']) || $vk_data['response'] == 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Сначала подпишитесь на нашу группу ВК, чтобы получить тест!',
            'error_code' => 'not_subscribed'
        ]);
        exit;
    }

    $trial_days = 2;
    $current_deadline = time();

    if (!empty($user['deadline'])) {
        $parsed_deadline = strtotime($user['deadline']);
        if ($parsed_deadline !== false && $parsed_deadline > time()) {
            $current_deadline = $parsed_deadline;
        }
    }
    
    $new_deadline = date('Y-m-d H:i:s', strtotime("+$trial_days days", $current_deadline));
    
    $stmt = $pdo->prepare("UPDATE users SET tariff = 'Base', deadline = ?, trial_used = ? WHERE id = ?");
    if ($stmt->execute([$new_deadline, 1, $user_id])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка базы данных при активации']);
    }
    exit;
}

// --- 5. ПРОМОКОДЫ И ПРИМЕНЕНИЕ СКИДКИ ИЗ VK УВЕДОМЛЕНИЯ ---
if ($action === 'apply_vk_promo') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $promo_code = trim($input['promo_code'] ?? $_POST['promo_code'] ?? $_GET['promo_code'] ?? '');

    if (!$user_id || empty($promo_code)) {
        echo json_encode(['success' => false, 'message' => 'Неверные данные промокода']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND promo_code = ?");
    $stmt->execute([$user_id, $promo_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['active_discount'] = 5;
        $_SESSION['applied_promo_code'] = $promo_code;

        echo json_encode(['success' => true, 'discount' => 5, 'message' => 'Скидка 5% успешно применена!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Неверный или чужой промокод']);
    }
    exit;
}

if ($action === 'check_promo') {
    $code = trim($input['promo_code'] ?? $_POST['promo_code'] ?? $_GET['code'] ?? '');
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    if (empty($code) || !$user_id) {
        echo json_encode(['success' => false, 'message' => 'Неверные параметры']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM promocodes WHERE code = ? AND is_active = 1");
    $stmt->execute([$code]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$promo) {
        echo json_encode(['success' => false, 'message' => 'Промокод не найден или уже недействителен']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        if ($promo['type'] === 'balance') {
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$promo['reward'], $user_id]);
        } elseif ($promo['type'] === 'days') {
            $pdo->prepare("UPDATE users SET deadline = IF(deadline > NOW(), DATE_ADD(deadline, INTERVAL ? DAY), DATE_ADD(NOW(), INTERVAL ? DAY)) WHERE id = ?")
                ->execute([$promo['reward'], $promo['reward'], $user_id]);
        }
        
        if (isset($promo['is_disposable']) && $promo['is_disposable'] == 1) {
            $pdo->prepare("UPDATE promocodes SET is_active = 0 WHERE id = ?")->execute([$promo['id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Промокод успешно активирован!', 'reward' => $promo['reward'], 'type' => $promo['type']]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка при активации промокода']);
    }
    exit;
}

// --- 6. ПОКУПКА ТАРИФА (С УЧЕТОМ СКИДКИ 5%) ---
if ($action === 'buy_tariff') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $t_key = $input['tariff'] ?? $_POST['tariff'] ?? $_GET['tariff'] ?? '';
    
    $tariffs = [
        'Base'       => ['p' => 190,  'd' => 30],
        'Pro'        => ['p' => 490,  'd' => 90],
        'Premium'    => ['p' => 1490, 'd' => 365],
        'SecretBase' => ['p' => 95,   'd' => 30]
    ];

    if (!isset($tariffs[$t_key])) {
        echo json_encode(['success' => false, 'message' => 'Тариф не найден']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance, tariff, deadline FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();

    $price = $tariffs[$t_key]['p'];

    if (isset($_SESSION['active_discount']) && $_SESSION['active_discount'] == 5) {
        $price = round($price * 0.95, 2);
    }

    if (!$u || $u['balance'] < $price) {
        echo json_encode(['success' => false, 'message' => 'Недостаточно средств на балансе']);
        exit;
    }

    $current_tariff = $u['tariff'] ?? 'Base';
    $current_limit = getDeviceLimit($current_tariff);
    $new_limit = getDeviceLimit($t_key);
    
    $is_active = !empty($u['deadline']) && strtotime($u['deadline']) > time();
    $final_tariff = ($is_active && $current_limit > $new_limit) ? $current_tariff : $t_key;

    try {
        $pdo->beginTransaction();
        
        $stmt_upd = $pdo->prepare("UPDATE users SET balance = balance - ?, tariff = ?, deadline = IF(deadline > NOW(), DATE_ADD(deadline, INTERVAL ? DAY), DATE_ADD(NOW(), INTERVAL ? DAY)), promo_code = NULL WHERE id = ?");
        $stmt_upd->execute([
            $price, 
            $final_tariff, 
            $tariffs[$t_key]['d'], 
            $tariffs[$t_key]['d'], 
            $user_id
        ]);

        unset($_SESSION['active_discount']);
        unset($_SESSION['applied_promo_code']);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Тариф успешно оплачен. Теперь вы можете добавить устройство.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении данных: ' . $e->getMessage()]);
    }
    exit;
}

// --- 7. ПОЛУЧЕНИЕ СПИСКА СЕРВЕРОВ ---
if ($action === 'get_servers') {
    $stmt = $pdo->query("SELECT id, name, flag_icon, protocol FROM servers WHERE is_active = 1");
    echo json_encode(['success' => true, 'servers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// --- 8. ПОЛУЧЕНИЕ СПИСКА УСТРОЙСТВ ---
if ($action === 'get_devices') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT id, device_name, wg_config as config FROM user_devices WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_u = $pdo->prepare("SELECT tariff FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $user = $stmt_u->fetch(PDO::FETCH_ASSOC);
    
    $max_devices = getDeviceLimit($user['tariff'] ?? 'base');
    
    echo json_encode([
        'success' => true, 
        'devices' => $devices,
        'current' => count($devices),
        'max' => $max_devices
    ]);
    exit;
}

// --- 9. ГЕНЕРАЦИЯ НОВОГО УСТРОЙСТВА (ПАРАЛЛЕЛЬНО) ---
if ($action === 'add_device') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['success' => false, 'message' => 'Пользователь не найден']); exit; }

    $has_bridge = (!empty($user['bridge_deadline']) && strtotime($user['bridge_deadline']) > time());

    if ($has_bridge) {
        $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1 AND id != 7")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$servers) { echo json_encode(['success' => false, 'message' => 'Нет доступных серверов']); exit; }

    $uuid = generateUuid();
    $clientEmail = $uuid; // Теперь имя клиента в панели будет совпадать с его UUID из БД
    $device_name = "Устройство " . (intval($pdo->query("SELECT count(*) FROM user_devices WHERE user_id = $user_id")->fetchColumn()) + 1);

    $main_exp = !empty($user['deadline']) ? strtotime($user['deadline']) : 0;
    $bridge_exp = !empty($user['bridge_deadline']) ? strtotime($user['bridge_deadline']) : 0;
    $max_exp = max($main_exp, $bridge_exp);
    $expiryTime = $max_exp > 0 ? $max_exp * 1000 : 0;

    $mh = curl_multi_init();
    $ch_array = [];

    foreach ($servers as $index => $server_data) {
        $inbound_ids = array_map('intval', explode(',', $server_data['inbound_id'] ?? '1'));
        
        $requestBody = [
            "client" => [
                "id" => $uuid, 
                "email" => $clientEmail,
                "flow" => "xtls-rprx-vision", // ИСПРАВЛЕНО: Добавлено принудительно для работы VLESS
                "expiryTime" => $expiryTime,
                "enable" => true, 
                "tgId" => 0, 
                "limitIp" => 0, 
                "totalGB" => 0, 
                "subId" => $uuid
            ],
            "inboundIds" => $inbound_ids 
        ];

        $baseUrl = rtrim(trim($server_data['api_url']), '/');
        if (preg_match('/\/panel$/i', $baseUrl)) $baseUrl = substr($baseUrl, 0, -6);
        
        $ch = curl_init($baseUrl . "/panel/api/clients/add");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: application/json",
            "Authorization: Bearer " . ($server_data['api_token'] ?? ''),
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

        curl_multi_add_handle($mh, $ch);
        $ch_array[$index] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    $success_count = 0;
    foreach ($ch_array as $index => $ch) {
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $success_count++;
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    if ($success_count > 0) {
        $stmt_ins = $pdo->prepare("INSERT INTO user_devices (user_id, device_name, wg_config, public_key, server_id, protocol) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_ins->execute([$user_id, $device_name, '', $uuid, 0, '3x-ui']);
        $device_id = $pdo->lastInsertId();

        $subLink = "https://mdvpn.ru/api/sub.php?d={$device_id}&u={$user_id}#" . rawurlencode("MDVPN.RU - Подписка");
        $pdo->prepare("UPDATE user_devices SET wg_config = ? WHERE id = ?")->execute([$subLink, $device_id]);

        echo json_encode(['success' => true, 'wg_config' => $subLink]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Не удалось создать ключ на серверах']);
    }
    exit;
}

// --- ПОКУПКА ОПЦИИ "ОБХОД ГЛУШИЛОК" (100 руб / 30 дней) ---
if ($action === 'buy_bridge') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $price = 100;

    $stmt = $pdo->prepare("SELECT balance, bridge_deadline, deadline FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        exit;
    }

    if ($u['balance'] < $price) {
        echo json_encode(['success' => false, 'message' => 'Недостаточно средств на балансе']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt_upd = $pdo->prepare("
            UPDATE users 
            SET balance = balance - ?, 
                bridge_deadline = IF(bridge_deadline > NOW(), DATE_ADD(bridge_deadline, INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY)) 
            WHERE id = ?
        ");
        $stmt_upd->execute([$price, $user_id]);

        $stmt_dev = $pdo->prepare("SELECT id, public_key FROM user_devices WHERE user_id = ?");
        $stmt_dev->execute([$user_id]);
        $devices = $stmt_dev->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($devices) > 0) {
            $server7 = $pdo->query("SELECT * FROM servers WHERE id = 7 AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
            if ($server7) {
                $inbound_ids = array_map('intval', explode(',', $server7['inbound_id'] ?? '1'));
                $baseUrl = rtrim(trim($server7['api_url']), '/');
                if (preg_match('/\/panel$/i', $baseUrl)) $baseUrl = substr($baseUrl, 0, -6);
                
                $expiryTime = !empty($u['deadline']) ? strtotime($u['deadline']) * 1000 : 0;
                
                $mh = curl_multi_init();
                $ch_array = [];
                
                foreach ($devices as $index => $dev) {
                    $uuid = $dev['public_key'];
                    $clientEmail = $uuid; // ИСПРАВЛЕНО: Email совпадает с UUID
                    
                    $requestBody = [
                        "client" => [
                            "id" => $uuid, 
                            "email" => $clientEmail,
                            "flow" => "xtls-rprx-vision",
                            "expiryTime" => $expiryTime,
                            "enable" => true, 
                            "tgId" => 0, 
                            "limitIp" => 0, 
                            "totalGB" => 0, 
                            "subId" => $uuid
                        ],
                        "inboundIds" => $inbound_ids 
                    ];
                    
                    $ch = curl_init($baseUrl . "/panel/api/clients/add");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Accept: application/json",
                        "Authorization: Bearer " . ($server7['api_token'] ?? ''),
                        "Content-Type: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
                    
                    curl_multi_add_handle($mh, $ch);
                    $ch_array[] = $ch;
                }
                
                $running = null;
                do {
                    curl_multi_exec($mh, $running);
                    curl_multi_select($mh, 0.1);
                } while ($running > 0);
                
                foreach ($ch_array as $ch) { curl_multi_remove_handle($mh, $ch); }
                curl_multi_close($mh);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Опция успешно активирована! Обновите подписку в приложении VPN.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Ошибка при активации опции']);
    }
    exit;
}

// --- 10. УДАЛЕНИЕ УСТРОЙСТВА (DELETE_DEVICE) ---
if ($action === 'delete_device') {
    $device_id = intval($input['device_id'] ?? $_POST['device_id'] ?? $_GET['device_id'] ?? 0);
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $user_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        echo json_encode(['success' => false, 'message' => 'Устройство не найдено']);
        exit;
    }

    $uuid = $device['public_key'];
    $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

    if ($servers && !empty($uuid)) {
        $mh = curl_multi_init();
        $ch_array = [];

        foreach ($servers as $index => $server_data) {
            $baseUrl = rtrim(trim($server_data['api_url']), '/');
            if (preg_match('/\/panel$/i', $baseUrl)) $baseUrl = substr($baseUrl, 0, -6);
            
            // Используем новый API полного очищения панели. Вместо email отправляем UUID.
            $ch = curl_init($baseUrl . "/panel/api/clients/del/" . $uuid);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Authorization: Bearer " . ($server_data['api_token'] ?? ''),
                "Content-Type: application/json"
            ]);

            curl_multi_add_handle($mh, $ch);
            $ch_array[$index] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

        foreach ($ch_array as $ch) {
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
    }

    $stmt_del = $pdo->prepare("DELETE FROM user_devices WHERE id = ?");
    $stmt_del->execute([$device_id]);

    echo json_encode(['success' => true]);
    exit;
}

// --- 11. ПЕРЕИМЕНОВАНИЕ УСТРОЙСТВА ---
if ($action === 'rename_device') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $device_id = intval($input['device_id'] ?? $_POST['device_id'] ?? $_GET['device_id'] ?? 0);
    $new_name = trim($input['new_name'] ?? $input['name'] ?? $_POST['name'] ?? $_GET['name'] ?? '');

    if (!$user_id || !$device_id || empty($new_name)) {
        echo json_encode(['success' => false, 'message' => 'Неверные данные для переименования']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE user_devices SET device_name = ? WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$new_name, $device_id, $user_id])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка базы данных при переименовании']);
    }
    exit;
}

// --- 12. ПОЛУЧЕНИЕ КОНФИГУРАЦИИ ---
if ($action === 'get_user_config') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $device_id = intval($input['device_id'] ?? $_POST['device_id'] ?? $_GET['device_id'] ?? 0);
    
    if ($device_id > 0) {
        $stmt = $pdo->prepare("SELECT wg_config FROM user_devices WHERE id = ? AND user_id = ?");
        $stmt->execute([$device_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT wg_config FROM user_devices WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$user_id]);
    }
    $config = $stmt->fetchColumn();
    
    if ($config) {
        echo json_encode(['success' => true, 'wg_config' => $config]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Конфигурация не найдена']);
    }
    exit;
}

// --- 13. ДАННЫЕ СЛОТ-МАШИНЫ ---
if ($action === 'get_slot_user') {
    $user_id = intval($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT username, deadline, bonus_spins FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($res) {
        $days_left = 0;
        if (!empty($res['deadline'])) {
            $diff = strtotime($res['deadline']) - time();
            if ($diff > 0) {
                $days_left = ceil($diff / 86400);
            }
        }
        echo json_encode([
            'success' => true, 
            'username' => $res['username'],
            'vpn_days' => $days_left,
            'bonus_spins' => intval($res['bonus_spins'])
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
    }
    exit;
}

// --- 14. СЛОТ-МАШИНА: БЕЗОПАСНЫЙ СПИН ---
if ($action === 'spin_slot') {
    $user_id = intval($_GET['user_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT bonus_spins, deadline FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || intval($user['bonus_spins']) <= 0) {
        echo json_encode(['success' => false, 'message' => 'Недостаточно бонусных спинов! Пополните баланс от 50р.']);
        exit;
    }
    
    $update_spin = $pdo->prepare("UPDATE users SET bonus_spins = bonus_spins - 1 WHERE id = ?");
    $update_spin->execute([$user_id]);
    
    $game_url = 'http://game.mdvpn.ru:8585/api/wheel/spin?user_id=' . $user_id;
    $game_res = @file_get_contents($game_url);
    
    if (!$game_res) {
        $pdo->prepare("UPDATE users SET bonus_spins = bonus_spins + 1 WHERE id = ?")->execute([$user_id]);
        echo json_encode(['success' => false, 'message' => 'Игровой сервер временно недоступен']);
        exit;
    }
    
    $data = json_decode($game_res, true);
    $prize_id = $data['prize_id'] ?? 'try_again';
    
    $days_to_add = 0;
    if (strpos($prize_id, 'vpn_') === 0) {
        $days_to_add = intval(explode('_', $prize_id)[1]);
    }
    
    if ($days_to_add > 0) {
        $pdo->prepare("UPDATE users SET deadline = IF(deadline > NOW(), DATE_ADD(deadline, INTERVAL ? DAY), DATE_ADD(NOW(), INTERVAL ? DAY)) WHERE id = ?")
            ->execute([$days_to_add, $days_to_add, $user_id]);
    }
    
    $stmt_new = $pdo->prepare("SELECT deadline, bonus_spins FROM users WHERE id = ?");
    $stmt_new->execute([$user_id]);
    $user_new = $stmt_new->fetch(PDO::FETCH_ASSOC);
    
    $days_left = 0;
    if (!empty($user_new['deadline'])) {
        $diff = strtotime($user_new['deadline']) - time();
        if ($diff > 0) $days_left = ceil($diff / 86400);
    }
    
    echo json_encode([
        'success' => true,
        'prize_id' => $prize_id,
        'updated_spins' => intval($user_new['bonus_spins']),
        'updated_days' => $days_left
    ]);
    exit;
}

// --- 15. ИСТОРИЯ ПОПОЛНЕНИЙ ---
if ($action === 'get_payment_history') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID пользователя']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT amount, status, created_at AS date FROM payments WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $history]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Ошибка получения истории']);
    }
    exit;
}

// --- 16. СТАТИСТИКА РЕФЕРАЛЬНОЙ ПРОГРАММЫ ---
if ($action === 'get_referral_stats') {
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID пользователя']);
        exit;
    }

    try {
        $stmtTotal = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT referral_id) as total_referrals, 
                COALESCE(SUM(bonus_earned), 0) as total_earned 
            FROM referral_earnings 
            WHERE referrer_id = ?
        ");
        $stmtTotal->execute([$user_id]);
        $stats = $stmtTotal->fetch(PDO::FETCH_ASSOC);

        $stmtList = $pdo->prepare("
            SELECT 
                u.username, 
                re.amount_paid, 
                re.bonus_earned, 
                re.created_at 
            FROM referral_earnings re
            JOIN users u ON re.referral_id = u.id
            WHERE re.referrer_id = ?
            ORDER BY re.created_at DESC
        ");
        $stmtList->execute([$user_id]);
        $referrals = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total_referrals' => intval($stats['total_referrals']),
            'total_earned' => floatval($stats['total_earned']),
            'referrals' => $referrals
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Ошибка получения реферальной статистики']);
    }
    exit;
}

// --- 17. ОБНОВЛЕНИЕ СТАТУСА АВТОПРОДЛЕНИЯ ЧЕРЕЗ ТУМБЛЕР ---
if ($action === 'update_auto_renew') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = intval($input['user_id'] ?? $_POST['user_id'] ?? 0);
    $auto_renew = intval($input['auto_renew'] ?? 0);

    if ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET auto_renew = ? WHERE id = ?");
            $stmt->execute([$auto_renew, $user_id]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка базы данных']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Неверный ID пользователя']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);