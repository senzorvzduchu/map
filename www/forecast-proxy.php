<?php
// forecast-proxy.php — modelová předpověď ČHMÚ (boxploty, úroveň KRAJ) pro graf v popupu
//
//   forecast-proxy.php?area=<NUTS3>&type=PM10[&sensor=SC_123]
//
// Stejná logika jako nosicheck.cz (cron/refresh_forecast.php + api/forecast.php),
// bez cronu: cache (cache/forecast/kraj_<code>.json + meta.json) se obnovuje při
// prvním požadavku po 30 minutách a stahuje se jen když ČHMÚ vydal nový běh
// (4 ZIPy ≈ 280 kB). Křivka je před výdejem UKOTVENA na úroveň senzoru
// (průměr posledních 3 h), jinak na pozorovanou mapu ČHMÚ pro kraj, jinak jde
// ven surová (anchored=false). Aritmetika je v lib/Forecast.php (testy v tests/).
// ?token=CRON_TOKEN vynutí kontrolu nového běhu (volitelný cron).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Forecast.php';

header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

$dir      = __DIR__ . '/cache/forecast';
$metaFile = $dir . '/meta.json';
$lockFile = $dir . '/build.lock';
$refreshAfter = 1800;
$now = time();

if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

// --- 1. Obnovit cache, když je čas ---------------------------------------------
$meta = Http::readJson($metaFile);
$force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
$due = $meta === null || ($now - (int)($meta['generated'] ?? 0)) > $refreshAfter;

if ($due || $force) {
    $lock = Http::tryLock($lockFile);
    if ($lock !== null) {
        refreshForecast($dir, $metaFile, $meta, $now);
        Http::unlock($lock);
        $meta = Http::readJson($metaFile);
    }
}

if ($force && !isset($_GET['area'])) {
    Http::jsonOut(['ok' => true, 'run' => $meta['run'] ?? null, 'areas' => count($meta['areas'] ?? [])]);
}

// --- 2. Výdej pro jednu oblast a látku -----------------------------------------
$area = (string)($_GET['area'] ?? '');
if (!preg_match('/^\d{1,4}\z/', $area)) {
    Http::fail('Neplatný kód oblasti.', 400);
}
$valueType = (string)($_GET['type'] ?? 'PM2.5');
if (!in_array($valueType, Forecast::COMPONENTS, true)) {
    Http::fail('Pro tuto veličinu se předpověď nepublikuje.', 400);
}

$data = Http::readJson($dir . '/kraj_' . $area . '.json');
if ($data === null || !isset($data['series'][$valueType])) {
    Http::fail('Předpověď ČHMÚ zatím není k dispozici.', 404);
}
$generated = (int)($data['generated'] ?? 0);
if ($now - $generated > Forecast::MAX_RUN_AGE_SEC) {
    Http::fail('Předpověď ČHMÚ je zastaralá.', 503);
}

$series = $data['series'][$valueType];
$obs    = $series['obs'] ?? null;
unset($series['obs']);

// Úroveň senzoru za poslední 3 h (z historie)
$observed = null;
$sensorId = (string)($_GET['sensor'] ?? '');
if ($sensorId !== '' && preg_match('/^(SC_\d+|CHMI_[A-Z0-9_]+|SCK_\d+|PA_\d+|LIBCICH_[A-F0-9]{8,32})\z/', $sensorId)) {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/historygraph/air_quality_history.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('SELECT AVG(value) FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at >= ?');
        $stmt->execute([$sensorId, $valueType, $now - Forecast::ANCHOR_HOURS * 3600]);
        $avg = $stmt->fetchColumn();
        if ($avg !== false && $avg !== null) {
            $observed = round((float)$avg, 1);
        }
    } catch (Throwable $e) {
        error_log('forecast-proxy.php: ' . $e->getMessage());
    }
}

$base   = Forecast::modelBase($series['p50']);
$factor = 1.0;
$source = null;
if ($observed !== null) {
    $factor = Forecast::anchorFactor($observed, $base);
    $source = 'sensor';
} elseif ($obs !== null) {
    $factor = Forecast::anchorFactor((float)$obs['p50'], $base);
    $source = 'chmi_map';
}
if ($factor === 1.0) {
    $source = null;
}
$series = Forecast::applyAnchor($series, $factor);

header('Cache-Control: public, max-age=600');
Http::jsonOut([
    'area'       => $data['area'] ?? $area,
    'name'       => $data['name'] ?? '',
    'area_type'  => $data['type'] ?? Forecast::AREA_TYPE,
    'type'       => $valueType,
    'run'        => $data['run'] ?? null,
    'generated'  => $generated,
    'anchored'   => $factor !== 1.0,
    'anchor'     => $factor,
    'anchor_src' => $source,
    'observed'   => $observed,
    'obs'        => $obs,
    't0'         => $series['t0'],
    'step'       => $series['step'],
    'p25'        => $series['p25'],
    'p50'        => $series['p50'],
    'p75'        => $series['p75'],
]);

/**
 * Najde nejnovější parsovatelný běh a zapíše po jednom souboru na kraj.
 * Selhání nechá starou cache být (má vlastní staleness gate při výdeji).
 */
function refreshForecast(string $dir, string $metaFile, ?array $meta, int $now): void
{
    $parsed = [];
    $usedRun = null;
    $created = null;
    $errors = []; // proč běh neprošel — zapisuje se do meta.json (last_error), ať je to vidět i bez logů serveru

    foreach (Forecast::runCandidates($now) as $runHour) {
        if ($meta !== null && ($meta['run'] ?? null) === Forecast::runLabel($runHour, $now)) {
            $meta['generated'] = $now;   // stejný běh: nic nestahovat, jen přerazítkovat
            unset($meta['refresh_failed'], $meta['last_error']);
            Http::atomicWrite($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
            return;
        }
        $batch = [];
        foreach (array_keys(Forecast::COMPONENTS) as $component) {
            [$body, $code, $err] = Http::fetch(Forecast::url($component, $runHour), 60);
            if ($body === null || $code !== 200) {
                $errors[] = sprintf('%s h%02d: HTTP %d %s', $component, $runHour, $code, $err);
                continue;
            }
            $entries = Forecast::unzip($body);
            if ($entries === null) {
                $errors[] = sprintf('%s h%02d: není ZIP (%d B)', $component, $runHour, strlen($body));
                continue;
            }
            $ok = 0;
            foreach ($entries as $json) {
                $area = Forecast::parseArea($json, $now);
                if ($area !== null) {
                    $batch[] = $area;
                    $created = max($created ?? 0, $area['created']);
                    $ok++;
                }
            }
            if ($ok === 0) {
                // typicky starý běh (datumVytvoreni > 6 h) — ČHMÚ nic nového nevydal
                $errors[] = sprintf('%s h%02d: %d souborů, žádný čerstvý', $component, $runHour, count($entries));
            }
        }
        if (count($batch) >= 10) {
            $parsed = $batch;
            $usedRun = $runHour;
            break;
        }
    }
    if ($parsed === []) {
        $summary = gmdate('Y-m-d H:i', $now) . 'Z: ' . implode('; ', array_slice($errors, 0, 6));
        error_log('forecast-proxy.php: refresh failed — ' . $summary);
        if ($meta !== null) {
            // Nic nového nešlo stáhnout: odložit další pokus, ať to nezkouší každý požadavek
            $meta['generated'] = $now;
            $meta['refresh_failed'] = true;
            $meta['last_error'] = $summary;
            Http::atomicWrite($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
        }
        return;
    }

    $areas = Forecast::merge($parsed);
    $run = Forecast::runLabel((int)$usedRun, $now);
    $index = [];
    foreach ($areas as $code => $area) {
        $payload = [
            'area' => (string)$code, 'name' => $area['name'], 'type' => $area['type'],
            'run' => $run, 'created' => $created, 'generated' => $now, 'series' => $area['series'],
        ];
        if (Http::atomicWrite($dir . '/kraj_' . $code . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE))) {
            $index[(string)$code] = $area['name'];
        }
    }
    if ($index === []) {
        return;
    }
    Http::atomicWrite($metaFile, json_encode([
        'run' => $run, 'created' => $created, 'generated' => $now,
        'area_type' => Forecast::AREA_TYPE, 'horizon_h' => Forecast::HORIZON_HOURS,
        'pollutants' => array_values(Forecast::COMPONENTS), 'areas' => $index,
    ], JSON_UNESCAPED_UNICODE));
}
