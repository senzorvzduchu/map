<?php
// libcich-proxy.php - Projekt Libčich (libcich.cz) proxy
// Stejný pattern jako pa-proxy.php: cache + fallback
//
// Dva režimy (?mode=):
//   aq  (default) - živá data pro mapu z /api/aq (nejnovější 2-min interval per senzor)
//   aqi           - poslední UZAVŘENÁ hodina z /api/aqi pro kolektor historie
//
// POZOR: API parametry year/month/day/hour jsou v LOKÁLNÍM čase (Europe/Prague),
// ale klíče intervalů v odpovědi jsou UTC ("...Z"). Ověřeno proti živému API.

require_once __DIR__ . '/config.php';

// Čistá serializace floatů (jinak hosting s jiným nastavením vypisuje 50.19762038999999731...)
ini_set('serialize_precision', '-1');

$mode = (isset($_GET['mode']) && $_GET['mode'] === 'aqi') ? 'aqi' : 'aq';

// Nastavení dle režimu
if ($mode === 'aq') {
    $cacheFile = 'libcich_data_cache.json';
    $cacheTime = 120;  // data jsou 2-min, 120 s je slušné k jejich serveru
} else {
    $cacheFile = 'libcich_aqi_cache.json';
    $cacheTime = 600;  // hodinové agregáty, kolektor běží po 5 min
}

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

// 1. Kontrola čerstvé cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime) && !isset($_GET['debug'])) {
    readfile($cacheFile);
    exit;
}

// ==========================================
// FETCH HELPER (cURL — Active24 blokuje custom headers přes stream context)
// ==========================================
function libcichFetch($url, &$httpCode, &$curlError) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    if ($httpCode !== 200 || $raw === false) return false;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : false;
}

// Merge 2-min intervalů chronologicky — novější přepíše starší, každý senzor skončí
// se svou poslední REÁLNOU hodnotou.
// POZOR (ověřeno proti živému API): bucket aktuální hodiny obsahuje předvytvořený
// BUDOUCÍ hraniční interval (konec hodiny) s placeholder záznamy (vše 0, prázdné devId,
// lat/lng 0). Ty se musí přeskočit, jinak přepíšou reálná data.
function mergeAqIntervals($intervals, &$byEid) {
    $keys = array_keys($intervals);
    sort($keys); // ISO-8601 UTC řetězce → lexikografické řazení = chronologické
    foreach ($keys as $k) {
        $intervalTs = strtotime($k);
        if ($intervalTs === false || $intervalTs > time()) continue; // budoucí boundary interval
        if (!is_array($intervals[$k])) continue;
        foreach ($intervals[$k] as $eid => $entry) {
            if (!is_array($entry)) continue;
            // placeholder záznam: bez souřadnic / bez devId → nepřepisovat reálná data
            if (empty($entry['latitude']) || empty($entry['longitude'])) continue;
            $entry['_ts'] = $k;
            $byEid[$eid] = $entry;
        }
    }
}

$tz = new DateTimeZone('Europe/Prague');
$debugInfo = ['mode' => $mode, 'fetched' => []];

// ==========================================
// MODE: AQ — živá data (nejnovější 2-min hodnota per senzor)
// ==========================================
if ($mode === 'aq') {
    $now = new DateTime('now', $tz);

    $buildUrl = function (DateTime $dt) {
        return 'https://libcich.cz/api/aq?' . http_build_query([
            'year'  => (int)$dt->format('Y'),
            'month' => (int)$dt->format('n'),
            'day'   => (int)$dt->format('j'),
            'hour'  => (int)$dt->format('G'),
        ]);
    };

    // Na začátku hodiny je aktuální bucket ještě (skoro) prázdný → přibrat předchozí hodinu.
    // Merge v chronologickém pořadí: novější interval přepíše starší → každý senzor má svou poslední hodnotu.
    $byEid = [];
    $anySuccess = false;

    $hours = [];
    if ((int)$now->format('i') < 10) {
        $hours[] = (clone $now)->modify('-1 hour');
    }
    $hours[] = $now;

    foreach ($hours as $dt) {
        $url = $buildUrl($dt);
        $json = libcichFetch($url, $httpCode, $curlError);
        $debugInfo['fetched'][] = ['url' => $url, 'http_code' => $httpCode, 'error' => $curlError, 'ok' => $json !== false];
        if ($json === false || empty($json['intervals'])) continue;
        $anySuccess = true;

        mergeAqIntervals($json['intervals'], $byEid);
    }

    // Záchrana: aktuální hodina úspěšná, ale prázdná (a předchozí jsme netahali) → zkusit předchozí
    if ($anySuccess && empty($byEid) && (int)$now->format('i') >= 10) {
        $dt = (clone $now)->modify('-1 hour');
        $url = $buildUrl($dt);
        $json = libcichFetch($url, $httpCode, $curlError);
        $debugInfo['fetched'][] = ['url' => $url, 'http_code' => $httpCode, 'error' => $curlError, 'ok' => $json !== false];
        if ($json !== false && !empty($json['intervals'])) {
            mergeAqIntervals($json['intervals'], $byEid);
        }
    }

    $sensors = [];
    foreach ($byEid as $eid => $e) {
        $lat = $e['latitude'] ?? null;
        $lng = $e['longitude'] ?? null;
        if (empty($lat) || empty($lng)) continue; // null i placeholder 0

        $sensors[] = [
            'id'          => (string)$eid,                       // devEid — stabilní HW ID (klíč markeru)
            'name'        => isset($e['devId']) ? (string)$e['devId'] : (string)$eid,
            'latitude'    => (float)$lat,
            'longitude'   => (float)$lng,
            'pm25'        => isset($e['pm2_5'])  ? round((float)$e['pm2_5'], 1)  : null,
            'pm10'        => isset($e['pm10_0']) ? round((float)$e['pm10_0'], 1) : null,
            'pm1'         => isset($e['pm1_0'])  ? round((float)$e['pm1_0'], 1)  : null,
            'temperature' => isset($e['temperature']) ? round((float)$e['temperature'], 1) : null,
            'humidity'    => isset($e['humidity'])    ? round((float)$e['humidity'], 0)    : null,
            'battery'     => isset($e['battery'])     ? (float)$e['battery']               : null,
            'annotation'  => isset($e['annotation_code']) ? (string)$e['annotation_code'] : '',
            'measured_at' => $e['_ts'],                          // ISO UTC klíč 2-min intervalu
        ];
    }
}

// ==========================================
// MODE: AQI — poslední uzavřená hodina (pro collect.php)
// ==========================================
else {
    $buildUrl = function (DateTime $dt) {
        return 'https://libcich.cz/api/aqi?' . http_build_query([
            'year'  => (int)$dt->format('Y'),
            'month' => (int)$dt->format('n'),
            'day'   => (int)$dt->format('j'),
        ]);
    };

    // Poslední uzavřená hodina = klíč intervalu + 3600 <= now.
    // Po půlnoci (lokální) může dnešek uzavřenou hodinu neobsahovat → fallback na včerejšek.
    $sensors = [];
    $anySuccess = false;

    foreach ([new DateTime('now', $tz), (new DateTime('now', $tz))->modify('-1 day')] as $dt) {
        $url = $buildUrl($dt);
        $json = libcichFetch($url, $httpCode, $curlError);
        $debugInfo['fetched'][] = ['url' => $url, 'http_code' => $httpCode, 'error' => $curlError, 'ok' => $json !== false];
        if ($json === false || empty($json['intervals'])) continue;
        $anySuccess = true;

        $keys = array_keys($json['intervals']);
        rsort($keys); // od nejnovějšího
        foreach ($keys as $k) {
            $intervalTs = strtotime($k);
            if ($intervalTs === false || $intervalTs + 3600 > time()) continue; // hodina ještě běží
            if (!is_array($json['intervals'][$k]) || count($json['intervals'][$k]) === 0) continue;

            foreach ($json['intervals'][$k] as $eid => $e) {
                if (!is_array($e)) continue;
                $lat = $e['latitude'] ?? null;
                $lng = $e['longitude'] ?? null;
                if (empty($lat) || empty($lng)) continue; // null i placeholder 0

                $sensors[] = [
                    'id'          => (string)$eid,
                    'name'        => isset($e['devId']) ? (string)$e['devId'] : (string)$eid,
                    'latitude'    => (float)$lat,
                    'longitude'   => (float)$lng,
                    'pm25'        => isset($e['pm2_5']) ? round((float)$e['pm2_5'], 2) : null,
                    'pm10'        => isset($e['pm10'])  ? round((float)$e['pm10'], 2)  : null,
                    'pm25_aqi'    => $e['pm2_5Aqi'] ?? null,
                    'pm10_aqi'    => $e['pm10Aqi']  ?? null,
                    'tags'        => isset($e['tags']) && is_array($e['tags']) ? $e['tags'] : [],
                    'interval'    => $k,            // ISO UTC začátek hodiny
                    'measured_at' => $intervalTs,   // unix timestamp začátku hodiny (pro collect.php)
                ];
            }
            break; // máme nejnovější uzavřenou hodinu
        }
        if (!empty($sensors)) break; // den s daty nalezen, včerejšek netřeba
    }
}

// Debug mode — zobrazí diagnostiku bez cache fallbacku
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    $debugInfo['sensor_count'] = count($sensors);
    $debugInfo['sample'] = array_slice($sensors, 0, 3);
    echo json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. Při chybě API → fallback na starou cache
if (empty($sensors)) {
    if (file_exists($cacheFile)) {
        readfile($cacheFile);
    } else {
        http_response_code(502);
        echo json_encode(["error" => "libcich.cz API nedostupné a žádná cache"]);
    }
    exit;
}

// 4. Uložit cache a odeslat
$output = json_encode($sensors, JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $output);
echo $output;
?>
