<?php
// Отключаем ограничение по времени выполнения скрипта
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

$vk_token = 'vk1.a.rYp6bA5hbb0pTT5ndssoQ1ei2nDeYxVX6Wd_uduJ3khKwamNDBcAKATXybiHeADEHi9DOoBUJJWLjVALGL9WY6osngwqEmhhzdjljJ7gKXG8iHguO_e7UVx7NtsnoZJCOl3Ijl_SHoSQWd-OpwrdLH1sA4OAZew12XmMEFt88Zv4jddg1r4mdU4bg9k6Jhg1SxtiHiH_qIsfUetsKWvnFw';

// 1. Получаем server, key и ts для подключения к Long Poll
function getLongPollServer($token) {
    $url = "https://api.vk.com/method/groups.getLongPollServer?group_id=238280166&access_token={$token}&v=5.131";
    $res = @file_get_contents($url);
    $data = json_decode($res, true);
    return $data['response'] ?? null;
}

$lpData = getLongPollServer($vk_token);
if (!$lpData) {
    die("Ошибка получения Long Poll сервера. Проверьте токен группы.\n");
}

$server = $lpData['server'];
$key = $lpData['key'];
$ts = $lpData['ts'];

echo "Long Poll запущен и слушает сообщения...\n";

// 2. Бесконечный цикл опроса серверов ВК
while (true) {
    $url = "{$server}?act=a_check&key={$key}&ts={$ts}&wait=25";
    $response = @file_get_contents($url);

    if (!$response) {
        sleep(2);
        continue;
    }

    $data = json_decode($response, true);

    // Если устарел ts или ключ, обновляем данные подключения
    if (isset($data['failed'])) {
        $lpData = getLongPollServer($vk_token);
        if ($lpData) {
            $server = $lpData['server'];
            $key = $lpData['key'];
            $ts = $lpData['ts'];
        }
        sleep(2);
        continue;
    }

    $ts = $data['ts'] ?? $ts;
    $updates = $data['updates'] ?? [];

    foreach ($updates as $event) {
        if ($event['type'] === 'message_new') {
            $message = $event['object']['message'] ?? [];
            $vk_id = $message['from_id'] ?? 0;
            $text = mb_strtolower(trim($message['text'] ?? ''));

            if ($vk_id > 0) {
                if ($text === 'тест' || $text === 'получить тест') {
                    // Проверяем статус пользователя в БД
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE vk_id = ?");
                    $stmt->execute([$vk_id]);
                    $user = $stmt->fetch();

                    if ($user && ($user['trial_used'] == 1 || $user['tariff'] === 'Trial')) {
                        sendVkMessage($vk_id, "⚠️ Вы уже использовали свой бесплатный тестовый период!", $vk_token);
                    } else {
                        // Сообщение с инструкцией по переходу на сайт
                        $msg = "🎁 Чтобы получить бесплатный тестовый период:\n\n" .
                               "1️⃣ Не забудьте подписаться на нашу группу!\n" .
                               "2️⃣ Перейдите на сайт: https://mdvpn.ru/\n" .
                               "3️⃣ Авторизуйтесь через VK ID в личном кабинете и заберите свой тест (если еще не делали этого).\n\n" .
                               "🔗 Личный кабинет: https://mdvpn.ru/lk/";

                        sendVkMessage($vk_id, $msg, $vk_token);
                    }
                } else {
                    sendVkMessage($vk_id, "Напишите слово «Тест», чтобы узнать, как получить бесплатный доступ на 2 дня.", $vk_token);
                }
            }
        }
    }
}

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