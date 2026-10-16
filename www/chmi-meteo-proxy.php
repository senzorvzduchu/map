<?php
// chmi-meteo-proxy.php — ČHMÚ meteorologické stanice (otevřená 10min data) → teplota
// a vlhkost pro mapu jako REFERENČNÍ síť vedle komunitních senzorů.
// GET → { generated, fetched_at, stale, count, stations: [ {wsi, name, lat, lng, elevation,
//          temperature, humidity, measured_at, age_min, stale} ] }
//
// Stejný vzor jako ag-proxy / wind / alerts / forecast: cache cache/chmi_meteo.json
// s TTL 15 min; první požadavek po expiraci ji pod zámkem (flock, neblokující) obnoví,
// souběžné požadavky dostanou starou kopii. Při chybě zdroje se vrací stará cache
// s příznakem stale=true. ?token=CRON_TOKEN vynutí obnovu, ?debug=1 ukáže diagnostiku.
//
// Zdroj (lib/ChmiMeteo.php): jeden JSON na STANICI a DEN (~80–150 kB, řádky za celý
// den UTC se v něm hromadí), žádný souhrnný soubor → stahuje se jen podmnožina
// ChmiMeteo::STATIONS (~60 stanic, paralelně přes Http::fetchMany). Metadata stanic
// (meta1, ~75 kB, souřadnice) se stahují 1× denně do cache/chmi_meteo_meta.json.
// Po půlnoci UTC dnešní soubory chvíli neexistují → fallback na včerejší den.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/ChmiMeteo.php';

const CHMI_METEO_CACHE_TTL = 900;        // s (15 min; zdroj má 10min krok a ~15 min zpoždění)
const CHMI_METEO_META_TTL  = 24 * 3600;  // s (seznam stanic se mění zřídka)
const CHMI_METEO_CONCURRENCY = 8;        // paralelních stažení
const CHMI_METEO_TIMEOUT     = 12;       // s na jeden soubor

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_DOMAIN);

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/chmi_meteo.json';
$metaFile  = $cacheDir . '/chmi_meteo_meta.json';
$lockFile  = $cacheDir . '/chmi_meteo.lock';
if (!file_exists($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// 1. Čerstvá cache → rovnou ven
if (!$force && !$debug && file_exists($cacheFile) && time() - filemtime($cacheFile) < CHMI_METEO_CACHE_TTL) {
    header('X-Source: Local-Cache');
    readfile($cacheFile);
    exit;
}

// 2. Obnovu dělá jen jeden požadavek; ostatní dostanou starou kopii
$lock = Http::tryLock($lockFile);
if ($lock === null && !$debug) {
    serveStale($cacheFile, 'Local-Cache-Locked');
}

$t0   = microtime(true);
$now  = time();
$diag = ['meta' => null, 'stations' => []];

// 3. Metadata stanic (1× denně; při chybě stará kopie)
$meta = null;
$metaCached = Http::readJson($metaFile);
$metaFresh  = $metaCached !== null && isset($metaCached['stations']) && !$force
    && time() - filemtime($metaFile) < CHMI_METEO_META_TTL;
if ($metaFresh) {
    $meta = $metaCached['stations'];
    $diag['meta'] = ['source' => 'cache', 'age_s' => time() - filemtime($metaFile), 'count' => count($meta)];
} else {
    foreach (ChmiMeteo::dates($now) as $ymd) {
        [$body, $code, $err] = Http::fetch(ChmiMeteo::metaUrl($ymd), 20);
        $diag['meta'] = ['source' => 'live', 'date' => $ymd, 'http_code' => $code, 'curl_error' => $err,
                         'bytes' => $body !== null ? strlen($body) : 0];
        if ($body === null || $code !== 200) continue;
        $json = json_decode($body, true);
        $parsed = is_array($json) ? ChmiMeteo::parseMeta($json) : [];
        if (count($parsed) > 0) {
            $meta = $parsed;
            $diag['meta']['count'] = count($meta);
            Http::atomicWrite($metaFile, json_encode(['generated' => gmdate('c', $now), 'date' => $ymd, 'stations' => $meta],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            break;
        }
    }
    if ($meta === null && $metaCached !== null && isset($metaCached['stations'])) {
        $meta = $metaCached['stations']; // stará metadata jsou lepší než žádná
        $diag['meta']['fallback'] = 'stale-cache';
    }
}

// 4. 10min soubory stanic: dnešek paralelně, pro chybějící (404 / prázdné) včerejšek
$readings = [];
$wsis     = array_keys(ChmiMeteo::STATIONS);
$pending  = $wsis;
foreach (ChmiMeteo::dates($now) as $ymd) {
    if (!$pending) break;
    $urls = [];
    foreach ($pending as $wsi) $urls[$wsi] = ChmiMeteo::stationUrl($wsi, $ymd);
    $res = Http::fetchMany(array_values($urls), CHMI_METEO_CONCURRENCY, CHMI_METEO_TIMEOUT);
    $next = [];
    foreach ($urls as $wsi => $url) {
        [$body, $code, $err] = $res[$url] ?? [null, 0, 'missing'];
        $parsed = [];
        if ($body !== null && $code === 200) {
            $json = json_decode($body, true);
            if (is_array($json)) $parsed = ChmiMeteo::parseStation($json);
        }
        $diag['stations'][$wsi][$ymd] = ['http_code' => $code, 'curl_error' => $err,
            'bytes' => $body !== null ? strlen($body) : 0, 'T' => isset($parsed['T']), 'H' => isset($parsed['H'])];
        if ($parsed) {
            $readings[$wsi] = $parsed;
        } else {
            $next[] = $wsi; // zkusit předchozí den
        }
    }
    $pending = $next;
}

$stations = ($meta !== null) ? ChmiMeteo::build($meta, $readings, $now) : [];
$ok = count($stations) > 0;
$elapsed = round(microtime(true) - $t0, 2);

if ($debug) {
    Http::unlock($lock);
    Http::jsonOut([
        'debug'         => true,
        'elapsed_s'     => $elapsed,
        'now'           => gmdate('c', $now),
        'dates_tried'   => ChmiMeteo::dates($now),
        'configured'    => count($wsis),
        'with_reading'  => count($readings),
        'in_output'     => count($stations),
        'missing'       => array_values(array_diff($wsis, array_keys($readings))),
        'meta'          => $diag['meta'],
        'cache_age_s'   => file_exists($cacheFile) ? time() - filemtime($cacheFile) : null,
        'stations'      => $diag['stations'],
    ]);
}

if (!$ok) {
    error_log('chmi-meteo-proxy.php: no stations (meta=' . json_encode($diag['meta']) . ')');
    Http::unlock($lock);
    serveStale($cacheFile, 'Stale-Cache');
}

// 5. Atomický zápis, výdej
$payload = [
    'generated'  => gmdate('c', $now),
    'fetched_at' => gmdate('c', $now),
    'stale'      => false,
    'count'      => count($stations),
    'elapsed_s'  => $elapsed,
    'stations'   => $stations,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
Http::atomicWrite($cacheFile, $json);
Http::unlock($lock);
header('X-Source: CHMI-Live');
echo $json;
exit;

/** Vrátí starou cache označenou stale=true, nebo 502 když žádná není. */
function serveStale(string $cacheFile, string $sourceHeader): never
{
    $old = Http::readJson($cacheFile);
    if ($old === null) {
        Http::fail('Otevřená data ČHMÚ nedostupná a žádná cache', 502);
    }
    $old['stale']     = true;
    $old['generated'] = gmdate('c');
    header('X-Source: ' . $sourceHeader);
    echo json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
