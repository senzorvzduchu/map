<?php
// sc-proxy.php - Umístěte do složky 'www/'
// Účel: Cachování dat ze Sensor.Community (prevence banu za rate-limit)

require_once __DIR__ . '/config.php';

// Nastavení
$cacheFile = 'sc_data_cache.json'; // Soubor, kam se data uloží
$apiUrl = 'https://data.sensor.community/airrohr/v1/filter/country=CZ';
$cacheTime = 300; // 300 sekund = 5 minut

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

// 1. Kontrola, zda máme čerstvá data v cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    readfile($cacheFile);
    exit;
}

// 2. Pokud jsou data stará, stáhneme nová
// Musíme poslat User-Agent, jinak nás API odmítne
$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: " . USER_AGENT . "\r\n"
    ]
];
$context = stream_context_create($options);
$data = @file_get_contents($apiUrl, false, $context);

// 3. Uložení a odeslání
if ($data !== false) {
    file_put_contents($cacheFile, $data);
    echo $data;
} else {
    // Fallback: Pokud API selže, zkusíme poslat alespoň starou cache
    if (file_exists($cacheFile)) {
        readfile($cacheFile);
    } else {
        http_response_code(502);
        echo json_encode(["error" => "Chyba stahování dat a žádná cache k dispozici"]);
    }
}
?>