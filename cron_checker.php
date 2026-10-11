<?php
require_once 'db.php';

$vk_token = 'vk1.a.YOUR_GROUP_ACCESS_TOKEN';
$now = date('Y-m-d H:i:s');
$future_limit = date('Y-m-d H:i:s', strtotime('+4 hours')); // Окно проверки: за 4 часа до конца

// 1. Ищем пользователей, у которых подходит к концу Trial-период
$stmt = $pdo->prepare("
    SELECT * FROM users 
    WHERE deadline <= ? 
      AND deadline >= ? 
      AND status = 'active'
      AND tariff = 'Trial'
      AND reminder_sent = 0 
      AND vk_id IS NOT NULL
");
$stmt->execute([$future_limit, $now]);
$users_to_remind = $stmt->fetchAll();

foreach ($users_to_remind as $user) {
    // Генерируем уникальный промокод со скидкой 5%
    $promo_code = 'TEST5-' . strtoupper(substr(md5(mt_rand()), 0, 6));
    
    // Сохраняем промокод и ставим флаг, что уведомление ушло
    $update = $pdo->prepare("UPDATE users SET promo_code = ?, reminder_sent = 1 WHERE id = ?");
    $update->execute([$promo_code, $user['id']]);
    
    // Ссылка на ЛК с автоматической передачей промокода в GET-параметре
    $lk_link = "https://mdvpn.ru/lk/?promo=" . $promo_code;
    
    $message = "⚠️ Твой тестовый период VPN заканчивается через несколько часов ({$user['deadline']}).\n\n" .
               "Чтобы не потерять доступ к быстрому интернету, продли подписку со скидкой 5%!\n" .
               "Твой личный промокод: {$promo_code}\n\n" .
               "🔗 Активировать в Личном кабинете: {$lk_link}";
               
    sendVkNotification($user['vk_id'], $message, $vk_token);
}

// 2. Автоматически закрываем тесты, у которых время вышло
$stmt_expired = $pdo->prepare("
    SELECT * FROM users 
    WHERE deadline < ? 
      AND status = 'active'
      AND tariff = 'Trial'
");
$stmt_expired->execute([$now]);
$expired_users = $stmt_expired->fetchAll();

foreach ($expired_users as $user) {
    $upd = $pdo->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
    $upd->execute([$user['id']]);
    
    // Здесь при необходимости можно вызвать отключение ключа в панели VPN (например, 3x-ui / Marzban API)
}

function sendVkNotification($vk_id, $message, $token) {
    $params = [
        'user_id' => $vk_id,
        'message' => $message,
        'random_id' => mt_rand(1, 1000000000),
        'access_token' => $token,
        'v' => '5.131'
    ];
    file_get_contents('https://api.vk.com/method/messages.send?' . http_build_query($params));
}