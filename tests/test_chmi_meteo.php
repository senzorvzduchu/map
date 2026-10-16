<?php
/**
 * Offline test: ČHMÚ 10min meteo data (meta1 + soubor stanice) → výstup proxy.
 * No network, no database.
 *
 * Run: php tests/test_chmi_meteo.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/ChmiMeteo.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}

// Fixed "now": 2026-10-16 15:05:00 UTC (soubor 15:01Z končí řádky 14:50Z)
$now = strtotime('2026-10-16 15:05:00 UTC');

// --- meta1 (tvar podle skutečného souboru 16. 10. 2026) ---
$meta = ['data' => ['type' => 'DataCollection', 'data' => [
    'header' => 'WSI,GH_ID,FULL_NAME,GEOGR1,GEOGR2,ELEVATION,BEGIN_DATE',
    'values' => [
        ['0-20000-0-04030', 'ZIS04030', 'Reykjavik', -21.903905, 64.127653, 51.0, '2015-01-01T00:00:00Z'], // nesledovaná
        ['0-20000-0-11519', 'P1PKAR01', 'Praha, Karlov', 14.427778, 50.069167, 260.5, '1920-01-01T00:00:00Z'],
        ['0-20000-0-11520', 'P1PLIB01', 'Praha, Libuš', 14.446944, 50.007778, 302.04, '1950-01-01T00:00:00Z'],
        ['0-20000-0-11723', 'B2BTUR01', 'Brno, Tuřany', 16.688889, 49.153056, 241.0, '1950-01-01T00:00:00Z'],
        ['0-20000-0-11723', 'B2BTUR01', 'Brno, Tuřany (dup)', 16.0, 49.0, 241.0, '1950-01-01T00:00:00Z'], // duplicitní řádek → první vyhrává
        ['0-20000-0-11787', 'O1LYSA01', '', 18.4475, 49.546111, 1322.03, '1897-01-01T00:00:00Z'],        // prázdný název → záložní
        // 0-20000-0-11518 Ruzyně v meta1 chybí → stanice vypadne i s měřením
    ],
]]];
$parsed = ChmiMeteo::parseMeta($meta);
check(count($parsed) === 4, 'parseMeta: 4 sledované stanice (Reykjavik vynechán, duplicita sloučena)');
check(($parsed['0-20000-0-11519']['lat'] ?? 0) === 50.069167 && ($parsed['0-20000-0-11519']['lng'] ?? 0) === 14.427778, 'parseMeta: GEOGR1 = lng, GEOGR2 = lat');
check(($parsed['0-20000-0-11723']['name'] ?? '') === 'Brno, Tuřany', 'parseMeta: první z duplicitních řádků vyhrává');
check(($parsed['0-20000-0-11787']['name'] ?? '') === 'Lysá hora', 'parseMeta: prázdný FULL_NAME → záložní název ze STATIONS');
check(($parsed['0-20000-0-11519']['elevation'] ?? null) === 260.5, 'parseMeta: elevation');

// --- soubor stanice ---
$row = fn(string $wsi, string $el, string $dt, $val, $q = 5.0) => [$wsi, $el, $dt, $val, '', $q];
$karlov = ['data' => ['type' => 'DataCollection', 'data' => [
    'header' => 'STATION,ELEMENT,DT,VAL,FLAG,QUALITY',
    'values' => [
        $row('0-20000-0-11519', 'Casmax', '2026-10-16T14:50:00Z', 442.0),       // jiný prvek → ignorovat
        $row('0-20000-0-11519', 'TMA',    '2026-10-16T14:50:00Z', 16.4, 3.0),   // jiný prvek
        $row('0-20000-0-11519', 'H',      '2026-10-16T14:30:00Z', 66.0),
        $row('0-20000-0-11519', 'H',      '2026-10-16T14:40:00Z', 65.0),
        $row('0-20000-0-11519', 'H',      '2026-10-16T14:50:00Z', 67.0),
        $row('0-20000-0-11519', 'T',      '2026-10-16T14:50:00Z', 16.0),
        $row('0-20000-0-11519', 'T',      '2026-10-16T14:40:00Z', 15.9),        // starší, i když později v souboru
        $row('0-20000-0-11519', 'T',      '2026-10-16T14:30:00Z', 15.6),
    ],
]]];
$r = ChmiMeteo::parseStation($karlov);
check(($r['T']['value'] ?? null) === 16.0 && $r['T']['ts'] === strtotime('2026-10-16T14:50:00Z'), 'parseStation: poslední T podle DT, ne podle pořadí');
check(($r['H']['value'] ?? null) === 67.0, 'parseStation: poslední H');

// Kvalita: 0 Good a 5 Unknown projdou, 1/2/3/4 ne; chybějící VAL ne; mimo meze ne
$libus = ['data' => ['data' => ['values' => [
    $row('0-20000-0-11520', 'T', '2026-10-16T14:50:00Z', 99.9, 1.0),   // Suspect → ignorovat
    $row('0-20000-0-11520', 'T', '2026-10-16T14:40:00Z', 88.8, 2.0),   // Poor
    $row('0-20000-0-11520', 'T', '2026-10-16T14:30:00Z', 77.7, 3.0),   // Estimated
    $row('0-20000-0-11520', 'T', '2026-10-16T14:20:00Z', null, 4.0),   // Missing
    $row('0-20000-0-11520', 'T', '2026-10-16T14:10:00Z', '',   5.0),   // prázdný VAL
    $row('0-20000-0-11520', 'T', '2026-10-16T14:00:00Z', 70.0, 5.0),   // mimo T_MAX 60
    $row('0-20000-0-11520', 'T', '2026-10-16T13:50:00Z', 15.1, 0.0),   // Good → poslední platný
    $row('0-20000-0-11520', 'H', '2026-10-16T14:50:00Z', 101.0, 5.0),  // mimo H_MAX
    $row('0-20000-0-11520', 'H', '2026-10-16T14:40:00Z', 72.0, 5.0),
]]]];
$r2 = ChmiMeteo::parseStation($libus);
check(($r2['T']['value'] ?? null) === 15.1, 'parseStation: QUALITY 1–4, prázdný VAL a hodnota mimo meze se přeskočí; 0 projde');
check(($r2['H']['value'] ?? null) === 72.0, 'parseStation: H > 100 % se přeskočí');
check(ChmiMeteo::parseStation(['data' => ['data' => ['values' => []]]]) === [], 'parseStation: prázdný soubor → []');
check(ChmiMeteo::parseStation(['foo' => 'bar']) === [], 'parseStation: cizí JSON → []');

// --- build ---
$readings = [
    '0-20000-0-11519' => $r,                                                            // Karlov: T+H 14:50
    '0-20000-0-11520' => $r2,                                                           // Libuš: T 13:50 (75 min), H 14:40
    '0-20000-0-11723' => ['T' => ['value' => 14.25, 'ts' => strtotime('2026-10-16T11:50:00Z')]], // Brno: 3 h 15 min → pryč
    '0-20000-0-11787' => ['H' => ['value' => 98.6, 'ts' => strtotime('2026-10-16T12:50:00Z')]],  // Lysá hora: jen H, 135 min → stale (> 2 h)
    '0-20000-0-11518' => ['T' => ['value' => 14.4, 'ts' => $now]],                      // Ruzyně: není v meta → pryč
];
$out = ChmiMeteo::build($parsed, $readings, $now);
$byWsi = array_column($out, null, 'wsi');
check(count($out) === 3, 'build: 3 stanice (Brno příliš staré, Ruzyně bez metadat)');
check(!isset($byWsi['0-20000-0-11723']) && !isset($byWsi['0-20000-0-11518']), 'build: stará stanice a stanice bez souřadnic chybí');

$k = $byWsi['0-20000-0-11519'] ?? [];
check(($k['temperature'] ?? null) === 16.0 && ($k['humidity'] ?? null) === 67.0, 'build: Karlov T 16.0, H 67');
check(($k['measured_at'] ?? '') === '2026-10-16T14:50:00+00:00' && ($k['age_min'] ?? -1) === 15 && ($k['stale'] ?? true) === false, 'build: measured_at ISO UTC, age_min 15, stale false');
check(($k['name'] ?? '') === 'Praha, Karlov' && ($k['lat'] ?? 0) === 50.069167 && ($k['lng'] ?? 0) === 14.427778, 'build: název a souřadnice z meta1');

$l = $byWsi['0-20000-0-11520'] ?? [];
check(($l['humidity'] ?? null) === 72.0 && ($l['temperature'] ?? null) === 15.1, 'build: Libuš – T o 50 min starší než H se ponechá (≤ 1 h)');
check(($l['measured_at'] ?? '') === '2026-10-16T14:40:00+00:00' && ($l['age_min'] ?? -1) === 25, 'build: measured_at = novější z T/H');

$ly = $byWsi['0-20000-0-11787'] ?? [];
check(array_key_exists('temperature', $ly) && $ly['temperature'] === null && ($ly['humidity'] ?? null) === 99.0, 'build: jen vlhkost → temperature null, humidity zaokrouhlená na celé %');
check(($ly['stale'] ?? false) === true && ($ly['age_min'] ?? 0) === 135, 'build: 135 min (> 2 h) → stale true');
check(($l['stale'] ?? true) === false, 'build: 25 min → stale false (hodinová publikace souboru není výpadek)');
check(($ly['name'] ?? '') === 'Lysá hora', 'build: záložní název');

// Řazení podle názvu
check(array_column($out, 'name') === ['Lysá hora', 'Praha, Karlov', 'Praha, Libuš'], 'build: seřazeno podle názvu');

// T a H z různých dnů: starý prvek > 1 h za novějším se vypustí
$mixed = ['0-20000-0-11519' => [
    'T' => ['value' => 16.0, 'ts' => strtotime('2026-10-16T14:50:00Z')],
    'H' => ['value' => 60.0, 'ts' => strtotime('2026-10-16T12:00:00Z')],
]];
$o = ChmiMeteo::build($parsed, $mixed, $now);
check(count($o) === 1 && $o[0]['humidity'] === null && $o[0]['temperature'] === 16.0, 'build: prvek o > 1 h starší než druhý se vypustí');

// Bez metadat → nic (proxy pak servíruje starou cache)
check(ChmiMeteo::build([], $readings, $now) === [], 'build: bez metadat prázdný výstup');

// --- URL a dny ---
check(ChmiMeteo::stationUrl('0-20000-0-11519', '20261016') === 'https://opendata.chmi.cz/meteorology/climate/now/data/10m-0-20000-0-11519-20261016.json', 'stationUrl');
check(ChmiMeteo::metaUrl('20261016') === 'https://opendata.chmi.cz/meteorology/climate/now/metadata/meta1-20261016.json', 'metaUrl');
check(ChmiMeteo::dates(strtotime('2026-10-17 00:03:00 UTC')) === ['20261017', '20261016'], 'dates: dnešek (UTC), pak včerejšek');
check(ChmiMeteo::dates(strtotime('2026-10-16 23:59:00 UTC')) === ['20261016', '20261015'], 'dates: podle UTC, ne lokálního času');

// --- seznam stanic ---
$n = count(ChmiMeteo::STATIONS);
check($n >= 40 && $n <= 60, "STATIONS: $n stanic (cíl 40–60; každá = 1 soubor ~100 kB po 15 min)");
check(count(array_unique(array_keys(ChmiMeteo::STATIONS))) === $n, 'STATIONS: WSI unikátní');
foreach (ChmiMeteo::STATIONS as $wsi => $name) {
    if (!preg_match('/^0-(20000|203)-0-\d+$/', $wsi)) { check(false, "STATIONS: neplatné WSI $wsi"); }
}
check(isset(ChmiMeteo::STATIONS['0-20000-0-11519'], ChmiMeteo::STATIONS['0-20000-0-11723'], ChmiMeteo::STATIONS['0-203-0-11790']), 'STATIONS: Praha Karlov, Brno Tuřany, Ostrava Poruba');

echo "\n" . ($failures === 0 ? 'ALL PASS' : "$failures FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
