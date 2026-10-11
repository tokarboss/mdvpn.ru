<?php
require_once __DIR__ . '/db.php';

$vk_token = 'vk1.a.rYp6bA5hbb0pTT5ndssoQ1ei2nDeYxVX6Wd_uduJ3khKwamNDBcAKATXybiHeADEHi9DOoBUJJWLjVALGL9WY6osngwqEmhhzdjljJ7gKXG8iHguO_e7UVx7NtsnoZJCOl3Ijl_SHoSQWd-OpwrdLH1sA4OAZew12XmMEFt88Zv4jddg1r4mdU4bg9k6Jhg1SxtiHiH_qIsfUetsKWvnFw';

// Ищем пользователей, у которых подписка истекает в течение ближайшего часа и которым еще не отправляли уведомление об окончании
// (можно добавить отдельную колонку notified, но для примера проверяем по статусу)
$stmt = $pdo->prepare("SELECT * FROM users WHERE deadline <= NOW() AND status = 'active'");
$stmt->execute();
$expired_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired_users as $user) {
    $vk_id = $user['vk_id'] ?? 0;
    
    if ($vk_id > 0) {
        $msg = "⚠️ Внимание! Ваш доступ (тестовый период) к VPN подошел к концу.\n\n" .
               "Чтобы продолжить пользоваться быстрым интернетом, продлите подписку в личном кабинете: https://mdvpn.ru/lk/";
        
        // Отправляем сообщение через API ВК
        sendVkMessage($vk_id, $msg, $vk_token);
        
        // Обновляем статус пользователя в базе, чтобы не спамить его повторно
        $updateStmt = $pdo->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
        $updateStmt->execute([$user['id']]);
    }
}

echo "Check completed.";

function sendVkMessage($vk_id, $message, $token) {
    $params = [
        'user_id' => $vk_id,
        'message' => $message,
        'random_id' => mt_rand(1, 1000000000),
        'access_token' => $token,
        'v' => '5.131'
    ];
    @file_get_contents('https://api.vk.com/method/messages.send?' . http_build_query($params));
}