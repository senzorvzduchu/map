<?php
/**
 * Offline test: ČHMÚ CAP index parsing / categorisation / warning extraction.
 * No network, no database, no var/ access.
 *
 * Run: php tests/test_alerts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit('CLI only'); }

require_once dirname(__DIR__) . '/www/lib/Alerts.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  OK  ' : ' FAIL ') . $what . "\n";
    if (!$ok) { $failures++; }
}

// Fixed "now": 2026-08-21 07:00 UTC (09:00 Prague)
$now = strtotime('2026-08-21 07:00:00 UTC');

// --- newestFromIndex ---------------------------------------------------------

$index = <<<'HTML'
<html><head><title>Index of /meteorology/weather/alerts/cap/</title></head><body><pre>
<a href="../">../</a>
<a href="alert_cap_50_010718.xml">alert_cap_50_010718.xml</a>       01-Jun-2025 07:18       1915939
<a href="alert_cap_50_201610.xml">alert_cap_50_201610.xml</a>       20-Aug-2026 16:11       1726673
<a href="alert_cap_50_190838.xml">alert_cap_50_190838.xml</a>       19-Aug-2026 08:39       1799559
<a href="alert_cap_70_181200.xml">alert_cap_70_181200.xml</a>       18-Aug-2026 14:40         59416
</pre></body></html>
HTML;

check(Alerts::newestFromIndex($index) === 'alert_cap_50_201610.xml', 'newest bulletin picked by mtime, not by filename');
check(Alerts::newestFromIndex('<html><body>Error 404</body></html>') === null, 'unparseable index returns null');
// The _70_ run is outlook-only and must never be chosen even when it is newest.
$only70 = '<a href="alert_cap_70_211200.xml">alert_cap_70_211200.xml</a>       21-Aug-2026 12:00         59416';
check(Alerts::newestFromIndex($only70) === null, '_70_ outlook bulletins are ignored');

// --- categorize --------------------------------------------------------------

$cases = [
    // air quality — the reason this feature exists
    'Smogová situace'                        => 'smog',
    'Regulace'                               => 'smog',
    'Zvýšené koncentrace přízemního ozónu'   => 'smog',
    'Vysoké koncentrace prachu'              => 'smog',
    'Zvýšené koncentrace oxidu dusičitého'   => 'smog',
    'Zvýšené koncentrace oxidu siřičitého'   => 'smog',
    // temperature
    'Extrémně vysoké teploty'                => 'heat',
    'Vysoké teploty'                         => 'heat',
    'Nízké teploty'                          => 'cold',
    'Silné mrazy'                            => 'cold',
    // weather
    'Silný vítr'                             => 'wind',
    'Vichřice'                               => 'wind',
    'Velmi silné bouřky'                     => 'storm',
    'Vydatný déšť'                           => 'rain',
    'Povodňová bdělost'                      => 'flood',
    'Sněhové jazyky'                         => 'snow',
    'Nebezpečí požárů'                       => 'fire',
    'Mlha'                                   => 'fog',
    'Jiný nebezpečný jev'                    => 'other',
];
foreach ($cases as $event => $expect) {
    check(Alerts::categorize($event) === $expect, "categorize: \"$event\" → $expect");
}
// Regression: "námrazové jevy" contains the letters of "mraz" but is ice, not cold.
check(Alerts::categorize('Námrazové jevy na pozemních komunikacích') === 'ice', 'námraza is ice, not cold');
check(Alerts::categorize('Ledovka') === 'ice', 'ledovka is ice');

// --- parseCap ----------------------------------------------------------------

check(Alerts::parseCap('') === null, 'empty body rejected');
check(Alerts::parseCap('Service Unavailable') === null, 'error text rejected');
check(Alerts::parseCap('<html><body>oops</body></html>') === null, 'HTML error page rejected');

$cap = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>2.49.0.0.203.0.CZ.260820161036.XOCZ50_OKPR_000328</identifier>
  <sender>chmi@chmi.cz</sender>
  <sent>2026-08-20T18:10:36+02:00</sent>
  <status>Actual</status>
  <msgType>Update</msgType>
  <info>
    <language>cs</language>
    <event>Žádná výstraha před větrem</event>
    <severity>Minor</severity>
    <area>
      <areaDesc>Hlavní město Praha</areaDesc>
      <geocode><valueName>CISORP</valueName><value>1100</value></geocode>
    </area>
  </info>
  <info>
    <language>cs</language>
    <event>Smogová situace</event>
    <severity>Moderate</severity>
    <onset>2026-08-21T06:00:00+02:00</onset>
    <expires>2026-08-22T06:00:00+02:00</expires>
    <description>Zvýšené  koncentrace
    suspendovaných částic PM10.</description>
    <instruction>Omezte fyzickou zátěž venku.</instruction>
    <parameter><valueName>situation</valueName><value>Inverzní charakter počasí.</value></parameter>
    <parameter><valueName>awareness_level</valueName><value>2; yellow; Moderate</value></parameter>
    <area>
      <areaDesc>Moravskoslezský kraj (Ostrava, Havířov)</areaDesc>
      <geocode><valueName>CISORP</valueName><value>8119</value></geocode>
      <geocode><valueName>EMMA_ID</valueName><value>CZ08119</value></geocode>
      <geocode><valueName>CISORP</valueName><value>8108</value></geocode>
    </area>
  </info>
  <info>
    <language>en-GB</language>
    <event>Smog situation</event>
    <severity>Moderate</severity>
    <area>
      <areaDesc>Moravian-Silesian Region</areaDesc>
      <geocode><valueName>CISORP</valueName><value>8119</value></geocode>
    </area>
  </info>
  <info>
    <language>cs</language>
    <event>Velmi silné bouřky</event>
    <severity>Severe</severity>
    <onset>2026-08-21T16:00:00+02:00</onset>
    <expires>2026-08-22T02:00:00+02:00</expires>
    <parameter><valueName>awareness_level</valueName><value>3; orange; Severe</value></parameter>
    <area>
      <areaDesc>Jihomoravský kraj</areaDesc>
      <geocode><valueName>CISORP</valueName><value>6203</value></geocode>
    </area>
  </info>
  <info>
    <language>cs</language>
    <event>Silný vítr</event>
    <severity>Moderate</severity>
    <onset>2026-08-19T06:00:00+02:00</onset>
    <expires>2026-08-20T18:00:00+02:00</expires>
    <parameter><valueName>awareness_level</valueName><value>2; yellow; Moderate</value></parameter>
    <area>
      <areaDesc>Karlovarský kraj</areaDesc>
      <geocode><valueName>CISORP</valueName><value>4101</value></geocode>
    </area>
  </info>
  <info>
    <language>cs</language>
    <event>Silné bouřky</event>
    <severity>Moderate</severity>
    <onset>2026-08-21T16:00:00+02:00</onset>
    <expires>2026-08-22T02:00:00+02:00</expires>
    <parameter><valueName>awareness_level</valueName><value>2; yellow; Moderate</value></parameter>
    <area>
      <areaDesc>Nezařazená oblast bez kódu</areaDesc>
    </area>
  </info>
</alert>
XML;

$parsed = Alerts::parseCap($cap);
check(is_array($parsed), 'valid CAP parses');
check(count($parsed) === 3, '"Žádná výstraha", en-GB duplicate and area-less block all dropped');

$byEvent = [];
foreach ($parsed as $a) { $byEvent[$a['event']] = $a; }

$smog = $byEvent['Smogová situace'] ?? null;
check($smog !== null, 'smog warning extracted');
check($smog['cat'] === 'smog' && $smog['level'] === 2, 'smog categorised, awareness_level read');
check($smog['orp'] === [8108, 8119], 'CISORP codes collected, sorted, EMMA_ID ignored');
check($smog['regions'] === ['Moravskoslezský kraj'], 'region name kept without the district list');
check($smog['desc'] === 'Zvýšené koncentrace suspendovaných částic PM10.', 'description whitespace collapsed');
check($smog['instr'] === 'Omezte fyzickou zátěž venku.', 'instruction kept');
check($smog['situation'] === 'Inverzní charakter počasí.', 'synoptic situation kept');
check($smog['onset'] === '2026-08-21T04:00:00Z' && $smog['expires'] === '2026-08-22T04:00:00Z', 'times normalised to UTC');
check($smog['issued'] === '2026-08-20T16:10:36Z', 'bulletin publication time carried on every warning');
check(strlen($smog['id']) === 12, 'stable short id generated');

// --- active ------------------------------------------------------------------

$live = Alerts::active($parsed, $now);
check(count($live) === 2, 'the wind warning that ended yesterday is dropped');
check($live[0]['event'] === 'Velmi silné bouřky', 'orange outranks yellow even when it starts later');
check($live[0]['inforce'] === false, 'upcoming warning flagged as not yet in force');
check($live[1]['event'] === 'Smogová situace' && $live[1]['inforce'] === true, 'in-force smog warning kept second');

// Same level → air quality wins over thunderstorms (this is an air-pollution map).
$tie = Alerts::active([
    ['event' => 'Silné bouřky', 'cat' => 'storm', 'level' => 2, 'onset' => '2026-08-21T05:00:00Z', 'expires' => '2026-08-22T02:00:00Z'],
    ['event' => 'Smogová situace', 'cat' => 'smog', 'level' => 2, 'onset' => '2026-08-21T05:00:00Z', 'expires' => '2026-08-22T02:00:00Z'],
], $now);
check($tie[0]['event'] === 'Smogová situace', 'at equal level, smog outranks thunderstorms');

// Warnings with no expiry must never silently vanish.
$noExpiry = Alerts::active([
    ['event' => 'Jiný jev', 'cat' => 'other', 'level' => 2, 'onset' => null, 'expires' => null],
], $now);
check(count($noExpiry) === 1 && $noExpiry[0]['inforce'] === true, 'warning without onset/expires stays and counts as in force');

// active() is idempotent — cron re-runs it on its own cached output.
check(Alerts::active($live, $now) == $live, 'active() is idempotent');

// --- build -------------------------------------------------------------------

$payload = Alerts::build($live, $now, 'alert_cap_50_201610.xml');
check($payload['generated'] === '2026-08-21T07:00:00Z', 'generated timestamp is ISO UTC');
check($payload['source'] === 'alert_cap_50_201610.xml', 'source bulletin recorded');
check($payload['issued'] === '2026-08-20T16:10:36Z', 'issued time surfaces in the payload');
check(count($payload['alerts']) === 2, 'payload carries the active warnings');
check(json_encode($payload) !== false, 'payload is JSON-encodable');

$empty = Alerts::build([], $now, 'alert_cap_50_201610.xml', '2026-08-20T16:10:36Z');
check($empty['alerts'] === [] && $empty['issued'] === '2026-08-20T16:10:36Z', 'issued survives an all-clear bulletin');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
