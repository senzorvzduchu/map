<?php
/**
 * Offline test: auto-příznak „indoor" (3.3) — SensorQuality::indoorFlags / autoFlags
 * nad syntetickou historií v SQLite v paměti. Bez sítě, bez produkční DB.
 *
 * Scénář = pár AirGradient v Poděbradech z 16. 10. 2026 (stejné souřadnice):
 *   AG_61860 „Location 2"  27,5 °C / 29 % celý den (interiér)      → MUSÍ být indoor
 *   AG_78554 „Předhradí"   venku, kopíruje meteostanici ±1 °C      → nesmí
 *   SC_1 „na slunci"       v noci jako ČHMÚ, v poledne 38 °C       → nesmí (rozkmit)
 * Reference = CHMI_M_<WSI> Praha, Kbely (43 km → mimo 30 km) a syntetická Poděbrady (6 km).
 *
 * Run: php tests/test_indoor_flag.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/SensorQuality.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}

$tz = new DateTimeZone('Europe/Prague');
// Pevné „teď": 16. 10. 2026 22:00 místního času → okno 24 h pokrývá noc 16. 10. 00–05 h celou
$now = (new DateTime('2026-10-16 22:00:00', $tz))->getTimestamp();
$dayStart = (new DateTime('2026-10-16 00:00:00', $tz))->getTimestamp();

// --- nightWindows -----------------------------------------------------------
$w = SensorQuality::nightWindows($now);
check(count($w) === 1 && $w[0][0] === $dayStart && $w[0][1] === $dayStart + 5 * 3600,
    'nightWindows: ve 22:00 jedno okno = dnes 00:00–05:00 Europe/Prague');
$w2 = SensorQuality::nightWindows($dayStart + 3 * 3600); // 03:00 → dnešní noc oříznutá + včerejší oříznutá
check(count($w2) === 2 && $w2[0][0] === $dayStart - 86400 + 3 * 3600 && $w2[0][1] === $dayStart - 86400 + 5 * 3600
    && $w2[1][0] === $dayStart && $w2[1][1] === $dayStart + 3 * 3600,
    'nightWindows: ve 03:00 dvě okna (včera 03–05 oříznuté oknem 24 h, dnes 00–03 oříznuté teď)');
// Přechod na zimní čas 25. 10. 2026 (noc má 6 hodin, 02:00 dvakrát): okno 00–05 místního = 6 h unixu
$dst = (new DateTime('2026-10-25 22:00:00', $tz))->getTimestamp();
$w3 = SensorQuality::nightWindows($dst);
check(count($w3) === 1 && ($w3[0][1] - $w3[0][0]) === 6 * 3600, 'nightWindows: noc změny času 25. 10. má 6 h (DST přes DateTime)');

// --- distance / median ------------------------------------------------------
check(abs(SensorQuality::distanceKm(50.091935, 15.14536, 50.1225, 15.1300) - 3.5) < 0.3, 'distanceKm: Poděbrady → syntetická stanice ≈ 3,5 km');
check(abs(SensorQuality::distanceKm(50.091935, 15.14536, 50.1236, 14.5386) - 43.5) < 1.0, 'distanceKm: Poděbrady → Praha, Kbely ≈ 43 km (mimo 30 km)');
check(SensorQuality::median([3, 1, 2]) === 2.0 && SensorQuality::median([4, 1, 3, 2]) === 2.5, 'median: lichý i sudý počet');

// --- syntetická DB ----------------------------------------------------------
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE measurements (id INTEGER PRIMARY KEY AUTOINCREMENT, sensor_id TEXT, measured_at INTEGER, value_type TEXT, value REAL)');
$db->exec('CREATE INDEX idx_sensor_type ON measurements (sensor_id, value_type, measured_at)');
$ins = $db->prepare('INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)');

/** Venkovní denní chod: noc 4 °C / 92 %, poledne 14 °C / 60 % (hladká křivka podle hodiny). */
$outdoorT = fn(float $h) => 9.0 + 5.0 * cos(2 * M_PI * ($h - 14) / 24);
$outdoorRH = fn(float $h) => 76.0 - 16.0 * cos(2 * M_PI * ($h - 14) / 24);

$db->beginTransaction();
// ČHMÚ: jedna hodnota za hodinu v HH:50 (jako zdroj), 24 h zpět
$refPod = 'CHMI_M_0-999-0-PODEBRADY';
$refKbely = 'CHMI_M_0-20000-0-11567';
for ($i = 0; $i < 25; $i++) {
    $ts = $dayStart - 2 * 3600 + $i * 3600 + 50 * 60;          // 22:50 včera … 22:50 dnes
    $h = (float)(new DateTime('@' . $ts))->setTimezone($tz)->format('G') + 50 / 60;
    foreach ([$refPod, $refKbely] as $ref) {
        $ins->execute([$ref, $ts, 'temperature', round($outdoorT($h), 1)]);
        $ins->execute([$ref, $ts, 'humidity', round($outdoorRH($h))]);
    }
}
// Komunitní senzory po 5 min, 24 h zpět
for ($ts = $now - 86400; $ts <= $now; $ts += 300) {
    $h = (float)(new DateTime('@' . $ts))->setTimezone($tz)->format('G') + ((int)(($ts % 3600) / 60)) / 60;
    $k = ($ts / 300) % 7;   // drobný šum
    // AG_61860 interiér: 27,5 ±0,3 °C, 29 ±1 %
    $ins->execute(['AG_61860', $ts, 'temperature', 27.5 + ($k - 3) * 0.1]);
    $ins->execute(['AG_61860', $ts, 'humidity', 29 + ($k % 3) - 1]);
    // AG_78554 venku: kryt +1 °C, RH −3 b.
    $ins->execute(['AG_78554', $ts, 'temperature', round($outdoorT($h) + 1.0, 1)]);
    $ins->execute(['AG_78554', $ts, 'humidity', round($outdoorRH($h) - 3)]);
    // SC_1 na slunci: v noci jako venku (+0,5 °C), 10–16 h ohřev krytu až +24 °C → poledne 38 °C
    $sun = ($h >= 10 && $h <= 16) ? 24.0 * sin(M_PI * ($h - 10) / 6) : 0.0;
    $ins->execute(['SC_1', $ts, 'temperature', round($outdoorT($h) + 0.5 + $sun, 1)]);
    $ins->execute(['SC_1', $ts, 'humidity', round($outdoorRH($h) - 2 - $sun)]);
    // SC_2 daleko (jen Kbely 43 km, Poděbrady 60 km): interiérové hodnoty, ale bez reference do 30 km
    $ins->execute(['SC_2', $ts, 'temperature', 24.0]);
    $ins->execute(['SC_2', $ts, 'humidity', 30.0]);
    // SCK_3 „zvlhčovač": teplo jako interiér, ale vlhkost jako venku → RH podmínka nesplněna
    $ins->execute(['SCK_3', $ts, 'temperature', 22.0 + ($k - 3) * 0.1]);
    $ins->execute(['SCK_3', $ts, 'humidity', round($outdoorRH($h))]);
    // PA_4 interiér, ale jen 4 noční vzorky (zapnutý až 04:40)
    if ($ts >= $dayStart + 4 * 3600 + 40 * 60) {
        $ins->execute(['PA_4', $ts, 'temperature', 23.0]);
        $ins->execute(['PA_4', $ts, 'humidity', 35.0]);
    }
    // LIBCICH_5 interiér bez vlhkosti (jen teplota) → nelze spárovat → neflaguje se
    $ins->execute(['LIBCICH_5', $ts, 'temperature', 23.0]);
}
// Stuck PM senzor pro kontrolu, že PM příznaky zůstaly (2 h, 24 vzorků, 15 µg/m³ bez pohybu)
for ($ts = $now - 7200; $ts <= $now; $ts += 300) {
    $ins->execute(['SC_9', $ts, 'PM2.5', 15.0]);
}
$db->commit();

$refs = [
    ['id' => $refPod,   'name' => 'Poděbrady (syntetická)', 'lat' => 50.1225, 'lng' => 15.1300],
    ['id' => $refKbely, 'name' => 'Praha, Kbely',           'lat' => 50.1236, 'lng' => 14.5386],
];
$coords = [
    'AG_61860'  => ['lat' => 50.091935, 'lng' => 15.14536],
    'AG_78554'  => ['lat' => 50.091935, 'lng' => 15.14536],
    'SC_1'      => ['lat' => 50.10,     'lng' => 15.10],
    'SC_2'      => ['lat' => 50.50,     'lng' => 14.30],   // 43+ km od obou
    'SCK_3'     => ['lat' => 50.09,     'lng' => 15.15],
    'PA_4'      => ['lat' => 50.09,     'lng' => 15.15],
    'LIBCICH_5' => ['lat' => 50.09,     'lng' => 15.15],
    $refPod     => ['lat' => 50.1225,   'lng' => 15.1300], // ČHMÚ v coords nikdy nevadí
];

// --- indoorFlags ------------------------------------------------------------
$flags = SensorQuality::indoorFlags($db, $refs, $coords, $now);
$byId = array_column($flags, null, 'sensor_id');
check(isset($byId['AG_61860']), 'indoorFlags: AG_61860 „Location 2" (27,5 °C / 29 % celou noc) je indoor');
$f = $byId['AG_61860'] ?? [];
check(($f['reason'] ?? '') === 'indoor' && ($f['value_type'] ?? '') === 'temperature', 'indoorFlags: reason indoor, value_type temperature');
check(($f['mean'] ?? 0) > 20 && ($f['std'] ?? 0) > 55, 'indoorFlags: ΔT ≈ +23 °C (mean), ΔRH ≈ +62 b. (std)');
check(($f['n'] ?? 0) >= 55, 'indoorFlags: ≥ 55 párových nočních vzorků (5 h × 12)');
check(($f['range'] ?? 9) < 1.0 && ($f['ref'] ?? '') === 'Poděbrady (syntetická)' && ($f['ref_km'] ?? 99) < 4, 'indoorFlags: rozkmit < 1 °C, reference = nejbližší stanice (3,5 km), ne Kbely');
check(str_contains((string)($f['detail'] ?? ''), 'Poděbrady'), 'indoorFlags: detail pro admin obsahuje název stanice');
check(!isset($byId['AG_78554']), 'indoorFlags: AG_78554 „Předhradí" (venku, ±1 °C od ČHMÚ) NENÍ indoor');
check(!isset($byId['SC_1']), 'indoorFlags: senzor na slunci (38 °C v poledne, v noci jako ČHMÚ) NENÍ indoor');
check(!isset($byId['SC_2']), 'indoorFlags: senzor bez stanice do 30 km se neflaguje (fail open)');
check(!isset($byId['SCK_3']), 'indoorFlags: teplo, ale vlhkost jako venku → ne (podmínka RH)');
check(!isset($byId['PA_4']), 'indoorFlags: jen 4 noční vzorky (< 6) → ne');
check(!isset($byId['LIBCICH_5']), 'indoorFlags: bez vlhkosti se nedá spárovat → ne');
check(!isset($byId[$refPod]) && !isset($byId[$refKbely]), 'indoorFlags: stanice ČHMÚ nikdy');
check(count($flags) === 1, 'indoorFlags: celkem přesně 1 příznak');

// Rozkmit: stejný interiér, ale s otevřeným oknem odpoledne (−5 °C po 2 h) → rozkmit ≥ 4 → ne
$db->beginTransaction();
for ($ts = $dayStart + 15 * 3600; $ts < $dayStart + 17 * 3600; $ts += 300) {
    $db->prepare('UPDATE measurements SET value = 22.0 WHERE sensor_id = ? AND value_type = ? AND measured_at = ?')
       ->execute(['AG_61860', 'temperature', $ts]);
}
$db->commit();
$flags2 = SensorQuality::indoorFlags($db, $refs, $coords, $now);
check(!array_filter($flags2, fn($x) => $x['sensor_id'] === 'AG_61860'), 'indoorFlags: rozkmit 24 h ≥ 4 °C (větrání) → příznak zmizí (samoléčení)');
// vrátit
$db->beginTransaction();
for ($ts = $dayStart + 15 * 3600; $ts < $dayStart + 17 * 3600; $ts += 300) {
    $db->prepare('UPDATE measurements SET value = 27.5 WHERE sensor_id = ? AND value_type = ? AND measured_at = ?')
       ->execute(['AG_61860', 'temperature', $ts]);
}
$db->commit();

// Bez referencí / bez souřadnic → nic (fail open)
check(SensorQuality::indoorFlags($db, [], $coords, $now) === [] && SensorQuality::indoorFlags($db, $refs, [], $now) === [],
    'indoorFlags: bez stanic nebo bez souřadnic → []');
// Teď = 23:00 následujícího dne: noc 16. 10. vypadla z okna, nové noční řádky nejsou → nic
check(SensorQuality::indoorFlags($db, $refs, $coords, $now + 25 * 3600) === [], 'indoorFlags: po 24 h bez nových nočních vzorků příznak zmizí');

// --- autoFlags (PM + indoor dohromady) ---------------------------------------
$all = SensorQuality::autoFlags($db, $now, $refs, $coords);
$allById = array_column($all, null, 'sensor_id');
check(isset($allById['SC_9']) && $allById['SC_9']['reason'] === 'stuck', 'autoFlags: PM příznak stuck zůstal (SC_9)');
check(isset($allById['AG_61860']) && $allById['AG_61860']['reason'] === 'indoor', 'autoFlags: obsahuje indoor AG_61860');
check(count($all) === 2, 'autoFlags: přesně 2 příznaky');
foreach ($all as $x) {
    check(isset($x['sensor_id'], $x['reason'], $x['value_type'], $x['mean'], $x['std'], $x['n'], $x['detail']),
        'autoFlags: ' . $x['sensor_id'] . ' má pole sensor_id/reason/value_type/mean/std/n/detail (admin + sensor-bans)');
}
// sensor-bans.php tvar výstupu
$pub = array_map(fn($x) => ['sensor_id' => $x['sensor_id'], 'reason' => $x['reason']], $all);
check(in_array(['sensor_id' => 'AG_61860', 'reason' => 'indoor'], $pub, true), 'sensor-bans.php: auto obsahuje {sensor_id: AG_61860, reason: indoor}');

// --- loadery z cache (tvar souborů) ------------------------------------------
$tmp = sys_get_temp_dir() . '/sv_indoor_test_' . getmypid();
@mkdir($tmp); @mkdir($tmp . '/cache');
file_put_contents($tmp . '/cache/chmi_meteo.json', json_encode(['stations' => [
    ['wsi' => '0-20000-0-11567', 'name' => 'Praha, Kbely', 'lat' => 50.1236, 'lng' => 14.5386, 'temperature' => 8.9],
    ['wsi' => '', 'name' => 'bez wsi', 'lat' => 1, 'lng' => 1],
]]));
file_put_contents($tmp . '/sc_data_cache.json', json_encode([
    ['sensor' => ['id' => 77008], 'location' => ['latitude' => '49.2397', 'longitude' => '13.5131']],
    ['sensor' => ['id' => 77008], 'location' => ['latitude' => '49.2397', 'longitude' => '13.5131']], // duplicitní záznam
    ['sensor' => ['id' => 1], 'location' => ['latitude' => null, 'longitude' => null]],
]));
file_put_contents($tmp . '/pa_data_cache.json', json_encode([['id' => 272377, 'latitude' => 50.05295, 'longitude' => 14.422396]]));
file_put_contents($tmp . '/sck_data_cache.json', json_encode([['id' => 19470, 'latitude' => 50.02429, 'longitude' => 14.49129]]));
file_put_contents($tmp . '/libcich_data_cache.json', json_encode([['id' => '001C6320C7438A81', 'latitude' => 50.1976, 'longitude' => 14.3627]]));
file_put_contents($tmp . '/cache/airgradient.json', json_encode(['sensors' => [['id' => 61860, 'latitude' => 50.091935, 'longitude' => 15.14536]]]));

$r = SensorQuality::referenceStations($tmp . '/cache/chmi_meteo.json');
check(count($r) === 1 && $r[0]['id'] === 'CHMI_M_0-20000-0-11567' && $r[0]['lat'] === 50.1236, 'referenceStations: CHMI_M_<wsi> + souřadnice, záznam bez wsi vynechán');
check(SensorQuality::referenceStations($tmp . '/neexistuje.json') === [], 'referenceStations: chybějící cache → []');
$c = SensorQuality::communityCoords($tmp);
check(count($c) === 5 && isset($c['SC_77008'], $c['PA_272377'], $c['SCK_19470'], $c['LIBCICH_001C6320C7438A81'], $c['AG_61860']),
    'communityCoords: 5 sítí → SC_/PA_/SCK_/LIBCICH_/AG_ (SC bez souřadnic vynechán)');
check($c['SC_77008']['lat'] === 49.2397 && $c['AG_61860']['lng'] === 15.14536, 'communityCoords: hodnoty jako float');
check(SensorQuality::communityCoords($tmp . '/nic') === [], 'communityCoords: bez cache → []');
foreach (glob($tmp . '/cache/*') as $g) @unlink($g);
foreach (glob($tmp . '/*.json') as $g) @unlink($g);
@rmdir($tmp . '/cache'); @rmdir($tmp);

echo "\n" . ($failures ? "FAILURES: $failures\n" : "ALL PASS\n");
exit($failures ? 1 : 0);
