<?php
declare(strict_types=1);

/**
 * AirGradient — čistá logika (filtr + normalizace) pro ag-proxy.php a testy.
 *
 * Zdroj: https://api.airgradient.com/public/api/v1/world/locations/measures/current
 * (bez tokenu, celý svět, ~1.5 MB gzip, jen aktuální hodnoty). Licence CC BY-SA 4.0 —
 * mapa musí uvádět „Data: AirGradient (CC BY-SA 4.0)".
 *
 * Pole záznamu (ověřeno 16. 10. 2026): locationId, locationName, publicLocationName,
 * latitude, longitude, offline, pm01, pm02 (= PM2.5), pm10, pm003Count, atmp, rhum,
 * rco2, tvoc, tvocIndex, noxIndex, wifi, timestamp (ISO UTC), timezone, model,
 * firmwareVersion, publicContributorName. Korigované pole (pm02_corrected) ve
 * veřejném feedu NENÍ — hodnoty jsou surové z Plantower PMS5003.
 */
final class AirGradient
{
    /** CZ bounding box (stejný jako PurpleAir): lat 48.55–51.06, lon 12.09–18.86. */
    public const LAT_MIN = 48.55;
    public const LAT_MAX = 51.06;
    public const LON_MIN = 12.09;
    public const LON_MAX = 18.86;

    /**
     * Senzory v boxu, ale mimo ČR (stav 16. 10. 2026) — vyřazeny stejně jako u PurpleAir,
     * aby neovlivňovaly interpolaci na hranici. Při změně stačí upravit seznam.
     *  90098 Dingolfing (DE), 187808 Regenstauf (DE), 193064 Dresden-Pappritz (DE),
     * 159894 Gbstr (DE), 182589 Rzuchów (PL), 196458 Lietavská Lúčka (SK)
     */
    public const EXCLUDE_IDS = [90098, 187808, 193064, 159894, 182589, 196458];

    /** Měření mladší než ONLINE_SEC = online; starší se kreslí šedě; starší než MAX_AGE_SEC se vypouští. */
    public const ONLINE_SEC  = 30 * 60;
    public const MAX_AGE_SEC = 2 * 3600;

    /**
     * Z celosvětového pole záznamů vybere CZ podmnožinu a převede ji na tvar,
     * který mapa používá u ostatních sítí (klíče jako pa-proxy.php).
     *
     * @param array<int, array<string, mixed>> $world dekódovaný JSON feedu
     * @param int $now unix čas „teď" (parametr kvůli testům)
     * @return array<int, array<string, mixed>> seřazeno podle id
     */
    public static function normalise(array $world, int $now): array
    {
        $out = [];
        foreach ($world as $r) {
            if (!is_array($r)) continue;
            $id  = $r['locationId'] ?? null;
            $lat = $r['latitude'] ?? null;
            $lon = $r['longitude'] ?? null;
            if (!is_int($id) || !is_numeric($lat) || !is_numeric($lon)) continue;
            $lat = (float)$lat;
            $lon = (float)$lon;
            if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lon < self::LON_MIN || $lon > self::LON_MAX) continue;
            if (in_array($id, self::EXCLUDE_IDS, true)) continue;
            if (!empty($r['offline'])) continue;

            $ts = isset($r['timestamp']) ? strtotime((string)$r['timestamp']) : false;
            if ($ts === false) continue;
            $age = $now - $ts;
            if ($age > self::MAX_AGE_SEC) continue;

            $pm25 = self::num($r['pm02'] ?? null, 0, 1000);
            $pm10 = self::num($r['pm10'] ?? null, 0, 2000);
            if ($pm25 === null && $pm10 === null) continue; // jen CO₂/T/RH → na mapě PM nic nezobrazí

            $name = trim((string)($r['publicLocationName'] ?? $r['locationName'] ?? ''));
            if ($name === '') $name = 'AirGradient #' . $id;

            $out[] = [
                'id'          => $id,
                'name'        => $name,
                'latitude'    => round($lat, 6),
                'longitude'   => round($lon, 6),
                'pm25'        => $pm25,
                'pm10'        => $pm10,
                'pm1'         => self::num($r['pm01'] ?? null, 0, 1000),
                'temperature' => self::num($r['atmp'] ?? null, -50, 70),
                'humidity'    => self::num($r['rhum'] ?? null, 0, 100, 0),
                'co2'         => self::num($r['rco2'] ?? null, 300, 10000, 0),
                'model'       => isset($r['model']) ? (string)$r['model'] : null,
                'measured_at' => gmdate('c', $ts),
                'age_min'     => (int)floor(max(0, $age) / 60),
                'online'      => $age <= self::ONLINE_SEC,
            ];
        }
        usort($out, fn($a, $b) => $a['id'] <=> $b['id']);
        return $out;
    }

    /** Číslo v rozsahu zaokrouhlené na $dec míst, jinak null. */
    private static function num(mixed $v, float $min, float $max, int $dec = 1): ?float
    {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        if ($f < $min || $f > $max) return null;
        return round($f, $dec);
    }
}
