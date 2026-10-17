<?php
// chmi-other-proxy.php — 3.4: referenční stanice jiných provozovatelů než ČHMÚ
// (zdravotní ústavy, kraje, města, podniky) z hodinových vrstev map na chmi.cz.
// GET → { generated, slot, slot_end, stale, count, stations: [ {code, name, owner, lat, lon,
//          measured_at, slot, age_min, stale, values:{pm25,pm10,no2,o3}, value_times:{…}} ] }
//
// Stejný vzor jako ag-proxy.php: cache cache/chmi_other.json s TTL 10 min (zdroj se
// obnovuje jednou za hodinu, refreshInterval mapy ČHMÚ je 600 s); první požadavek po
// expiraci ji pod neblokujícím zámkem obnoví, souběžné dostanou starou kopii; při
// chybě zdroje se vrací stará cache se stale=true. ?token=CRON_TOKEN vynutí obnovu,
// ?debug=1 ukáže diagnostiku bez zápisu cache.
//
// Zdroj (nedokumentovaný backend map chmi.cz, podrobně lib/ChmiOther.php): pro poslední
// dostupný slot H (ověří se GET PM10; v první části hodiny ještě neexistuje → H−1 …) se
// stáhnou 4 vrstvy (PM10, PM2_5, NO2, O3) a totéž pro slot o hodinu starší, aby stanice,
// která v posledním slotu hlásí N/A, dostala poslední známou hodnotu (max 3 h zpět).
// 8 požadavků × ~100 kB, paralelně (Http::fetchMany), ~1–2 s. Licence CC BY 4.0 (ČHMÚ).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/ChmiOther.php';

const CO_CACHE_TTL = 600; // s

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_DOMAIN);

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/chmi_other.json';
$lockFile  = $cacheDir . '/chmi_other.lock';
if (!file_exists($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

// 1. Čerstvá cache → rovnou ven
if (!$force && !$debug && file_exists($cacheFile) && time() - filemtime($cacheFile) < CO_CACHE_TTL) {
    header('X-Source: Local-Cache');
    readfile($cacheFile);
    exit;
}

// 2. Obnovu dělá jen jeden požadavek; ostatní dostanou starou kopii
$lock = Http::tryLock($lockFile);
if ($lock === null && !$debug) {
    serveStale($cacheFile, 'Local-Cache-Locked');
}

$t0  = microtime(true);
$now = time();
$diag = ['probe' => [], 'layers' => []];

// 3. Nejnovější existující slot: zkouší se PM10 pro H, H−1, … (404 = ještě nevygenerováno)
$slot      = null;
$pm10Layer = null;
foreach (ChmiOther::slotCandidates($now) as $cand) {
    [$body, $code, $err] = Http::fetch(ChmiOther::layerUrl('PM10', $cand), 20);
    $json = ($body !== null && $code === 200) ? json_decode($body, true) : null;
    $ok   = is_array($json) && isset($json['features']) && is_array($json['features']) && count($json['features']) > 0;
    $diag['probe'][] = ['slot' => $cand, 'http' => $code, 'features' => $ok ? count($json['features']) : 0, 'err' => $err];
    if ($ok) {
        $slot      = $cand;
        $pm10Layer = $json;
        break;
    }
}

if ($slot === null) {
    error_log('chmi-other-proxy.php: žádný slot nedostupný: ' . json_encode($diag['probe']));
    if ($debug) {
        Http::unlock($lock);
        Http::jsonOut(['debug' => true, 'error' => 'no slot available', 'diag' => $diag,
            'cache_age_s' => file_exists($cacheFile) ? time() - filemtime($cacheFile) : null]);
    }
    Http::unlock($lock);
    serveStale($cacheFile, 'Stale-Cache');
}

// 4. Zbylé vrstvy pro slot S a všechny čtyři pro S−1 (fallback pro N/A)
$prev = ChmiOther::slot(ChmiOther::slotEnd($slot) - 3600);
$urls = [];
foreach (ChmiOther::LAYERS as $layer => $key) {
    if ($layer !== 'PM10') $urls[ChmiOther::layerUrl($layer, $slot)] = [$slot, $key];
    $urls[ChmiOther::layerUrl($layer, $prev)] = [$prev, $key];
}
$results = Http::fetchMany(array_keys($urls), 8, 20);

$bySlot = [$slot => ['pm10' => ChmiOther::parseLayer($pm10Layer, 'pm10')], $prev => []];
$diag['layers'][] = ['slot' => $slot, 'key' => 'pm10', 'http' => 200, 'stations' => count($bySlot[$slot]['pm10'])];
foreach ($urls as $url => [$s, $key]) {
    [$body, $code, $err] = $results[$url] ?? [null, 0, 'missing'];
    $json = ($body !== null && $code === 200) ? json_decode($body, true) : null;
    $parsed = is_array($json) ? ChmiOther::parseLayer($json, $key) : [];
    $bySlot[$s][$key] = $parsed;
    $diag['layers'][] = ['slot' => $s, 'key' => $key, 'http' => $code, 'stations' => count($parsed), 'err' => $err];
}

$stations = ChmiOther::merge($bySlot, $now);

if ($debug) {
    Http::unlock($lock);
    Http::jsonOut([
        'debug'       => true,
        'slot'        => $slot,
        'slot_end'    => gmdate('c', ChmiOther::slotEnd($slot)),
        'prev_slot'   => $prev,
        'count'       => count($stations),
        'with_pm25'   => count(array_filter($stations, fn($s) => isset($s['values']['pm25']))),
        'with_pm10'   => count(array_filter($stations, fn($s) => isset($s['values']['pm10']))),
        'stale_count' => count(array_filter($stations, fn($s) => $s['stale'])),
        'missing'     => array_values(array_diff(array_keys(ChmiOther::STATIONS), array_column($stations, 'code'))),
        'elapsed_ms'  => (int)round((microtime(true) - $t0) * 1000),
        'diag'        => $diag,
        'cache_age_s' => file_exists($cacheFile) ? time() - filemtime($cacheFile) : null,
    ]);
}

if (count($stations) === 0) {
    error_log('chmi-other-proxy.php: slot ' . $slot . ' bez jediné hodnoty sledovaných stanic');
    Http::unlock($lock);
    serveStale($cacheFile, 'Stale-Cache');
}

// 5. Atomický zápis, výdej
$payload = [
    'generated' => gmdate('c', $now),
    'slot'      => $slot,
    'slot_end'  => gmdate('c', ChmiOther::slotEnd($slot)),
    'stale'     => false,
    'count'     => count($stations),
    'stations'  => $stations,
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
        Http::fail('data-provider.chmi.cz nedostupný a žádná cache', 502);
    }
    $old['stale']     = true;
    $old['generated'] = gmdate('c');
    header('X-Source: ' . $sourceHeader);
    echo json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
