<?php
// alerts-proxy.php — výstrahy ČHMÚ (SIVS, CAP 1.2 open data), POUZE kvalita ovzduší
//
// Stejná logika jako nosicheck.cz (cron/refresh_alerts.php + api/alerts.php),
// bez cronu: cache se obnovuje při prvním požadavku po 10 minutách. Index
// adresáře (91 kB) se stahuje vždy, bulletin (1,7 MB) jen když se změnil název.
// Cache drží všechny kategorie, ven jde jen `cat = smog` (smogová situace,
// prach, ozon, NO₂, SO₂). Výstrahy expirují podle vlastního času — filtruje se
// při každém požadavku. Neúspěšné obnovení NIKDY nevyprázdní cache: prázdný
// seznam je reálný stav („nic neplatí"), ne důsledek výpadku sítě.
// ?token=CRON_TOKEN vynutí obnovení (volitelný cron).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Alerts.php';

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);
header('Cache-Control: public, max-age=300');

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/alerts_latest.json';
$lockFile  = $cacheDir . '/alerts_build.lock';
$cacheTime = 600;
$now = time();

if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cached = Http::readJson($cacheFile);
$force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
$fresh = $cached !== null && (strtotime((string)($cached['generated'] ?? '')) ?: 0) > $now - $cacheTime;

if (!$fresh || $force) {
    $lock = Http::tryLock($lockFile);
    if ($lock !== null) {
        $result = refreshAlerts($cached, $now);
        if ($result !== null) {
            Http::atomicWrite($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
            $cached = $result;
        } elseif ($cached !== null) {
            // Obnovení selhalo: jen prodloužit platnost, ať to nezkouší každý požadavek
            $cached['generated'] = gmdate('Y-m-d\TH:i:s\Z', $now);
            $cached['refresh_failed'] = true;
            Http::atomicWrite($cacheFile, json_encode($cached, JSON_UNESCAPED_UNICODE));
        }
        Http::unlock($lock);
    }
}

if ($cached === null) {
    Http::fail('Výstrahy ČHMÚ zatím nejsou k dispozici.', 404);
}

// Servírujeme jen výstrahy na kvalitu ovzduší, aktuálně platné/chystané
$smog = array_values(array_filter(
    Alerts::active($cached['alerts'] ?? [], $now),
    fn($a) => ($a['cat'] ?? '') === 'smog'
));
$payload = Alerts::build($smog, strtotime((string)($cached['generated'] ?? '')) ?: $now,
    (string)($cached['source'] ?? ''), $cached['issued'] ?? null);
$payload['filter'] = 'smog';
Http::jsonOut($payload);

/**
 * Stáhne nejnovější bulletin (jen když se změnil) a vrátí nový obsah cache,
 * nebo null když nic nešlo stáhnout/parsovat (volající ponechá starou cache).
 */
function refreshAlerts(?array $cached, int $now): ?array
{
    $xml = null;
    $source = null;

    [$index, $code, ] = Http::fetch(Alerts::INDEX_URL, 25);
    if ($index !== null && $code === 200) {
        $newest = Alerts::newestFromIndex($index);
        if ($newest !== null) {
            if ($cached !== null && ($cached['source'] ?? null) === $newest) {
                // Stejný bulletin: jen přerazítkovat, active() se počítá při výdeji
                $kept = Alerts::active($cached['alerts'] ?? [], $now);
                return Alerts::build($kept, $now, $newest, $cached['issued'] ?? null);
            }
            [$body, $bcode, ] = Http::fetch(Alerts::INDEX_URL . $newest, 60);
            if ($body !== null && $bcode === 200) {
                $xml = $body;
                $source = $newest;
            }
        }
    }

    if ($xml === null) {
        [$body, $fcode, ] = Http::fetch(Alerts::FALLBACK_URL, 60);
        if ($body === null || $fcode !== 200) {
            return null;
        }
        $xml = $body;
        $source = 'live';
    }

    $parsed = Alerts::parseCap($xml);
    if ($parsed === null) {
        return null;
    }
    return Alerts::build(Alerts::active($parsed, $now), $now, (string)$source);
}
