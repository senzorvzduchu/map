<?php
/**
 * Offline test: ČHMÚ forecast ZIP reading, area parsing, merging, anchoring.
 * No network, no database, no var/ access.
 *
 * The ZIP fixture is built here with gzdeflate() rather than committed as a
 * binary, so the deflate path Forecast::unzip() actually uses is exercised.
 *
 * Run: php tests/test_forecast.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/Forecast.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}

/** Minimal ZIP writer (deflate or stored) — test-side counterpart of unzip(). */
function makeZip(array $entries, int $method = 8): string
{
    $local = '';
    $central = '';
    foreach ($entries as $name => $body) {
        $crc   = crc32($body);
        $usize = strlen($body);
        $data  = $method === 8 ? gzdeflate($body) : $body;
        $csize = strlen($data);
        $offset = strlen($local);

        $local .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $method, 0, 0, $crc, $csize, $usize, strlen($name), 0)
            . $name . $data;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, 0, 0, $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset)
            . $name;
    }
    return $local . $central . "PK\x05\x06"
        . pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);
}

/**
 * A realistic area document: the "mapa" hour at $t0 — deliberately on a
 * different level, as in the real feed — then $hours forecast hours from $t0.
 */
function areaJson(string $comp, string $code, string $name, int $t0, int $hours, ?array $holeAt = null): string
{
    $values = [[
        'dt_beg_utc' => gmdate('Y-m-d\TH:i:s\Z', $t0),
        'dt_end_utc' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 3600),
        'type'       => 'mapa',
        'min'        => '30', 'p_25' => '33', 'p_50' => '35', 'p_75' => '37', 'max' => '40',
    ]];
    for ($i = 0; $i <= $hours; $i++) {
        $ts = $t0 + $i * 3600;
        if ($holeAt !== null && in_array($i, $holeAt, true)) { continue; }
        $p50 = 10 + $i * 0.5;
        $values[] = [
            'dt_beg_utc' => gmdate('Y-m-d\TH:i:s\Z', $ts),
            'dt_end_utc' => gmdate('Y-m-d\TH:i:s\Z', $ts + 3600),
            'type'       => 'predpoved',
            'min'        => (string)($p50 - 4),
            'p_25'       => (string)($p50 - 2),
            'p_50'       => (string)$p50,
            'p_75'       => (string)($p50 + 2),
            'max'        => (string)($p50 + 5),
        ];
    }
    return json_encode([
        'zaznamID'       => 'boxploty_' . $comp . '_KRAJ_' . $code,
        'datovyZdrojID'  => 'ovzdusi.predpovedi',
        'datovyTokID'    => 'boxplot_chart',
        'datumVytvoreni' => gmdate('Y-m-d\TH:i:s\Z', $t0 + 5400),
        'verzeDat'       => '1.0',
        'data' => [
            'metadata' => [
                'DataSourceID'   => 'AirQuality',
                'Dataset'        => 'BoxplotChart',
                'OriginFile'     => 'x.csv',
                'ComponentCode'  => $comp,
                'AreaType'       => 'KRAJ',
                'AreaName'       => $name,
                'AreaCode'       => $code,
                'AreaCodeSystem' => 'CZ-NUTS3',
                'Identifier'     => $comp . '-KRAJ-' . $code,
            ],
            'values' => $values,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

// Fixed "now": 2026-08-21 09:20 UTC. Freshest published run is then 07z.
$now = strtotime('2026-08-21 09:20:00 UTC');
$hour0 = intdiv($now, 3600) * 3600;

// --- runCandidates -----------------------------------------------------------

check(Forecast::runCandidates($now) === [7, 6, 5], 'newest candidate run at 09:20 UTC is 07z');
check(Forecast::runCandidates(strtotime('2026-08-21 08:30:00 UTC')) === [6, 5, 4], 'at 08:30 the 07z run is not assumed published yet');
check(Forecast::runCandidates(strtotime('2026-08-21 00:30:00 UTC')) === [22, 21, 20], 'candidates wrap across midnight');

check(Forecast::runLabel(7, $now) === '2026-08-21T07', 'run label carries the date the file name lacks');
check(Forecast::runLabel(22, strtotime('2026-08-21 00:30:00 UTC')) === '2026-08-20T22', 'run label rolls back to yesterday before midnight');

// --- unzip -------------------------------------------------------------------

$zip = makeZip(['a.json' => str_repeat('{"x":1}', 500), 'b.json' => 'plain']);
$entries = Forecast::unzip($zip);
check(is_array($entries) && count($entries) === 2, 'deflate ZIP yields both entries');
check($entries['a.json'] === str_repeat('{"x":1}', 500), 'deflated entry round-trips');
check($entries['b.json'] === 'plain', 'short entry round-trips');

$stored = Forecast::unzip(makeZip(['s.json' => 'stored body'], 0));
check($stored !== null && $stored['s.json'] === 'stored body', 'stored (method 0) entries are read');

check(Forecast::unzip('<html>Error 500</html>') === null, 'HTML error page is not a ZIP');
check(Forecast::unzip('') === null, 'empty body returns null');
check(Forecast::unzip(substr($zip, 0, 40)) === null, 'truncated ZIP returns null');
$corrupt = $zip;
$corrupt[60] = chr((ord($corrupt[60]) + 1) % 256); // damage the deflate stream
check(Forecast::unzip($corrupt) === null, 'corrupted payload fails the CRC instead of returning garbage');

// --- parseArea ---------------------------------------------------------------

$fresh = areaJson('PM10', '19', 'Hlavní město Praha', $hour0, 72);
$a = Forecast::parseArea($fresh, $now);
check($a !== null && $a['area'] === '19' && $a['value_type'] === 'PM10', 'area code and value type mapped');
check($a['name'] === 'Hlavní město Praha', 'area name kept for the popup label');
check(count($a['points']) === Forecast::HORIZON_HOURS + 1, 'series trimmed to the analysis hour + HORIZON_HOURS');
check(array_key_first($a['points']) === $hour0, 'series starts at the first forecast hour');
$firstPoint = $a['points'][$hour0];
check($firstPoint === [8.0, 10.0, 12.0], 'p25/p50/p75 parsed as numbers in order');

// The observation map is a different product on a different level — splicing it
// onto the forecast would draw a cliff that only exists in the file format.
check(!in_array([33.0, 35.0, 37.0], $a['points'], true), 'the "mapa" record never enters the forecast line');
check($a['obs']['p50'] === 35.0 && $a['obs']['t'] === $hour0, 'the "mapa" record is kept aside as an observation');

// A run reaches us ~1:38 after its nominal hour and the cron may sit on it for
// another half hour, so by the time anyone looks, the analysis hour is well
// outside the plotted window. It must survive anyway — it is the fallback
// anchor level, not a plotted point. (Age-gating it here silently disabled the
// whole chmi_map anchoring tier in production once.)
$old = Forecast::parseArea(areaJson('PM10', '19', 'Praha', $hour0, 72), $now + 4 * 3600);
check($old !== null && $old['obs']['p50'] === 35.0, 'the observation survives once its hour has scrolled off the chart');

check(Forecast::parseArea(areaJson('PM2_5', '19', 'Praha', $hour0, 72), $now)['value_type'] === 'PM2.5',
    'PM2_5 is normalised to our PM2.5 value type');
check(Forecast::parseArea(areaJson('SO2', '19', 'Praha', $hour0, 72), $now) === null,
    'SO2 is published by CHMI but not a pollutant we carry');

// The rolling window keeps yesterday's run under the same file name, and its
// horizon still reaches into the future — only datumVytvoreni gives it away.
$stale = areaJson('PM10', '19', 'Praha', $hour0 - 86400, 96);
check(Forecast::parseArea($stale, $now) === null, 'yesterday\'s run under the same file name is rejected');
$sameFileYesterday = Forecast::parseArea($stale, $now - 86400);
check($sameFileYesterday !== null && count($sameFileYesterday['points']) === Forecast::HORIZON_HOURS + 1,
    '…rejected for its run age, not its horizon: the same file parses a day earlier');

check(Forecast::parseArea('not json', $now) === null, 'non-JSON returns null');
check(Forecast::parseArea('{"data":{}}', $now) === null, 'JSON without values returns null');

// --- merge / columnar --------------------------------------------------------

$merged = Forecast::merge([
    Forecast::parseArea(areaJson('PM10', '19', 'Praha', $hour0, 72), $now),
    Forecast::parseArea(areaJson('NO2', '19', 'Praha', $hour0, 72), $now),
    Forecast::parseArea(areaJson('PM10', '108', 'Kraj Vysočina', $hour0, 72), $now),
    null, // a component that failed to download must not break the rest
]);
// PHP casts numeric string keys to int; json_encode turns them back into "19".
check(array_keys($merged) === [19, 108], 'areas keyed by code, smallest first');
check(array_keys($merged['19']['series']) === ['PM10', 'NO2'], 'several pollutants merge into one area');
$s = $merged['19']['series']['PM10'];
check($s['t0'] === $hour0 && $s['step'] === 3600, 'columnar series carries its own t0/step');
check(count($s['p50']) === Forecast::HORIZON_HOURS + 1 && $s['p50'][2] === 11.0, 'columnar values line up with the hour grid');

$holed = Forecast::merge([Forecast::parseArea(areaJson('PM10', '19', 'Praha', $hour0, 72, [3, 4]), $now)]);
$hs = $holed['19']['series']['PM10'];
check($hs['p50'][2] === 11.0 && $hs['p50'][3] === null && $hs['p50'][5] === 12.5,
    'missing hours become null so the chart breaks the line instead of shifting it');

// --- modelBase / anchorFactor / applyAnchor ----------------------------------

check(Forecast::modelBase([10.0, 12.0, 14.0, 99.0]) === 12.0, 'model base averages the first hours only');
check(Forecast::modelBase([null, 10.0, null, 20.0, 30.0]) === 20.0, 'nulls are skipped, not counted as zero');
check(Forecast::modelBase([]) === null, 'an empty series has no base');
check(Forecast::modelBase([null, null]) === null, 'an all-null series has no base');

check(Forecast::anchorFactor(20.0, 10.0) === 2.0, 'plain ratio when the sensor reads above the model');
check(Forecast::anchorFactor(5.0, 10.0) === 0.5, 'ratio below 1 is kept');
// Real case: Praha 08z 2026-08-21 — stations ~21.6 µg/m³, model 6.9.
check(Forecast::anchorFactor(21.6, 6.9) === 3.0, 'the real ~3× regional/urban gap is representable');
check(Forecast::anchorFactor(200.0, 10.0) === Forecast::ANCHOR_MAX, 'a spiking sensor cannot blow up the curve');
check(Forecast::anchorFactor(1.0, 10.0) === Forecast::ANCHOR_MIN, 'clamped from below too');
check(Forecast::anchorFactor(20.0, 0.4) === 1.0, 'a near-zero model value disables anchoring');
check(Forecast::anchorFactor(null, 10.0) === 1.0, 'no reading disables anchoring');
check(Forecast::anchorFactor(0.0, 10.0) === 1.0, 'a zero reading disables anchoring');

$series = ['t0' => $hour0, 'step' => 3600, 'p25' => [8.0, null], 'p50' => [10.0, 12.0], 'p75' => [12.0, 14.0]];
$scaled = Forecast::applyAnchor($series, 2.0);
check($scaled['p50'] === [20.0, 24.0] && $scaled['p75'] === [24.0, 28.0], 'anchoring scales the whole box, not just the median');
check($scaled['p25'] === [16.0, null], 'nulls survive anchoring');
check($scaled['t0'] === $hour0 && $scaled['step'] === 3600, 'anchoring leaves the time grid alone');
check(Forecast::applyAnchor($series, 1.0) === $series, 'factor 1.0 is a no-op');

// --- krajForCisorp -----------------------------------------------------------

check(Forecast::krajForCisorp(1100) === '19', 'Praha (CISORP 1100) → NUTS3 19');
check(Forecast::krajForCisorp(8122) === '132', 'Ostravsko (CISORP 81xx) → Moravskoslezský 132');
check(Forecast::krajForCisorp(6203) === '116', 'Brněnsko (CISORP 62xx) → Jihomoravský 116');
check(count(array_unique(Forecast::KRAJ_BY_CISORP_PREFIX)) === 14, 'all 14 regions are mapped exactly once');
check(Forecast::krajForCisorp(9901) === null, 'unknown prefix returns null');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
