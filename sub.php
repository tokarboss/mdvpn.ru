<?php
// Включаем отладку ошибок
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (ob_get_level()) ob_end_clean();

require_once '../db.php'; 

$device_id = intval($_GET['d'] ?? 0);
$user_id = intval($_GET['u'] ?? 0);

if (!$device_id || !$user_id) {
    header("HTTP/1.1 400 Bad Request");
    die("Ошибка: Не переданы параметры d или u");
}

// Получаем устройство и пользователя
$stmt = $pdo->prepare("
    SELECT ud.public_key, u.deadline, u.bridge_deadline 
    FROM user_devices ud 
    JOIN users u ON ud.user_id = u.id 
    WHERE ud.id = ? AND ud.user_id = ?
");
$stmt->execute([$device_id, $user_id]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userData) {
    header("HTTP/1.1 404 Not Found");
    die("Ошибка: Устройство или пользователь не найдены в БД");
}

$deviceUuid = $userData['public_key'];

// Проверяем активность подписок
$time_now = time();
$deadline_time = !empty($userData['deadline']) ? strtotime($userData['deadline']) : 0;
$bridge_time = !empty($userData['bridge_deadline']) ? strtotime($userData['bridge_deadline']) : 0;

$is_main_active = ($deadline_time > $time_now);
$is_bridge_active = ($bridge_time > $time_now);

// Выбираем сервера
if ($is_main_active && $is_bridge_active) {
    $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($is_bridge_active && !$is_main_active) {
    $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1 AND id = 7")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $servers = $pdo->query("SELECT * FROM servers WHERE is_active = 1 AND id != 7")->fetchAll(PDO::FETCH_ASSOC);
}

$all_links = [];
$mh = curl_multi_init();
$ch_array = [];

foreach ($servers as $index => $srv) {
    $baseUrl = rtrim(trim($srv['api_url'] ?? ''), '/');
    if (preg_match('/\/panel$/i', $baseUrl)) $baseUrl = substr($baseUrl, 0, -6);
    
    $ch = curl_init($baseUrl . "/panel/api/inbounds/list");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    
    $headers = ["Accept: application/json"];
    if (!empty($srv['api_token'])) {
        $headers[] = "Authorization: Bearer " . $srv['api_token'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $targetHost = !empty($srv['ip_address']) ? $srv['ip_address'] : parse_url($baseUrl, PHP_URL_HOST);

    $srvNameLower = mb_strtolower($srv['name'] ?? '');
    $flag = "🌐";
    if (strpos($srvNameLower, 'россия') !== false || strpos($srvNameLower, 'ru') !== false || $srv['id'] == 7) {
        $flag = "🇷🇺";
    } elseif (strpos($srvNameLower, 'германия') !== false || strpos($srvNameLower, 'germany') !== false || $srv['id'] == 2) {
        $flag = "🇩🇪";
    } elseif (strpos($srvNameLower, 'канада') !== false || strpos($srvNameLower, 'canada') !== false || $srv['id'] == 3) {
        $flag = "🇨🇦";
    } elseif (strpos($srvNameLower, 'нидерланды') !== false || strpos($srvNameLower, 'netherlands') !== false || $srv['id'] == 4) {
        $flag = "🇳🇱";
    } elseif (strpos($srvNameLower, 'казахстан') !== false || strpos($srvNameLower, 'kz') !== false) {
        $flag = "🇰🇿";    
    }

    curl_multi_add_handle($mh, $ch);
    $ch_array[$index] = [
        'ch' => $ch,
        'server_name' => $srv['name'] ?? ('Server #' . $srv['id']),
        'server_ip' => $targetHost,
        'flag' => $flag
    ];
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.1);
} while ($running > 0);

foreach ($ch_array as $index => $item) {
    $ch = $item['ch'];
    $response = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] && isset($data['obj'])) {
            $inbounds = $data['obj'];
            foreach ($inbounds as $inbound) {
                $port = intval($inbound['port'] ?? 443);
                $protocol = strtolower(trim($inbound['protocol'] ?? 'vless'));
                $serverName = $item['server_name'];
                $serverIp = $item['server_ip'];
                $flag = $item['flag'];
                
                // Фильтрация протоколов: Разрешаем Hysteria и VLESS
                if ($flag !== "🇷🇺" && strpos($protocol, 'hysteria') === false && strpos($protocol, 'vless') === false) {
                    continue;
                }
                
                $settingsRaw = $inbound['settings'] ?? [];
                $settingsJson = is_string($settingsRaw) ? json_decode($settingsRaw, true) : $settingsRaw;
                $clients = $settingsJson['clients'] ?? [];
                
                $foundClient = null;
                foreach ($clients as $client) {
                    $clientId = $client['id'] ?? $client['password'] ?? $client['auth'] ?? '';
                    $clientEmail = $client['email'] ?? '';
                    if ($clientId === $deviceUuid || strpos($clientEmail, 'dev_' . $user_id) !== false) {
                        $foundClient = $client;
                        break;
                    }
                }

                if ($foundClient) {
                    $streamRaw = $inbound['streamSettings'] ?? [];
                    $streamSettings = is_string($streamRaw) ? json_decode($streamRaw, true) : $streamRaw;
                    
                    $network = $streamSettings['network'] ?? 'tcp';
                    $security = $streamSettings['security'] ?? 'none';
                    $link = '';

                    // 1. ДИНАМИЧЕСКАЯ ГЕНЕРАЦИЯ VLESS (РЕАЛЬНЫЕ ДАННЫЕ ИЗ 3X-UI)
                    if ($protocol === 'vless') {
                        $flow = !empty($foundClient['flow']) ? $foundClient['flow'] : 'xtls-rprx-vision';
                        
                        $link = "vless://{$deviceUuid}@{$serverIp}:{$port}?type={$network}&security={$security}";
                        if (!empty($flow)) $link .= "&flow=" . urlencode($flow);
                        
                        if ($security === 'reality') {
                            $realitySettings = $streamSettings['realitySettings'] ?? [];
                            $resSettings = $realitySettings['settings'] ?? [];
                            
                            $pbk = $resSettings['publicKey'] ?? $realitySettings['publicKey'] ?? '';
                            
                            // Извлекаем shortId
                            $sid = '';
                            if (!empty($realitySettings['shortIds']) && is_array($realitySettings['shortIds'])) {
                                $sid = $realitySettings['shortIds'][0];
                            } elseif (!empty($realitySettings['shortId'])) {
                                $sid = $realitySettings['shortId'];
                            }

                            // Беру первый доступный serverName из массива 3X-UI, либо дефолт
                            $serverNamesArr = $realitySettings['serverNames'] ?? [];
                            $sni = !empty($serverNamesArr) ? $serverNamesArr[0] : 'google.com';

                            // Реальный spiderX из панели
                            $spx = $resSettings['spiderX'] ?? $realitySettings['spiderX'] ?? '/';

                            if (!empty($sni)) $link .= "&sni=" . urlencode($sni);
                            if (!empty($pbk)) $link .= "&pbk=" . urlencode($pbk);
                            if (!empty($sid)) $link .= "&sid=" . urlencode($sid);
                            if (!empty($spx)) $link .= "&spx=" . urlencode($spx);
                            $link .= "&fp=chrome";
                        } elseif ($security === 'tls') {
                            $tlsSettings = $streamSettings['tlsSettings'] ?? [];
                            if (!empty($tlsSettings['serverName'])) {
                                $link .= "&sni=" . urlencode($tlsSettings['serverName']);
                            }
                        }
                    } 
                    // 2. ДИНАМИЧЕСКАЯ ГЕНЕРАЦИЯ HYSTERIA / HYSTERIA 2
                    elseif (strpos($protocol, 'hysteria') !== false) {
                        $password = $foundClient['auth'] ?? $foundClient['password'] ?? $deviceUuid;
                        $link = "hysteria2://{$password}@{$serverIp}:{$port}?";
                        
                        $hy2Settings = $streamSettings['hy2Settings'] ?? $streamSettings['hysteriaSettings'] ?? [];
                        $tlsSettings = $streamSettings['tlsSettings'] ?? [];
                        $sni = $hy2Settings['sni'] ?? $tlsSettings['serverName'] ?? '';
                        
                        $queryParams = [];
                        
                        // Добавляем SNI только если он задан и не является заглушкой yahoo.com
                        if (!empty($sni) && strtolower($sni) !== 'yahoo.com') {
                            $queryParams[] = "sni=" . urlencode($sni);
                        }
                        $queryParams[] = "insecure=1";

                        $finalmask = $streamSettings['finalmask'] ?? [];
                        $udpMasks = $finalmask['udp'] ?? [];
                        foreach ($udpMasks as $mask) {
                            if (isset($mask['type']) && $mask['type'] === 'salamander') {
                                $obfsPassword = $mask['settings']['password'] ?? '';
                                if (!empty($obfsPassword)) {
                                    $queryParams[] = "obfs=salamander";
                                    $queryParams[] = "obfs-password=" . urlencode($obfsPassword);
                                }
                            }
                        }
                        
                        $link .= implode('&', $queryParams);
                    } 

                    if (!empty($link)) {
                        $link .= "#" . rawurlencode("{$flag} {$serverName} ({$protocol})");
                        $all_links[] = $link;
                    }
                }
            }
        }
    }
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

$clean_links = array_filter(array_map('trim', $all_links));

if (empty($clean_links)) {
    header("Content-Type: text/plain; charset=utf-8");
    die("Конфиги не найдены. Убедитесь, что устройство создано на серверах и подписка активна.");
}

$noticeText = "ВСТУПАЙ В ВК: vk.com/mdvpn_ru | LTE-обход 100р/мес";
$final_links = [];

foreach ($clean_links as $link) {
    $serverTitle = "MDVPN.RU Server";
    if (strpos($link, '#') !== false) {
        list($linkPart, $hashPart) = explode('#', $link, 2);
        $link = $linkPart;
        $serverTitle = urldecode($hashPart);
    }
    $serverTitle = preg_replace('/-dev_\d+_\d+.*$/', '', $serverTitle);
    $final_links[] = trim($link) . '#' . rawurlencode(trim($serverTitle) . " | " . $noticeText);
}

$final_content = implode("\n", $final_links);
$expireTimestamp = max($deadline_time, $bridge_time);
if ($expireTimestamp == 0) $expireTimestamp = time() + 86400;

header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("profile-title: base64:" . base64_encode("MDVPN.RU - WI-FI ИГРОВОЙ"));
header("subscription-userinfo: upload=0; download=0; total=107374182400; expire={$expireTimestamp}");

echo base64_encode($final_content);
exit;
?>