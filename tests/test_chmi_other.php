<?php
/**
 * Offline test 3.4: ČHMÚ data-provider hourly layers → other-owner reference stations.
 * No network, no database. Fixtures = real layers for slot 202610171700 saved 17. 10. 2026.
 *
 * Run: php tests/test_chmi_other.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/ChmiOther.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}
function fixture(string $name): array
{
    $path = __DIR__ . '/fixtures/' . $name;
    $d = json_decode((string)file_get_contents($path), true);
    if (!is_array($d)) { throw new RuntimeException('fixture unreadable: ' . $name); }
    return $d;
}

// --- slots -------------------------------------------------------------------
$now = strtotime('2026-10-17 17:55:00 UTC');
check(ChmiOther::slot($now) === '202610171700', 'slot(17:55Z) = 202610171700');
check(ChmiOther::slotCandidates($now) === ['202610171700', '202610171600', '202610171500', '202610171400'], 'four candidate slots, newest first');
check(ChmiOther::slotEnd('202610171700') === strtotime('2026-10-17 17:00:00 UTC'), 'slotEnd = 17:00Z');
check(ChmiOther::slotStart('202610171700') === strtotime('2026-10-17 16:00:00 UTC'), 'slotStart = 16:00Z (= opendata startTime)');
check(ChmiOther::slot(strtotime('2026-10-18 00:10:00 UTC')) === '202610180000', 'midnight slot');
check(ChmiOther::slotCandidates(strtotime('2026-10-18 00:10:00 UTC'))[1] === '202610172300', 'candidate crosses the day boundary');
check(ChmiOther::layerUrl('PM2_5', '202610171700') === 'https://data-provider.chmi.cz/api/data/ovzdusi/stanice/mereni/PM2_5/1h/202610171700', 'layer URL');

// --- parseValue ---------------------------------------------------------------
check(ChmiOther::parseValue('18,0', 'pm10') === 18.0, '"18,0" → 18.0 (decimal comma)');
check(ChmiOther::parseValue('19.9', 'pm10') === 19.9, '"19.9" → 19.9 (decimal point)');
check(ChmiOther::parseValue('N/A', 'pm25') === null, '"N/A" → null');
check(ChmiOther::parseValue('', 'pm25') === null, 'empty → null');
check(ChmiOther::parseValue(null, 'pm25') === null, 'null → null');
check(ChmiOther::parseValue('0,0', 'pm10') === 0.0, 'PM 0,0 stays 0.0');
check(ChmiOther::parseValue('0,0', 'no2') === null, 'NO2 0,0 → null (analyser outage sentinel)');
check(ChmiOther::parseValue('0,0', 'o3') === null, 'O3 0,0 → null');
check(ChmiOther::parseValue('-5,0', 'pm10') === null, 'negative → null');
check(ChmiOther::parseValue('2500', 'pm10') === null, 'above limit → null');
check(ChmiOther::parseValue('abc', 'pm10') === null, 'garbage → null');

// --- parseLayer on the real PM10 layer -------------------------------------------
$pm10 = ChmiOther::parseLayer(fixture('chmi_other_PM10_202610171700.json'), 'pm10');
check(count($pm10) === 38, 'PM10 layer: 38 watched stations present (got ' . count($pm10) . ')');
check(($pm10['TORD'] ?? null) === 18.0, 'TORD Ostrava-Radvanice ZÚ PM10 = 18.0');
check(($pm10['TORO'] ?? null) === 52.0, 'TORO Ostrava-Radvanice OZO PM10 = 52.0');
check(($pm10['BBMS'] ?? null) === 30.8, 'BBMS Brno-Svatoplukova PM10 = 30.8');
check(array_key_exists('ARER', $pm10) && $pm10['ARER'] === null, 'ARER present with N/A → null');
check(!isset($pm10['1AKH']) && !isset($pm10['TOFF']) && !isset($pm10['ALIB']), 'foreign and ČHMÚ-owned codes are not in the whitelist output');
check(!isset($pm10['STCS']), 'STCS has no PM10 in the layer → absent');

$no2 = ChmiOther::parseLayer(fixture('chmi_other_NO2_202610171700.json'), 'no2');
check(($no2['TOSJ'] ?? null) === 64.3, 'TOSJ NO2 = 64.3');
check(array_key_exists('ZZZS', $no2) && $no2['ZZZS'] === null, 'ZZZS NO2 "0,0" → null');
check(array_key_exists('TORD', $no2) && $no2['TORD'] === null, 'TORD NO2 N/A → null');

$o3 = ChmiOther::parseLayer(fixture('chmi_other_O3_202610171700.json'), 'o3');
check(count($o3) === 9, 'O3 layer: 9 watched stations (got ' . count($o3) . ')');
check(($o3['TORD'] ?? null) === 41.9, 'TORD O3 = 41.9');

$pm25 = ChmiOther::parseLayer(fixture('chmi_other_PM2_5_202610171700.json'), 'pm25');
check(($pm25['TJBL'] ?? null) === 62.0, 'TJBL Jablunkov PM2.5 = 62.0');
check(($pm25['UUKT'] ?? null) === 1.0, 'UUKT PM2.5 = 1.0 (kept; QC is a human decision)');

// --- merge: single slot ---------------------------------------------------------
$S = '202610171700';
$P = '202610171600';
$layersS = ['pm10' => $pm10, 'pm25' => $pm25, 'no2' => $no2, 'o3' => $o3];
$merged = ChmiOther::merge([$S => $layersS], $now);
$byCode = array_column($merged, null, 'code');
check(count($merged) === 30, '30 stations with at least one value in slot 1700 (got ' . count($merged) . ')');
check(!isset($byCode['ARER']) && !isset($byCode['MLOS']) && !isset($byCode['ZROZ']), 'all-N/A stations dropped');
check(isset($byCode['TORD']) && $byCode['TORD']['values'] === ['pm10' => 18.0, 'pm25' => 13.0, 'o3' => 41.9], 'TORD values pm10/pm25/o3, no NO2');
check($byCode['TORD']['owner'] === 'Zdravotní ústav Ostrava / město Ostrava', 'TORD owner label');
check($byCode['TORD']['measured_at'] === '2026-10-17T16:00:00+00:00', 'measured_at = start of the hour');
check($byCode['TORD']['slot'] === $S && $byCode['TORD']['age_min'] === 55, 'slot and age (55 min after the hour end)');
check($byCode['TORD']['stale'] === false, 'not stale');
check($byCode['TORD']['value_times']['pm25'] === strtotime('2026-10-17 16:00:00 UTC'), 'value_times carry the hour start per pollutant');
check(isset($byCode['ZZZS']) && $byCode['ZZZS']['values'] === ['pm10' => 17.3, 'pm25' => 10.9], 'ZZZS keeps PM, drops zero gases');
check(isset($byCode['STCS']) && array_keys($byCode['STCS']['values']) === ['no2', 'o3'], 'STCS gases only');
$codes = array_column($merged, 'code');
$sorted = $codes; sort($sorted, SORT_STRING);
check($codes === $sorted, 'output sorted by code');

// --- merge: fallback to the previous slot --------------------------------------------
$layersP = [
    'pm10' => ['ARER' => 12.0, 'TORD' => 99.0],   // ARER only in P; TORD has a newer value in S → S wins
    'pm25' => [],
    'no2'  => ['TORD' => 30.0],                    // TORD NO2 N/A in S → P fills it
    'o3'   => [],
];
$merged2 = ChmiOther::merge([$P => $layersP, $S => $layersS], $now); // deliberately older slot first
$byCode2 = array_column($merged2, null, 'code');
check(count($merged2) === 31, 'previous slot adds ARER (31 stations)');
check(($byCode2['TORD']['values']['pm10'] ?? null) === 18.0, 'newest slot wins for PM10');
check(($byCode2['TORD']['values']['no2'] ?? null) === 30.0, 'N/A in newest slot → value from previous slot');
check($byCode2['TORD']['value_times']['no2'] === strtotime('2026-10-17 15:00:00 UTC'), 'fallback value keeps its own hour (15:00Z start)');
check($byCode2['TORD']['measured_at'] === '2026-10-17T16:00:00+00:00' && $byCode2['TORD']['slot'] === $S, 'station measured_at stays at the newest used slot');
check($byCode2['ARER']['slot'] === $P && $byCode2['ARER']['age_min'] === 115 && $byCode2['ARER']['measured_at'] === '2026-10-17T15:00:00+00:00', 'ARER dated by the previous slot');
check($byCode2['ARER']['stale'] === false, 'ARER 1 h 55 min old → not stale yet');

// --- stale -------------------------------------------------------------------------
$late = strtotime('2026-10-17 20:30:00 UTC'); // 3 h 30 min after slot end
$merged3 = ChmiOther::merge([$S => $layersS], $late);
$byCode3 = array_column($merged3, null, 'code');
check($byCode3['TORD']['stale'] === true && $byCode3['TORD']['age_min'] === 210, 'older than 3 h → stale with age 210 min');

// --- stations table sanity --------------------------------------------------------------
$bad = 0;
foreach (ChmiOther::STATIONS as $code => $row) {
    if (!preg_match('/^[A-Z]{4}$/', $code) || count($row) !== 4) { $bad++; continue; }
    [$name, $owner, $lat, $lon] = $row;
    if ($name === '' || $owner === '' || $lat < 48.5 || $lat > 51.1 || $lon < 12.0 || $lon > 18.9) $bad++;
}
check($bad === 0 && count(ChmiOther::STATIONS) === 40, '40 stations, all with name/owner and CZ coordinates');

echo "\n" . ($failures === 0 ? 'ALL PASS' : $failures . ' FAILURE(S)') . "\n";
exit($failures === 0 ? 0 : 1);
