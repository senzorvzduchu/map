<?php
// sck-proxy.php - Umístěte do složky 'www/'
// Účel: Cachování dat ze Smart Citizen Network (aktivní CZ senzory)

require_once __DIR__ . '/config.php';

$cacheFile = 'sck_data_cache.json';
$cacheTime = 300; // 5 minut

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

if (!$debug && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    readfile($cacheFile);
    exit;
}

// Senzor musí hlásit data v posledních 24 hodinách
$activeThreshold = time() - (24 * 3600);

function sck_fetch($url) {
    $options = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: " . USER_AGENT . "\r\n" .
                        "Accept: application/vnd.smartcitizen; version=0,application/json\r\n"
        ]
    ];
    $context = stream_context_create($options);
    $data = @file_get_contents($url, false, $context);
    return $data ? json_decode($data, true) : null;
}

// Najde hodnotu senzoru podle vzoru v názvu (case-insensitive)
function findSensor($sensors, $namePattern) {
    foreach ($sensors as $s) {
        $name = strtolower($s['name'] ?? '');
        if (strpos($name, $namePattern) !== false && isset($s['value']) && $s['value'] !== null) {
            return floatval($s['value']);
        }
    }
    return null;
}

// CZ bounding box
$LAT_MIN = 48.5; $LAT_MAX = 51.2;
$LNG_MIN = 12.0; $LNG_MAX = 18.9;

// Fetch pages sorted by distance from CZ centre, stop when page has no CZ hits
$allDevices = [];
for ($page = 1; $page <= 5; $page++) {
    $url = "https://api.smartcitizen.me/v0/devices?near=49.8175,15.473&per_page=100&page={$page}";
    $pageDevices = sck_fetch($url);
    if (!is_array($pageDevices) || count($pageDevices) === 0) break;

    $hitOnPage = 0;
    foreach ($pageDevices as $d) {
        $lat = floatval($d['data']['location']['latitude'] ?? $d['location']['latitude'] ?? 0);
        $lng = floatval($d['data']['location']['longitude'] ?? $d['location']['longitude'] ?? 0);
        if ($lat >= $LAT_MIN && $lat <= $LAT_MAX && $lng >= $LNG_MIN && $lng <= $LNG_MAX) {
            $allDevices[] = $d;
            $hitOnPage++;
        }
    }
    if ($hitOnPage === 0) break;
}

$result = [];
$debugSkipped = [];

if (is_array($allDevices)) {
    foreach ($allDevices as $device) {
        $id = $device['id'];

        // Blacklist: senzory mimo ČR zachycené bounding boxem
        // 19547 = Warm Spark Cow (Wroclaw, PL)
        $sck_blacklist = [19547];
        if (in_array($id, $sck_blacklist)) {
            if ($debug) $debugSkipped[] = ['id' => $id, 'name' => $device['name'], 'reason' => 'blacklisted'];
            continue;
        }

        // Pouze venkovní senzory
        $exposure = $device['data']['location']['exposure']
            ?? $device['location']['exposure']
            ?? null;
        if ($exposure !== 'outdoor') {
            if ($debug) $debugSkipped[] = ['id' => $id, 'name' => $device['name'], 'reason' => 'not outdoor', 'exposure' => $exposure];
            continue;
        }

        // Pouze aktivní senzory (hlásily data v posledních 24h)
        $lastReading = $device['last_reading_at'] ?? null;
        if (!$lastReading) {
            if ($debug) $debugSkipped[] = ['id' => $id, 'name' => $device['name'], 'reason' => 'no last_reading_at'];
            continue;
        }
        $lastTs = strtotime($lastReading);
        if ($lastTs < $GLOBALS['activeThreshold']) {
            if ($debug) $debugSkipped[] = ['id' => $id, 'name' => $device['name'], 'reason' => 'inactive', 'last_reading_at' => $lastReading];
            continue;
        }

        // Souřadnice (již ověřeny bbox filtrem výše)
        $lat = $device['data']['location']['latitude']
            ?? $device['location']['latitude'];
        $lng = $device['data']['location']['longitude']
            ?? $device['location']['longitude'];

        // Hledáme PM2.5 podle vzoru v názvu senzoru
        $sensors = $device['data']['sensors'] ?? [];
        $pm25 = findSensor($sensors, '2.5');

        if ($pm25 === null) {
            if ($debug) {
                $sensorNames = array_map(fn($s) => ($s['name'] ?? '?') . '=' . ($s['value'] ?? 'null'), $sensors);
                $debugSkipped[] = ['id' => $id, 'name' => $device['name'], 'reason' => 'no PM2.5 value', 'sensors' => $sensorNames];
            }
            continue;
        }

        // Ostatní hodnoty
        $data = ['89' => $pm25];
        $pm1  = findSensor($sensors, 'pm 1');
        if ($pm1 === null) $pm1 = findSensor($sensors, 'pm1');
        $pm10 = findSensor($sensors, 'pm 10');
        if ($pm10 === null) $pm10 = findSensor($sensors, 'pm10');
        $temp = findSensor($sensors, 'temperature');
        $hum  = findSensor($sensors, 'humidity');

        if ($pm1  !== null) $data['87'] = $pm1;
        if ($pm10 !== null) $data['88'] = $pm10;
        if ($temp !== null) $data['55'] = $temp;
        if ($hum  !== null) $data['56'] = $hum;

        $result[] = [
            'id'        => $id,
            'name'      => $device['name'] ?? ('Smart Citizen #' . $id),
            'latitude'  => floatval($lat),
            'longitude' => floatval($lng),
            'exposure'  => $exposure,
            'state'     => $device['state'] ?? 'has_published',
            'data'      => $data,
        ];
    }
}

if ($debug) {
    echo json_encode([
        'cz_bbox_found'   => count($allDevices),
        'active_cz_count' => count($result),
        'skipped'         => $debugSkipped,
        'result'          => $result,
    ], JSON_PRETTY_PRINT);
    exit;
}

$json = json_encode($result);
file_put_contents($cacheFile, $json);
echo $json;
?>
