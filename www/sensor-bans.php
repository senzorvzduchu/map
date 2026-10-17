<?php
// sensor-bans.php — veřejný seznam senzorů, které mapa NESMÍ zobrazit
// GET → { generated, banned: [id…], auto: [{sensor_id, reason}…] }
//   banned = ruční blokace správce (tabulka sensor_bans v historygraph DB, admin/bans.php)
//   auto   = automatické příznaky (SensorQuality), samoléčivé: stuck/plateau = plochý
//            průběh PM za 2 h; od 3.3 indoor = v noci výrazně tepleji a sušeji než nejbližší
//            meteostanice ČHMÚ do 30 km + stabilní teplota 24 h (senzor v interiéru)
// Cache 10 min v cache/sensor_flags.json; ban/unban v adminu ji smaže.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/SensorQuality.php';

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);
// no-cache v prohlížeči: blokace se má projevit při dalším načtení mapy
header('Cache-Control: no-cache');

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/sensor_flags.json';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 600) {
    readfile($cacheFile);
    exit;
}

try {
    $db = SensorQuality::db();
    $payload = [
        'generated' => gmdate('c'),
        'banned'    => SensorQuality::manualBans($db),
        'auto'      => array_map(
            fn($f) => ['sensor_id' => $f['sensor_id'], 'reason' => $f['reason']],
            SensorQuality::autoFlags($db)
        ),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    Http::atomicWrite($cacheFile, $json);
    echo $json;
} catch (Throwable $e) {
    error_log('sensor-bans.php: ' . $e->getMessage());
    // Fail open: prázdný seznam = tentokrát bez filtrování, mapa běží dál
    echo json_encode(['generated' => gmdate('c'), 'banned' => [], 'auto' => []]);
}
