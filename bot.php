<?php
// Отключаем любые ошибки и буферизацию
error_reporting(0);
ini_set('display_errors', 0);
while (ob_get_level()) {
    ob_end_clean();
}

$confirmation_token = 'a923f664';

// Читаем сырые данные из потока ввода
$input = file_get_contents('php://input');

// Если в запросе от ВК есть слово confirmation или это проверка связи — сразу отдаем токен и завершаем работу
if (strpos($input, 'confirmation') !== false || $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $confirmation_token;
    exit;
}

$data = json_decode($input, true);

if (isset($data['type']) && $data['type'] === 'confirmation') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $confirmation_token;
    exit;
}

// Ниже идет обычная логика работы с ботом (если пришло сообщение)
if (!$data) {
    echo 'ok';
    exit;
}

require_once __DIR__ . '/db.php';
$vk_token = 'vk1.a.rYp6bA5hbb0pTT5ndssoQ1ei2nDeYxVX6Wd_uduJ3khKwamNDBcAKATXybiHeADEHi9DOoBUJJWLjVALGL9WY6osngwqEmhhzdjljJ7gKXG8iHguO_e7UVx7NtsnoZJCOl3Ijl_SHoSQWd-OpwrdLH1sA4OAZew12XmMEFt88Zv4jddg1r4mdU4bg9k6Jhg1SxtiHiH_qIsfUetsKWvnFw';

if (isset($data['type']) && $data['type'] === 'message_new') {
    $vk_id = $data['object']['message']['from_id'] ?? 0;
    $text = mb_strtolower(trim($data['object']['message']['text'] ?? ''));

    if ($vk_id > 0) {
        if ($text === 'тест' || $text === 'получить тест') {
            // Проверяем, использовал ли пользователь тест ранее в базе
            $stmt = $pdo->prepare("SELECT * FROM users WHERE vk_id = ?");
            $stmt->execute([$vk_id]);
            $user = $stmt->fetch();

            if ($user && ($user['trial_used'] == 1 || $user['tariff'] === 'Trial')) {
                sendVkMessage($vk_id, "⚠️ Вы уже использовали свой бесплатный тестовый период!", $vk_token);
            } else {
                // Текст сообщения с инструкцией и ссылкой на сайт
                $msg = "🎁 Хотите получить бесплатный тестовый период?\n\n" .
                       "1️⃣ Не забудьте подписаться на нашу группу!\n" .
                       "2️⃣ Перейдите на сайт: https://mdvpn.ru/\n" .
                       "3️⃣ Авторизуйтесь через VK ID в личном кабинете — и тестовый период активируется автоматически!\n\n" .
                       "🔗 Личный кабинет: https://mdvpn.ru/lk/";

                sendVkMessage($vk_id, $msg, $vk_token);
            }
        } else {
            sendVkMessage($vk_id, "Напишите слово «Тест», чтобы узнать, как получить бесплатный доступ.", $vk_token);
        }
    }
}

echo 'ok';

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