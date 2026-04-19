<?php
// pa-proxy.php - PurpleAir proxy s EPA korekcí PM2.5
// Stejný pattern jako sc-proxy.php: cache + fallback

require_once __DIR__ . '/config.php';

// Nastavení
$cacheFile = 'pa_data_cache.json';
$cacheTime = 900; // 900 sekund = 15 minut

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

// 1. Kontrola čerstvé cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    readfile($cacheFile);
    exit;
}

// 2. Stáhnout data z PurpleAir API (CZ bounding box, outdoor senzory)
$fields = 'name,latitude,longitude,pm2.5_cf_1_a,pm2.5_cf_1_b,pm10.0_atm,humidity,temperature,last_seen';
$params = http_build_query([
    'fields'        => $fields,
    'location_type' => 0,       // outdoor only
    'max_age'       => 3600,    // viděno v posledních 60 min
    'nwlat'         => 51.06,   // CZ bounding box (zúžený)
    'nwlng'         => 12.10,
    'selat'         => 48.55,
    'selng'         => 18.87,
]);

$apiUrl = 'https://api.purpleair.com/v1/sensors?' . $params;

// cURL místo file_get_contents — Active24 blokuje custom headers přes stream context
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'X-API-Key: ' . PURPLEAIR_API_KEY,
    ],
    CURLOPT_USERAGENT      => USER_AGENT,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Debug mode — zobrazí chyby bez cache fallbacku
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo json_encode([
        'debug'     => true,
        'http_code' => $httpCode,
        'curl_error'=> $curlError,
        'url'       => $apiUrl,
        'raw_length'=> $raw !== false ? strlen($raw) : 0,
        'raw_preview'=> $raw !== false ? substr($raw, 0, 500) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Pokud HTTP kód není 200, považujeme za chybu
if ($httpCode !== 200 || $raw === false) {
    $raw = false;
}

// 3. Při chybě API → fallback na starou cache
if ($raw === false) {
    if (file_exists($cacheFile)) {
        readfile($cacheFile);
    } else {
        http_response_code(502);
        echo json_encode(["error" => "PurpleAir API nedostupné a žádná cache"]);
    }
    exit;
}

$response = json_decode($raw, true);
if (!isset($response['fields']) || !isset($response['data'])) {
    if (file_exists($cacheFile)) {
        readfile($cacheFile);
    } else {
        http_response_code(502);
        echo json_encode(["error" => "Neplatná odpověď z PurpleAir API"]);
    }
    exit;
}

// 4. Normalizace — mapovat pole podle 'fields' indexu
$idx = array_flip($response['fields']);
$sensors = [];

foreach ($response['data'] as $row) {
    $sensorIndex = $row[0]; // sensor_index je vždy první
    $name      = $row[$idx['name']]      ?? null;
    $lat       = $row[$idx['latitude']]   ?? null;
    $lon       = $row[$idx['longitude']]  ?? null;
    $cf1_a     = $row[$idx['pm2.5_cf_1_a']] ?? null;
    $cf1_b     = $row[$idx['pm2.5_cf_1_b']] ?? null;
    $pm10      = $row[$idx['pm10.0_atm']]   ?? null;
    $humidity  = $row[$idx['humidity']]      ?? null;
    $tempF     = $row[$idx['temperature']]   ?? null;
    $lastSeen  = $row[$idx['last_seen']]     ?? null;

    if ($lat === null || $lon === null) continue;

    // Blacklist: senzory mimo ČR zachycené bounding boxem
    // 4619 = Toms PurpleAir (Regensburg, DE), 32145 = PurpleAir Dresden (DE),
    // 171945 = BBB39 (Dresden, DE), 60443 = Neudorf im Weinviertel (AT),
    // 202867 = Borský Mikuláš (SK)
    $blacklist = [4619, 32145, 171945, 60443, 202867];
    if (in_array($sensorIndex, $blacklist)) continue;

    // PM2.5: průměr kanálů A+B, pak EPA korekce (Barkjohn 2021)
    $pm25_raw = null;
    $pm25_corrected = null;
    $confidence = 'ok';

    if ($cf1_a !== null && $cf1_b !== null) {
        // Kontrola shody kanálů
        $diff = abs($cf1_a - $cf1_b);
        if ($diff > max(5, $cf1_a * 0.7)) {
            $confidence = 'low';
        }
        $pm25_raw = ($cf1_a + $cf1_b) / 2;
    } elseif ($cf1_a !== null) {
        $pm25_raw = $cf1_a;
        $confidence = 'low';
    } elseif ($cf1_b !== null) {
        $pm25_raw = $cf1_b;
        $confidence = 'low';
    }

    if ($pm25_raw !== null) {
        if ($humidity !== null) {
            // EPA Barkjohn 2021: PM2.5_corrected = 0.524 * CF1 - 0.0862 * RH + 5.75
            $pm25_corrected = round(max(0, 0.524 * $pm25_raw - 0.0862 * $humidity + 5.75), 1);
        } else {
            // Bez vlhkosti nelze korekci aplikovat → raw hodnota
            $pm25_corrected = round(max(0, $pm25_raw), 1);
            $confidence = 'low';
        }
    }

    // Teplota: Fahrenheit → Celsius
    $tempC = ($tempF !== null) ? round(($tempF - 32) * 5 / 9, 1) : null;

    $sensors[] = [
        'id'         => $sensorIndex,
        'name'       => $name,
        'latitude'   => $lat,
        'longitude'  => $lon,
        'pm25'       => $pm25_corrected,
        'pm25_raw'   => ($pm25_raw !== null) ? round($pm25_raw, 1) : null,
        'pm10'       => ($pm10 !== null) ? round($pm10, 1) : null,
        'temperature'=> $tempC,
        'humidity'   => ($humidity !== null) ? round($humidity, 0) : null,
        'confidence' => $confidence,
        'last_seen'  => $lastSeen,
    ];
}

// 5. Uložit cache a odeslat
$output = json_encode($sensors, JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $output);
echo $output;
?>
