<?php
// ag-proxy.php — AirGradient (veřejný světový feed) → CZ podmnožina pro mapu
// GET → { generated, fetched_at, stale, count, sensors: [ {id, name, latitude, longitude,
//          pm25, pm10, pm1, temperature, humidity, co2, model, measured_at, age_min, online} ] }
//
// Stejný vzor jako ostatní self-refreshing proxy (wind/alerts/forecast): cache
// cache/airgradient.json s TTL 3 min; první požadavek po expiraci ji pod zámkem
// (flock, neblokující) obnoví, souběžné požadavky dostanou starou kopii. Při
// chybě zdroje se vrací stará cache s příznakem stale=true. ?token=CRON_TOKEN
// vynutí obnovu (kdyby se někdy přidal URL cron), ?debug=1 ukáže diagnostiku.
//
// Zdroj je celý svět (~2 900 senzorů, ~1.5 MB gzip, bez tokenu); filtr na ČR je
// server-side (API bbox neumí) a cachuje se jen CZ výsledek (~30 senzorů, pár kB).
// Licence CC BY-SA 4.0 → mapa uvádí atribuci v legendě, popupu i modalu.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/AirGradient.php';

const AG_SOURCE_URL = 'https://api.airgradient.com/public/api/v1/world/locations/measures/current';
const AG_CACHE_TTL  = 180; // s

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_DOMAIN);

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/airgradient.json';
$lockFile  = $cacheDir . '/airgradient.lock';
if (!file_exists($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// 1. Čerstvá cache → rovnou ven
if (!$force && !$debug && file_exists($cacheFile) && time() - filemtime($cacheFile) < AG_CACHE_TTL) {
    header('X-Source: Local-Cache');
    readfile($cacheFile);
    exit;
}

// 2. Obnovu dělá jen jeden požadavek; ostatní dostanou starou kopii
$lock = Http::tryLock($lockFile);
if ($lock === null && !$debug) {
    serveStale($cacheFile, 'Local-Cache-Locked');
}

// 3. Stažení (gzip — bez komprese je feed ~10 MB)
$ch = curl_init(AG_SOURCE_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => '',          // Accept-Encoding: gzip, deflate (+ auto dekomprese)
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_USERAGENT      => USER_AGENT,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
]);
$raw      = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$world = ($raw !== false && $httpCode === 200) ? json_decode($raw, true) : null;
$ok    = is_array($world) && count($world) > 0;

if ($debug) {
    Http::unlock($lock);
    Http::jsonOut([
        'debug'       => true,
        'http_code'   => $httpCode,
        'curl_error'  => $curlErr,
        'raw_length'  => $raw !== false ? strlen($raw) : 0,
        'world_count' => $ok ? count($world) : 0,
        'cz_count'    => $ok ? count(AirGradient::normalise($world, time())) : 0,
        'cache_age_s' => file_exists($cacheFile) ? time() - filemtime($cacheFile) : null,
    ]);
}

if (!$ok) {
    error_log('ag-proxy.php: fetch failed http=' . $httpCode . ' err=' . $curlErr);
    Http::unlock($lock);
    serveStale($cacheFile, 'Stale-Cache');
}

// 4. Filtr + normalizace, atomický zápis, výdej
$now     = time();
$sensors = AirGradient::normalise($world, $now);
$payload = [
    'generated'  => gmdate('c', $now),
    'fetched_at' => gmdate('c', $now),
    'stale'      => false,
    'count'      => count($sensors),
    'sensors'    => $sensors,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
Http::atomicWrite($cacheFile, $json);
Http::unlock($lock);
header('X-Source: AirGradient-Live');
echo $json;
exit;

/** Vrátí starou cache označenou stale=true, nebo 502 když žádná není. */
function serveStale(string $cacheFile, string $sourceHeader): never
{
    $old = Http::readJson($cacheFile);
    if ($old === null) {
        Http::fail('AirGradient API nedostupné a žádná cache', 502);
    }
    $old['stale']     = true;
    $old['generated'] = gmdate('c');
    header('X-Source: ' . $sourceHeader);
    echo json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
