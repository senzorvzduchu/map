<?php
// chmi-proxy.php s CACHOVÁNÍM

require_once __DIR__ . '/config.php';

// 1. Nastavení
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);
$cache_folder = __DIR__ . '/cache'; // Složka pro ukládání
$cache_lifetime = 900; // 900 sekund = 15 minut (bohatě stačí, data jsou 1h průměry)

// Definice povolených URL
$sources = [
    'metadata' => [
        'url' => 'https://opendata.chmi.cz/air_quality/now/metadata/metadata.json',
        'file' => 'chmi_metadata.json',
        'content_type' => 'application/json'
    ],
    'data' => [
        'url' => 'https://opendata.chmi.cz/air_quality/now/data/airquality_1h_avg_CZ.csv',
        'file' => 'chmi_data.csv',
        'content_type' => 'text/plain'
    ]
];

// 2. Kontrola požadavku
$request = isset($_GET['type']) ? $_GET['type'] : '';

if (!array_key_exists($request, $sources)) {
    http_response_code(400);
    die("Error: Invalid request. Only 'metadata' and 'data' are allowed.");
}

// Vytvoření cache složky, pokud neexistuje
if (!file_exists($cache_folder)) {
    mkdir($cache_folder, 0755, true);
}

$current_config = $sources[$request];
$local_file = $cache_folder . '/' . $current_config['file'];

// 3. Logika Cache
$fetch_new = true;

// Pokud soubor existuje a je čerstvý, použijeme ho
if (file_exists($local_file)) {
    $file_age = time() - filemtime($local_file);
    if ($file_age < $cache_lifetime) {
        $fetch_new = false;
    }
}

// 4. Stažení dat (pokud je třeba)
if ($fetch_new) {
    $content = @file_get_contents($current_config['url']);

    if ($content !== false) {
        // Uložíme na disk
        file_put_contents($local_file, $content);
    } else {
        // Pokud stažení selže, zkusíme poslat starou verzi (pokud existuje)
        if (!file_exists($local_file)) {
            http_response_code(502);
            die("Error: Could not fetch data from CHMI and no cache available.");
        }
        // Jinak pošleme starou verzi (lepší stará data než žádná)
    }
}

// 5. Odeslání souboru
header('Content-Type: ' . $current_config['content_type']);
// Přidáme hlavičku, abys viděl, jestli to jde z cache (pro debugging)
header('X-Source: ' . ($fetch_new ? 'CHMI-Live' : 'Local-Cache'));

readfile($local_file);
?>