<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../db.php';
use phpseclib3\Net\SSH2;

// Настройки SSH
define('SSH_HOST', '91.219.23.216');
define('SSH_USER', 'root');
define('SSH_PASS', 'zaza1212');

try {
    // Выбираем всех активных пользователей, у которых истек срок (deadline)
    $stmt = $pdo->prepare("SELECT id, tariff, deadline FROM users WHERE status = 'active' AND deadline < NOW()");
    $stmt->execute();
    $expired_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($expired_users) > 0) {
        $ssh = new SSH2(SSH_HOST);
        if (!$ssh->login(SSH_USER, SSH_PASS)) exit("SSH Login Failed");

        foreach ($expired_users as $user) {
            // Формируем имя клиента (как оно создавалось в wg-add.sh)
            $client_name = ($user['tariff'] == 'Trial') ? "trial_" . $user['id'] : $user['tariff'] . "_" . $user['id'];
            
            // 1. Удаляем на сервере
            $ssh->exec("bash /root/wg-remove.sh $client_name");

            // 2. Обновляем статус в БД
            $update = $pdo->prepare("UPDATE users SET status = 'expired', wg_config = NULL, public_key = NULL WHERE id = ?");
            $update->execute([$user['id']]);
            
            echo "User {$user['id']} expired and removed.\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}