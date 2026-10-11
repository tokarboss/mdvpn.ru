<?php
// api/yookassa_handler.php
require_once __DIR__ . '/../db.php';

$source = file_get_contents('php://input');
$requestBody = json_decode($source, true);

// Логируем запросы от ЮKassa
file_put_contents(__DIR__ . '/yk_log.txt', date('Y-m-d H:i:s') . " - " . $source . "\n", FILE_APPEND);

if (isset($requestBody['event']) && $requestBody['event'] === 'payment.succeeded') {
    $payment = $requestBody['object'];
    $amount = floatval($payment['amount']['value']);
    $user_id = intval($payment['metadata']['user_id'] ?? 0);
    $order_id = $payment['id']; // ID платежа в ЮKassa

    // Получаем флаги из нашего платежа
    $is_auto_renew = !empty($payment['metadata']['is_auto_renew']); // Пришел ли платеж от нашего CRON скрипта
    $auto_renew_flag = $payment['metadata']['auto_renew_flag'] ?? '0'; // Ставил ли юзер галочку "Сохранить карту" при ручном пополнении

    // Проверяем, есть ли сохраненный метод оплаты (токен карты)
    $payment_method_id = null;
    if (!empty($payment['payment_method']['saved']) && !empty($payment['payment_method']['id'])) {
        $payment_method_id = $payment['payment_method']['id'];
    }

    if ($user_id > 0 && $amount > 0) {
        try {
            $pdo->beginTransaction();

            if ($is_auto_renew) {
                // ==============================================================
                // СЦЕНАРИЙ 1: АВТОМАТИЧЕСКИЙ ПЛАТЕЖ (от скрипта auto_renewal.php)
                // ==============================================================
                // Деньги списались автоматически, напрямую продлеваем подписку на 30 дней.
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET deadline = DATE_ADD(IF(deadline > NOW(), deadline, NOW()), INTERVAL 30 DAY) 
                    WHERE id = ?
                ");
                $stmt->execute([$user_id]);

                // Записываем в историю как успешное автопродление
                $hist_stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, order_id, status) VALUES (?, ?, ?, 'success_autorenew')");
                $hist_stmt->execute([$user_id, $amount, $order_id]);

            } else {
                // ==============================================================
                // СЦЕНАРИЙ 2: РУЧНОЕ ПОПОЛНЕНИЕ БАЛАНСА КЛИЕНТОМ
                // ==============================================================
                
                if ($payment_method_id && $auto_renew_flag === '1') {
                    // Юзер пополнял руками и ставил галочку "Автопродление" - сохраняем токен карты
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ?, payment_method_id = ?, auto_renew = 1 WHERE id = ?");
                    $stmt->execute([$amount, $payment_method_id, $user_id]);
                } else {
                    // Обычное пополнение без сохранения карты
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $user_id]);
                }
                
                $hist_stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, order_id, status) VALUES (?, ?, ?, 'success')");
                $hist_stmt->execute([$user_id, $amount, $order_id]);
            }

            // ==============================================================
            // 3. РЕФЕРАЛЬНАЯ ПРОГРАММА (начисляем 20% рефереру в обоих случаях)
            // ==============================================================
            $stmtRef = $pdo->prepare("SELECT invited_by FROM users WHERE id = ?");
            $stmtRef->execute([$user_id]);
            $referrerId = intval($stmtRef->fetchColumn());

            if ($referrerId > 0) {
                $bonus = $amount * 0.20;
                $stmtBonus = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmtBonus->execute([$bonus, $referrerId]);

                $stmtLog = $pdo->prepare("INSERT INTO referral_earnings (referrer_id, referral_id, amount_paid, bonus_earned) VALUES (?, ?, ?, ?)");
                $stmtLog->execute([$referrerId, $user_id, $amount, $bonus]);
            }

            $pdo->commit();
            http_response_code(200);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Ошибка ЮKassa БД: " . $e->getMessage());
            http_response_code(500);
            exit;
        }
    }
}

http_response_code(400); 
?>