<?php
// wind-proxy.php — větrný grid pro leaflet-velocity + unášení interpolace
//
// Zdroj: Open-Meteo (zdarma, bez klíče), vítr ve výšce 10 m, 15×12 = 180 bodů
// nad ČR (bbox s přesahem 12.0–19.0 E / 48.5–51.25 N, krok 0.5° × 0.25°),
// řazení row-major od SZ rohu (pořadí, které leaflet-velocity očekává).
// Meteorologický směr „odkud fouká" → U/V složky.
//
// Bez cronu: stejný vzor jako ostatní proxy — cache 60 min, po expiraci se grid
// přestaví při prvním požadavku (~3 dotazy na Open-Meteo, 2–5 s). Zámek brání
// souběžným přestavbám; když Open-Meteo selže, servíruje se stará cache.
// Volitelně lze grid představět cronem: wind-proxy.php?token=CRON_TOKEN
// (obejde cache, nikdy nevrací 401 — jen ignoruje špatný token).

require_once __DIR__ . '/config.php';

ini_set('serialize_precision', '-1');

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/wind_latest.json';
$lockFile  = $cacheDir . '/wind_build.lock';
$cacheTime = 3600;

if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$forceRebuild = isset($_GET['token']) && $_GET['token'] === CRON_TOKEN;
$cacheFresh = file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime);

if ($cacheFresh && !$forceRebuild) {
    header('X-Source: Local-Cache');
    readfile($cacheFile);
    exit;
}

// ==========================================
// Zámek: jen jeden proces staví, ostatní dostanou starou cache
// ==========================================
$lock = fopen($lockFile, 'c');
$haveLock = $lock && flock($lock, LOCK_EX | LOCK_NB);
if (!$haveLock) {
    if (file_exists($cacheFile)) {
        header('X-Source: Local-Cache-Stale');
        readfile($cacheFile);
    } else {
        http_response_code(503);
        echo json_encode(['error' => 'Větrná data se právě připravují, zkuste to za chvíli.']);
    }
    exit;
}

// ==========================================
// GRID
// ==========================================
const WIND_LO1 = 12.0;  const WIND_LO2 = 19.0;  const WIND_DX = 0.5;   // nx = 15
const WIND_LA1 = 51.25; const WIND_LA2 = 48.50; const WIND_DY = 0.25;  // ny = 12

$nx = (int)round((WIND_LO2 - WIND_LO1) / WIND_DX) + 1;
$ny = (int)round((WIND_LA1 - WIND_LA2) / WIND_DY) + 1;

$lats = [];
$lngs = [];
for ($iy = 0; $iy < $ny; $iy++) {
    for ($ix = 0; $ix < $nx; $ix++) {
        $lats[] = round(WIND_LA1 - $iy * WIND_DY, 4);
        $lngs[] = round(WIND_LO1 + $ix * WIND_DX, 4);
    }
}
$total = count($lats); // 180

// ==========================================
// FETCH (cURL — Active24 blokuje custom headers přes stream context)
// ==========================================
function windFetchChunk(array $lats, array $lngs) {
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude'        => implode(',', $lats),
        'longitude'       => implode(',', $lngs),
        'current'         => 'wind_speed_10m,wind_direction_10m',
        'wind_speed_unit' => 'ms',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false || $code !== 200) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (isset($data['current'])) $data = [$data]; // jediný bod = objekt, více bodů = pole
    if (count($data) !== count($lats)) return null;

    $out = [];
    foreach ($data as $point) {
        if (!isset($point['current']['wind_speed_10m'], $point['current']['wind_direction_10m'])) {
            return null; // jeden vadný bod = celá dávka neplatná, nikdy částečný grid
        }
        $out[] = [
            'speed_ms' => (float)$point['current']['wind_speed_10m'],
            'dir_deg'  => (float)$point['current']['wind_direction_10m'],
        ];
    }
    return $out;
}

$winds = [];
$failed = false;
for ($off = 0; $off < $total; $off += 60) {
    $chunk = windFetchChunk(array_slice($lats, $off, 60), array_slice($lngs, $off, 60));
    if ($chunk === null) { $failed = true; break; }
    array_push($winds, ...$chunk);
}

if ($failed) {
    flock($lock, LOCK_UN);
    fclose($lock);
    if (file_exists($cacheFile)) {
        header('X-Source: Local-Cache-Stale');
        readfile($cacheFile);
    } else {
        http_response_code(502);
        echo json_encode(['error' => 'Open-Meteo nedostupné a žádná cache k dispozici']);
    }
    exit;
}

// Meteorologický směr „odkud" → vektor
$uData = [];
$vData = [];
foreach ($winds as $w) {
    $rad = deg2rad($w['dir_deg']);
    $uData[] = round(-$w['speed_ms'] * sin($rad), 2);
    $vData[] = round(-$w['speed_ms'] * cos($rad), 2);
}

$refTime = gmdate('Y-m-d\TH:i:s\Z');
$headerBase = [
    'parameterCategory' => 2,
    'parameterUnit'     => 'm.s-1',
    'nx' => $nx, 'ny' => $ny,
    'lo1' => WIND_LO1, 'la1' => WIND_LA1, 'lo2' => WIND_LO2, 'la2' => WIND_LA2,
    'dx' => WIND_DX, 'dy' => WIND_DY,
    'refTime' => $refTime,
];

$out = [
    [
        'header' => $headerBase + ['parameterNumber' => 2, 'parameterNumberName' => 'eastward_wind'],
        'data'   => $uData,
    ],
    [
        'header' => $headerBase + ['parameterNumber' => 3, 'parameterNumberName' => 'northward_wind'],
        'data'   => $vData,
    ],
];

$json = json_encode($out);

// Atomický zápis (tmp + rename), aby čtenáři neviděli rozepsaný soubor
$tmp = $cacheFile . '.tmp';
if (file_put_contents($tmp, $json) !== false) {
    rename($tmp, $cacheFile);
}

flock($lock, LOCK_UN);
fclose($lock);

header('X-Source: OpenMeteo-Live');
echo $json;
?>
