<?php
/**
 * Offline test: AirGradient world feed → CZ subset normalisation.
 * No network, no database.
 *
 * Run: php tests/test_airgradient.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/AirGradient.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}

// Fixed "now": 2026-10-16 13:10:00 UTC
$now = strtotime('2026-10-16 13:10:00 UTC');
$fresh = '2026-10-16T13:09:16.000Z';   // < 1 min
$stale = '2026-10-16T12:20:00.000Z';   // 50 min → in output, online=false
$dead  = '2026-10-16T10:00:00.000Z';   // 3 h 10 min → dropped

$rec = function (int $id, float $lat, float $lon, array $over = []) use ($fresh): array {
    return array_merge([
        'locationId' => $id, 'locationName' => "Loc $id", 'publicLocationName' => "Loc $id",
        'latitude' => $lat, 'longitude' => $lon, 'offline' => false,
        'pm01' => 5.0, 'pm02' => 11.94, 'pm10' => 12.1, 'atmp' => 18.66, 'rhum' => 46,
        'rco2' => 429, 'timestamp' => $fresh, 'model' => 'O-1PP',
    ], $over);
};

$world = [
    $rec(67175, 50.0529, 14.3473),                                        // Praha, fresh
    $rec(81353, 50.0295, 14.3059, ['pm01' => null, 'pm02' => null, 'pm10' => null]), // only CO2/T/RH → drop
    $rec(90098, 48.6261, 12.4897),                                        // Dingolfing DE → excluded by id
    $rec(1, 52.52, 13.40),                                                // Berlin → outside bbox
    $rec(2, 49.19, 16.62, ['timestamp' => $stale]),                       // Brno, 50 min old → grey
    $rec(3, 49.43, 17.97, ['timestamp' => $dead]),                        // 3 h old → drop
    $rec(4, 49.90, 18.09, ['offline' => true]),                           // offline flag → drop
    $rec(5, 50.10, 14.46, ['publicLocationName' => '  ', 'locationName' => null, 'rco2' => null, 'pm02' => 0.0]),
    $rec(6, 50.46, 13.41, ['latitude' => null]),                          // no coords → drop
    $rec(7, 50.09, 15.14, ['pm02' => '46.0', 'rhum' => '55', 'atmp' => 9999]), // numeric strings ok, T out of range → null
    'garbage',
];

$out = AirGradient::normalise($world, $now);
$byId = array_column($out, null, 'id');

check(count($out) === 4, 'four sensors survive the filter (got ' . count($out) . ')');
check(array_keys($byId) === [2, 5, 7, 67175], 'output sorted by id');
check(!isset($byId[81353]), 'sensor with no PM values dropped');
check(!isset($byId[90098]), 'border sensor excluded by id list');
check(!isset($byId[1]), 'sensor outside bbox dropped');
check(!isset($byId[3]), 'reading older than MAX_AGE dropped');
check(!isset($byId[4]), 'offline flag dropped');
check(!isset($byId[6]), 'missing coordinates dropped');

$p = $byId[67175];
check($p['name'] === 'Loc 67175', 'public name used');
check($p['pm25'] === 11.9 && $p['pm10'] === 12.1 && $p['pm1'] === 5.0, 'pm fields rounded to 1 dp');
check($p['temperature'] === 18.7 && $p['humidity'] === 46.0 && $p['co2'] === 429.0, 'T 1 dp, RH and CO2 whole numbers');
check($p['online'] === true && $p['age_min'] === 0, 'fresh reading is online, age 0 min');
check($p['measured_at'] === '2026-10-16T13:09:16+00:00', 'measured_at is ISO UTC');
check($p['model'] === 'O-1PP', 'model passed through');

$b = $byId[2];
check($b['online'] === false && $b['age_min'] === 50, '50 min old reading kept but offline (age 50)');

$f = $byId[5];
check($f['name'] === 'AirGradient #5', 'blank names fall back to AirGradient #id');
check($f['pm25'] === 0.0, 'zero PM2.5 is a value, not null');
check($f['co2'] === null, 'null CO2 stays null');

$s = $byId[7];
check($s['pm25'] === 46.0 && $s['humidity'] === 55.0, 'numeric strings accepted');
check($s['temperature'] === null, 'out-of-range temperature → null');

// Edge: empty / non-array input
check(AirGradient::normalise([], $now) === [], 'empty feed → empty output');

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
