<?php
$rss_url = "https://www.cnews.ru/inc/rss/news.xml"; 
$json_file = "news.json";

// Ключевые слова для фильтрации
$keywords = ['VPN', 'блокировка', 'Роскомнадзор', 'протокол', 'интернет', 'YouTube'];

// Добавляем подавление ошибок при загрузке XML
$xml = @simplexml_load_file($rss_url);

if ($xml === false) {
    die("Ошибка загрузки RSS — проверьте доступ к серверу cnews.ru");
}

$news = [];
foreach ($xml->channel->item as $item) {
    $title = (string)$item->title;
    $desc = strip_tags((string)$item->description);
    
    // Проверка ключевых слов
    if (preg_match('/' . implode('|', $keywords) . '/i', $title . $desc)) {
        
        $footer_text = "\n\nЧтобы всегда оставаться на связи и обходить любые ограничения, используйте MDVPN — надежный сервис с современными протоколами шифрования.";
        
        $news[] = [
            'title' => $title,
            'link'  => (string)$item->link,
            'pub'   => (string)$item->pubDate,
            'desc'  => $desc . $footer_text 
        ];
    }
    if (count($news) >= 5) break; 
}

// Записываем данные только если массив не пуст
if (!empty($news)) {
    file_put_contents($json_file, json_encode($news, JSON_UNESCAPED_UNICODE));
    echo "Новости успешно обновлены!";
} else {
    echo "Новости найдены, но фильтр не подобрал совпадений.";
}
?>