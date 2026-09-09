<?php
declare(strict_types=1);

/**
 * ČHMÚ air-quality forecast helpers (modelová předpověď, boxplot open data).
 * Pure functions — no I/O, no globals — so tests/test_forecast.php runs offline.
 *
 * Source: https://opendata.chmi.cz/air_quality/now/forecast/
 *   airquality_FC-boxplots_<KRAJ|ORP>_<COMP>_h_<HH>.zip
 *
 * Shape of the feed (verified 2026-08-21):
 *  - HH is the MODEL RUN hour, and the 24 files are a rolling window: h_08 is
 *    yesterday's 08z run until today's 08z one replaces it. A run appears about
 *    HH+1:38 UTC, so we ask for the run ~1¾ h old and walk backwards. Never
 *    trust the file name for freshness — parseArea() re-checks the timestamps
 *    inside (same lesson as the CAP bulletin index in Alerts.php).
 *  - Each ZIP holds one JSON per area (14 KRAJ / 206 ORP), each with exactly one
 *    `type: "mapa"` record followed by `predpoved` hours up to a FIXED end time,
 *    so the horizon is 49–96 h depending on run. We publish HORIZON_HOURS of it,
 *    which every run covers.
 *  - "mapa" is NOT the first hour of the forecast — it is ČHMÚ's observation
 *    based map, a different product on a different scale. Praha PM10, 08z run
 *    of 2026-08-21: mapa 17.2, next hour's forecast 6.9. Splicing the two would
 *    draw a 60 % cliff that exists only in the file format, so parseArea() keeps
 *    them apart and only `points` is ever drawn as a line.
 *  - min/p_25/p_50/p_75/max are the SPATIAL spread inside the area, not model
 *    uncertainty: p_50 is the median over the whole region — rural included —
 *    and runs far below an urban station (same run: model 6.9 vs. 21.6 measured
 *    across the 14 Prague stations). The absolute level is therefore never shown
 *    against a sensor's own line; the SHAPE is, anchored on what that sensor
 *    actually measures. See anchorFactor()/applyAnchor().
 *
 * ZIPs are read by unzip() below rather than ext/zip: php8.3-zip is a separate
 * Debian package and this project has already been bitten once by a missing
 * php8.3-xml on the VPS. gzinflate() is core zlib and always there.
 */
final class Forecast
{
    public const BASE = 'https://opendata.chmi.cz/air_quality/now/forecast/';

    /** ČHMÚ component code => our value_type (SO2 is published but unused). */
    public const COMPONENTS = ['PM2_5' => 'PM2.5', 'PM10' => 'PM10', 'NO2' => 'NO2', 'O3' => 'O3'];

    /** Hours of forecast we keep. Every run covers at least 49 h. */
    public const HORIZON_HOURS = 48;

    /** Area level served to the map. ORP (206) needs a RUIAN↔CISORP crosswalk. */
    public const AREA_TYPE = 'KRAJ';

    /** A run older than this is treated as a dead feed. */
    public const MAX_RUN_AGE_SEC = 21600; // 6 h

    /**
     * CISORP prefix (kraj part of the warning-area code we already have in the
     * browser) => CZ-NUTS3 area code used by the forecast files.
     * Verified against all 96 active ČHMÚ stations (lat/lng → ORP ring → prefix
     * vs. the Region field in metadata.json): every prefix maps 1:1.
     */
    public const KRAJ_BY_CISORP_PREFIX = [
        11 => '19',  // Hlavní město Praha
        21 => '27',  // Středočeský
        31 => '35',  // Jihočeský
        32 => '43',  // Plzeňský
        41 => '51',  // Karlovarský
        42 => '60',  // Ústecký
        51 => '78',  // Liberecký
        52 => '86',  // Královéhradecký
        53 => '94',  // Pardubický
        61 => '108', // Vysočina
        62 => '116', // Jihomoravský
        71 => '124', // Olomoucký
        72 => '141', // Zlínský
        81 => '132', // Moravskoslezský
    ];

    /**
     * Anchoring (see anchorFactor). The clamp is wide because the gap it has to
     * cover is real — a 3× difference between the regional model level and an
     * urban station is normal, not an error — but not unlimited, so a spiking
     * sensor cannot rescale two days of forecast.
     */
    public const ANCHOR_HOURS = 3;
    public const ANCHOR_MIN = 0.33;
    public const ANCHOR_MAX = 3.0;

    public static function url(string $component, int $runHour, string $areaType = self::AREA_TYPE): string
    {
        return self::BASE . 'airquality_FC-boxplots_' . $areaType . '_' . $component
            . '_h_' . str_pad((string)$runHour, 2, '0', STR_PAD_LEFT) . '.zip';
    }

    /**
     * Run hours to try, newest first. A run is published ~1 h 38 min after its
     * nominal hour, so at 09:20 UTC the freshest complete run is 07z; asking for
     * the hour 1¾ h back lands on it without ever requesting a file that is
     * still being written.
     */
    public static function runCandidates(int $nowUtc, int $count = 3): array
    {
        $newest = (int)gmdate('G', $nowUtc - 6300);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ($newest - $i + 24) % 24;
        }
        return $out;
    }

    /**
     * Run hour => dated label ("2026-08-21T07"). The file name carries only the
     * hour, so this is what lets the cache tell today's 07z run from yesterday's
     * and skip the download when nothing new has been published.
     */
    public static function runLabel(int $runHour, int $nowUtc): string
    {
        $back = ((int)gmdate('G', $nowUtc) - $runHour + 24) % 24;
        return gmdate('Y-m-d\TH', $nowUtc - $back * 3600);
    }

    /**
     * Minimal ZIP reader: name => uncompressed bytes.
     * Handles the two methods that occur in practice (0 stored, 8 deflate) and
     * bails out on ZIP64 rather than guessing. Returns null when the body is not
     * a ZIP at all — ČHMÚ error pages come back as HTTP 200, like FIRMS CSV.
     */
    public static function unzip(string $bin): ?array
    {
        $len = strlen($bin);
        if ($len < 22 || substr($bin, 0, 2) !== 'PK') {
            return null;
        }
        // End of central directory: scan backwards over the comment field.
        $eocd = -1;
        $from = max(0, $len - 65557);
        for ($i = $len - 22; $i >= $from; $i--) {
            if (substr($bin, $i, 4) === "PK\x05\x06") {
                $eocd = $i;
                break;
            }
        }
        if ($eocd < 0) {
            return null;
        }
        $end = unpack('vdisk/vcdDisk/vcdHere/vcdTotal/VcdSize/VcdOffset/vcomment', substr($bin, $eocd + 4, 18));
        if ($end === false || $end['cdTotal'] === 0xFFFF || $end['cdOffset'] === 0xFFFFFFFF) {
            return null; // empty, malformed, or ZIP64 — not what ČHMÚ publishes
        }

        $out = [];
        $p = $end['cdOffset'];
        for ($n = 0; $n < $end['cdTotal']; $n++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") {
                return null;
            }
            $h = unpack('vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset', substr($bin, $p + 4, 42));
            if ($h === false) {
                return null;
            }
            $name = substr($bin, $p + 46, $h['nameLen']);
            $p += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

            $lo = $h['localOffset'];
            if (substr($bin, $lo, 4) !== "PK\x03\x04") {
                return null;
            }
            $lh = unpack('vnameLen/vextraLen', substr($bin, $lo + 26, 4));
            if ($lh === false) {
                return null;
            }
            $data = substr($bin, $lo + 30 + $lh['nameLen'] + $lh['extraLen'], $h['csize']);
            if ($h['method'] === 8) {
                $data = @gzinflate($data);
            } elseif ($h['method'] !== 0) {
                return null; // bzip2/lzma — never seen here, do not guess
            }
            if ($data === false || strlen($data) !== $h['usize'] || crc32($data) !== $h['crc']) {
                return null;
            }
            $out[$name] = $data;
        }
        return $out === [] ? null : $out;
    }

    /**
     * One area JSON => ['area', 'name', 'type', 'value_type', 'points' => [ts => [p25,p50,p75]]].
     *
     * Two rejections matter here. `datumVytvoreni` older than MAX_RUN_AGE_SEC
     * means the rolling window is still serving YESTERDAY's run under this file
     * name — it would look perfectly usable (its horizon still reaches into the
     * future), just a day out of date. And points outside
     * [current hour − 1 h, +HORIZON] are dropped so the caller never draws the
     * past or a horizon we do not publish.
     */
    public static function parseArea(string $json, int $nowUtc): ?array
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || !isset($doc['data']['metadata'], $doc['data']['values'])) {
            return null;
        }
        $created = strtotime((string)($doc['datumVytvoreni'] ?? ''));
        if ($created === false || $nowUtc - $created > self::MAX_RUN_AGE_SEC) {
            return null;
        }
        $meta = $doc['data']['metadata'];
        $comp = (string)($meta['ComponentCode'] ?? '');
        if (!isset(self::COMPONENTS[$comp])) {
            return null;
        }
        $code = trim((string)($meta['AreaCode'] ?? ''));
        if ($code === '' || !preg_match('/^\d{1,4}\z/', $code)) {
            return null;
        }

        $first = intdiv($nowUtc, 3600) * 3600 - 3600;
        $last  = $first + (self::HORIZON_HOURS + 1) * 3600;

        $points = [];
        $obs = null;
        foreach ($doc['data']['values'] as $v) {
            if (!is_array($v) || !isset($v['dt_beg_utc'], $v['p_50'])) {
                continue;
            }
            $ts = strtotime((string)$v['dt_beg_utc']);
            if ($ts === false) {
                continue;
            }
            $box = [
                round((float)$v['p_25'], 1),
                round((float)$v['p_50'], 1),
                round((float)$v['p_75'], 1),
            ];
            // The observation map is kept aside — a different product, never a
            // forecast hour. It is deliberately NOT held to the window below:
            // it belongs to the run's analysis hour, which by publication time
            // is already ~2 h old, and it is used only as a reference LEVEL for
            // rescaling, never plotted. Age-gating it here killed the whole
            // chmi_map anchoring tier in production; the run-age check above
            // already bounds how stale it can be.
            if (($v['type'] ?? '') === 'mapa') {
                $obs = ['t' => $ts, 'p25' => $box[0], 'p50' => $box[1], 'p75' => $box[2]];
                continue;
            }
            if ($ts < $first || $ts > $last) {
                continue;
            }
            $points[$ts] = $box;
        }
        if (count($points) < 12) {
            return null; // stale rolling-window file or a truncated run
        }
        ksort($points);

        return [
            'area'       => $code,
            'name'       => trim((string)($meta['AreaName'] ?? $code)),
            'type'       => (string)($meta['AreaType'] ?? self::AREA_TYPE),
            'value_type' => self::COMPONENTS[$comp],
            'created'    => $created,
            'obs'        => $obs,
            'points'     => $points,
        ];
    }

    /**
     * Parsed per-area results (any mix of components) => one record per area,
     * each series stored columnar from a common t0 so the cached files stay
     * small: {"PM10": {"t0":…, "step":3600, "p25":[…], "p50":[…], "p75":[…]}}.
     * Hours missing from a series become null and break the client's line.
     */
    public static function merge(array $parsed): array
    {
        $areas = [];
        foreach ($parsed as $a) {
            if ($a === null) {
                continue;
            }
            $code = $a['area'];
            $areas[$code]['name'] = $a['name'];
            $areas[$code]['type'] = $a['type'];
            $areas[$code]['series'][$a['value_type']] = self::columnar($a['points']) + ['obs' => $a['obs']];
        }
        ksort($areas, SORT_NATURAL);
        return $areas;
    }

    /** [ts => [p25,p50,p75]] => columnar arrays on a gapless hourly grid. */
    private static function columnar(array $points): array
    {
        $times = array_keys($points);
        $t0 = (int)min($times);
        $tn = (int)max($times);
        $n = intdiv($tn - $t0, 3600) + 1;

        $p25 = $p50 = $p75 = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $points[$t0 + $i * 3600] ?? null;
            $p25[] = $p[0] ?? null;
            $p50[] = $p[1] ?? null;
            $p75[] = $p[2] ?? null;
        }
        return ['t0' => $t0, 'step' => 3600, 'p25' => $p25, 'p50' => $p50, 'p75' => $p75];
    }

    /**
     * Mean of the first hours of a p50 series — the model's own "level right
     * now", used as the denominator when anchoring. Averaging a few hours
     * rather than taking one keeps a single odd hour from setting the scale.
     */
    public static function modelBase(array $p50, int $hours = self::ANCHOR_HOURS): ?float
    {
        $vals = [];
        foreach ($p50 as $v) {
            if ($v === null) {
                continue;
            }
            $vals[] = (float)$v;
            if (count($vals) >= $hours) {
                break;
            }
        }
        return $vals === [] ? null : array_sum($vals) / count($vals);
    }

    /**
     * Anchoring factor: what the sensor measures ÷ what the model says for the
     * same place and time. Applied to the whole curve it turns a regional level
     * — which sits well below any urban sensor — into that sensor's own level,
     * keeping only the shape of the forecast.
     *
     * $observed must be a SMOOTHED reading (a few hours), never an instant one:
     * a passing lorry would otherwise rescale the next two days. The clamp is
     * the second line of defence, and 1.0 (no anchoring) is returned whenever
     * the arithmetic would be meaningless.
     */
    public static function anchorFactor(?float $observed, ?float $modelBase): float
    {
        if ($observed === null || $modelBase === null || $modelBase < 1.0 || $observed <= 0.0) {
            return 1.0;
        }
        return max(self::ANCHOR_MIN, min(self::ANCHOR_MAX, round($observed / $modelBase, 3)));
    }

    /** Scale a columnar series by an anchoring factor, nulls preserved. */
    public static function applyAnchor(array $series, float $factor): array
    {
        if ($factor === 1.0) {
            return $series;
        }
        foreach (['p25', 'p50', 'p75'] as $key) {
            if (!isset($series[$key])) {
                continue;
            }
            $series[$key] = array_map(
                static fn($v) => $v === null ? null : round((float)$v * $factor, 1),
                $series[$key]
            );
        }
        return $series;
    }

    /** CISORP warning-area code (browser side) => forecast KRAJ area code. */
    public static function krajForCisorp(int $cisorp): ?string
    {
        return self::KRAJ_BY_CISORP_PREFIX[intdiv($cisorp, 100)] ?? null;
    }
}
