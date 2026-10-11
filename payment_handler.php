<?php
// api/payment_handler.php
require_once __DIR__ . '/../db.php'; 

$secret_2 = 'R_+=aZXj7gCuL9}'; 

// Логируем все входящие запросы для отладки (создаст файл fk_log.txt в папке api)
file_put_contents(__DIR__ . '/fk_log.txt', date('Y-m-d H:i:s') . " - " . print_r($_POST, true) . "\n", FILE_APPEND);

$merchant_id = $_POST['MERCHANT_ID'] ?? '';
$amount      = $_POST['AMOUNT']      ?? ''; 
$order_id    = $_POST['MERCHANT_ORDER_ID'] ?? '';
$user_id     = $_POST['us_user']     ?? ''; 
$sign_fka    = $_POST['SIGN']        ?? '';

$my_sign = md5($merchant_id . ':' . $amount . ':' . $secret_2 . ':' . $order_id);

if (strtolower($my_sign) === strtolower($sign_fka)) {
    try {
        // 1. Пополняем баланс
        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->execute([floatval($amount), intval($user_id)]);

        if ($stmt->rowCount() > 0) {
            // 2. ЗАПИСЫВАЕМ В ИСТОРИЮ ПЛАТЕЖЕЙ (ТАБЛИЦА payments)
            $hist_stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, order_id, status) VALUES (?, ?, ?, 'success')");
            $hist_stmt->execute([intval($user_id), floatval($amount), $order_id]);

            die('YES'); 
        } else {
            error_log("Ошибка: Пользователь с ID $user_id не найден.");
            die('error: user_not_found');
        }
    } catch (PDOException $e) {
        error_log("Ошибка БД FreeKassa: " . $e->getMessage());
        die('error: database_fail');
    }
} else {
    error_log("Ошибка подписи! Ожидали $my_sign, получили $sign_fka");
    die('error: bad_sign');
}
?>