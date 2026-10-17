<?php
declare(strict_types=1);

/**
 * Community-sensor bans and automatic quality flags.
 * Ported from nosicheck.cz src/SensorQuality.php; the ban table lives in the
 * same SQLite file as the history (historygraph/air_quality_history.db).
 *
 * Auto flags — the flatness principle the popup warning already uses, applied
 * server-side over the collected history so a broken sensor is HIDDEN from the
 * map and the interpolation, not just annotated in its chart:
 *   stuck:   2h std-dev < 0.5 at mean > 10 µg/m³  (frozen reading)
 *   plateau: 2h std-dev < 3.0 at mean > 60 µg/m³  (clogged optical sensor)
 * Needs ≥ 12 samples in 2 h (community sensors report 24×), so hourly sources
 * (ČHMÚ, Libčich) are never auto-flagged. Flags clear themselves as soon as the
 * sensor varies again. Extremes (>500 µg/m³, neighbour-median outliers) are
 * rejected at ingest and render time and need no flag here.
 *
 * 3.3 adds a third self-healing reason computed from the 3.0 temperature /
 * humidity history against the 3.1 ČHMÚ meteorological stations:
 *   indoor:  at night (00:00–05:00 Europe/Prague, no solar heating of the
 *            enclosure) the sensor is much warmer and much drier than the
 *            nearest ČHMÚ station within 30 km AND its temperature barely moves
 *            over 24 h — heated room air, not outdoor air. Thresholds:
 *            median(T_sensor − T_chmi) > 6 °C, median(RH_chmi − RH_sensor) > 15
 *            points, 24 h temperature range < 4 °C, ≥ 6 paired night samples.
 *            Found on AG_61860 "Location 2" (Poděbrady): 27.5 °C / 29 % next to
 *            AG_78554 at 13.5 °C / 59 % on the same coordinates.
 * ČHMÚ ids (CHMI_, CHMI_M_) are never flagged. The collector keeps storing a
 * flagged sensor (unlike a manual ban): the flag needs tomorrow's night samples
 * to re-evaluate itself, otherwise it would clear and re-arm every day.
 */
final class SensorQuality
{
    public const WINDOW_SEC = 7200;
    public const MIN_SAMPLES = 12;

    public const STUCK_STD = 0.5;
    public const STUCK_MEAN = 10.0;
    public const PLATEAU_STD = 3.0;
    public const PLATEAU_MEAN = 60.0;

    // 3.3 indoor heuristic
    public const INDOOR_WINDOW_SEC = 86400;   // history looked at
    public const INDOOR_NIGHT_FROM = 0;       // local hour, inclusive
    public const INDOOR_NIGHT_TO = 5;         // local hour, exclusive
    public const INDOOR_MAX_KM = 30.0;        // nearest ČHMÚ station no farther
    public const INDOOR_DT = 6.0;             // median(T_sensor − T_chmi) > this
    public const INDOOR_DRH = 15.0;           // median(RH_chmi − RH_sensor) > this
    public const INDOOR_RANGE = 4.0;          // 24 h max−min of sensor T < this
    public const INDOOR_MIN_SAMPLES = 6;      // paired night samples
    public const INDOOR_PAIR_SLOTS = 1;       // ČHMÚ value may sit ±N hour slots away
    public const TZ = 'Europe/Prague';

    /** Formerly hardcoded faulty Sensor.Community units — seeded on first use. */
    private const SEED_BANS = [
        ['SC_46875', 'Dlouhodobě nesmyslné hodnoty (původně natvrdo v kódu)'],
        ['SC_7213',  'Dlouhodobě nesmyslné hodnoty (původně natvrdo v kódu)'],
    ];

    public static function db(): PDO
    {
        $db = new PDO('sqlite:' . __DIR__ . '/../historygraph/air_quality_history.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    /** Create the ban table on first use and seed the historical hardcoded bans. */
    public static function ensureTable(PDO $db): void
    {
        $existed = (bool)$db->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='sensor_bans'"
        )->fetchColumn();
        if ($existed) {
            return;
        }
        $db->exec('CREATE TABLE sensor_bans (
            sensor_id  TEXT PRIMARY KEY,
            reason     TEXT NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL
        )');
        $ins = $db->prepare('INSERT OR IGNORE INTO sensor_bans (sensor_id, reason, created_at) VALUES (?, ?, ?)');
        foreach (self::SEED_BANS as [$id, $reason]) {
            $ins->execute([$id, $reason, time()]);
        }
    }

    /** Manually banned sensor ids. */
    public static function manualBans(PDO $db): array
    {
        self::ensureTable($db);
        return $db->query('SELECT sensor_id FROM sensor_bans ORDER BY sensor_id')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Manual bans with reason/date (admin list). */
    public static function manualBansDetailed(PDO $db): array
    {
        self::ensureTable($db);
        return $db->query('SELECT sensor_id, reason, created_at FROM sensor_bans ORDER BY created_at DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Normalise an admin-typed id: bare number ⇒ SC_. Community networks only —
     * ČHMÚ reference stations (CHMI_, CHMI_M_) cannot be banned. Returns null when invalid.
     * 3.2: AG_ (AirGradient, since 2.9) was missing here, so AirGradient units could not be
     * banned from the admin UI although collect.php and app.js already honour AG_ bans.
     */
    public static function normalizeId(string $raw): ?string
    {
        $id = trim($raw);
        if (preg_match('/^\d+$/', $id)) {
            $id = 'SC_' . $id;
        }
        return preg_match('/^(SC|SCK|PA|LIBCICH|AG)_[A-Za-z0-9_-]{1,40}$/', $id) ? $id : null;
    }

    /**
     * All automatic flags: PM flatness (stuck/plateau) + indoor (3.3).
     * Returns [{sensor_id, reason, value_type, mean, std, n, detail}, …], one per sensor.
     * $refs / $coords: see indoorFlags(); null = load from the cache files on disk.
     * The indoor pass is best-effort — any failure there must not hide the PM flags.
     */
    public static function autoFlags(PDO $db, ?int $now = null, ?array $refs = null, ?array $coords = null): array
    {
        $flags = self::flatnessFlags($db, $now);
        try {
            $refs = $refs ?? self::referenceStations();
            $coords = $coords ?? self::communityCoords();
            foreach (self::indoorFlags($db, $refs, $coords, $now) as $f) {
                if (!isset($flags[$f['sensor_id']])) {
                    $flags[$f['sensor_id']] = $f;
                }
            }
        } catch (Throwable $e) {
            error_log('SensorQuality indoor: ' . $e->getMessage());
        }
        return array_values($flags);
    }

    /**
     * Community sensors whose recent µg/m³ history looks broken.
     * Returns [sensor_id => {sensor_id, reason: 'stuck'|'plateau', value_type, mean, std, n, detail}].
     */
    public static function flatnessFlags(PDO $db, ?int $now = null): array
    {
        $since = ($now ?? time()) - self::WINDOW_SEC;
        $stmt = $db->prepare(
            "SELECT sensor_id, value_type,
                    COUNT(*)                                   AS n,
                    AVG(value)                                 AS mean,
                    AVG(value * value) - AVG(value)*AVG(value) AS variance
             FROM measurements
             WHERE measured_at >= ?
               AND value_type IN ('PM2.5', 'PM10')
               AND sensor_id NOT LIKE 'CHMI\\_%' ESCAPE '\\'
             GROUP BY sensor_id, value_type
             HAVING n >= " . self::MIN_SAMPLES
        );
        $stmt->execute([$since]);

        $flags = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mean = (float)$row['mean'];
            $std = sqrt(max(0.0, (float)$row['variance']));
            $reason = null;
            if ($std < self::STUCK_STD && $mean > self::STUCK_MEAN) {
                $reason = 'stuck';
            } elseif ($std < self::PLATEAU_STD && $mean > self::PLATEAU_MEAN) {
                $reason = 'plateau';
            }
            if ($reason === null) {
                continue;
            }
            $id = (string)$row['sensor_id'];
            if (!isset($flags[$id])) {
                $flags[$id] = [
                    'sensor_id'  => $id,
                    'reason'     => $reason,
                    'value_type' => (string)$row['value_type'],
                    'mean'       => round($mean, 1),
                    'std'        => round($std, 2),
                    'n'          => (int)$row['n'],
                    'detail'     => sprintf('průměr %.1f µg/m³, odchylka %.2f', $mean, $std),
                ];
            }
        }
        return $flags;
    }

    // ------------------------------------------------------------------
    // 3.3 indoor detection
    // ------------------------------------------------------------------

    /**
     * ČHMÚ meteorological stations with coordinates, from the 3.1 proxy cache
     * (cache/chmi_meteo.json → stations[{wsi, name, lat, lng}]). The cache is written
     * by chmi-meteo-proxy.php and is at most a day old whenever the map runs.
     * Returns [{id: 'CHMI_M_<wsi>', name, lat, lng}, …]; [] when the cache is missing.
     */
    public static function referenceStations(?string $file = null): array
    {
        $file = $file ?? __DIR__ . '/../cache/chmi_meteo.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string)file_get_contents($file), true);
        $out = [];
        foreach ($json['stations'] ?? [] as $s) {
            if (empty($s['wsi']) || !isset($s['lat'], $s['lng'])) {
                continue;
            }
            $out[] = [
                'id'   => 'CHMI_M_' . $s['wsi'],
                'name' => (string)($s['name'] ?? $s['wsi']),
                'lat'  => (float)$s['lat'],
                'lng'  => (float)$s['lng'],
            ];
        }
        return $out;
    }

    /**
     * Coordinates of community sensors keyed by history id, read from the proxy
     * caches the map already keeps (no extra API calls, no DB column):
     *   SC_<sensor.id>   sc_data_cache.json      (array of records, location.latitude/longitude)
     *   SCK_<id>         sck_data_cache.json     (array, latitude/longitude)
     *   PA_<id>          pa_data_cache.json      (array, latitude/longitude)
     *   LIBCICH_<id>     libcich_data_cache.json (array, latitude/longitude)
     *   AG_<id>          cache/airgradient.json  ({sensors:[…]}, latitude/longitude)
     * A sensor missing from every cache (offline right now) simply cannot be paired
     * with a station and is not flagged — fail open.
     * Returns [id => ['lat' => float, 'lng' => float]].
     */
    public static function communityCoords(?string $wwwDir = null): array
    {
        $www = $wwwDir ?? dirname(__DIR__);
        $coords = [];
        $load = static function (string $path): array {
            if (!is_file($path)) {
                return [];
            }
            $j = json_decode((string)file_get_contents($path), true);
            return is_array($j) ? $j : [];
        };

        foreach ($load($www . '/sc_data_cache.json') as $r) {
            $id = $r['sensor']['id'] ?? null;
            $lat = $r['location']['latitude'] ?? null;
            $lng = $r['location']['longitude'] ?? null;
            if ($id !== null && is_numeric($lat) && is_numeric($lng)) {
                $coords['SC_' . $id] = ['lat' => (float)$lat, 'lng' => (float)$lng];
            }
        }
        foreach (['SCK_' => '/sck_data_cache.json', 'PA_' => '/pa_data_cache.json', 'LIBCICH_' => '/libcich_data_cache.json'] as $prefix => $file) {
            foreach ($load($www . $file) as $r) {
                if (isset($r['id']) && is_numeric($r['latitude'] ?? null) && is_numeric($r['longitude'] ?? null)) {
                    $coords[$prefix . $r['id']] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude']];
                }
            }
        }
        $ag = $load($www . '/cache/airgradient.json');
        foreach ($ag['sensors'] ?? [] as $r) {
            if (isset($r['id']) && is_numeric($r['latitude'] ?? null) && is_numeric($r['longitude'] ?? null)) {
                $coords['AG_' . $r['id']] = ['lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude']];
            }
        }
        return $coords;
    }

    /** Great-circle distance in km. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Night windows [start, end) in unix time (00:00–05:00 Europe/Prague) that overlap
     * the last INDOOR_WINDOW_SEC before $now. DST-safe via DateTime. Usually two:
     * last night and, early in the day, the night before that (clipped to the window).
     */
    public static function nightWindows(int $now): array
    {
        $tz = new DateTimeZone(self::TZ);
        $since = $now - self::INDOOR_WINDOW_SEC;
        $out = [];
        for ($back = 0; $back <= 2; $back++) {
            $d = (new DateTime('@' . $now))->setTimezone($tz);
            if ($back > 0) {
                $d->modify("-$back day");
            }
            $start = (clone $d)->setTime(self::INDOOR_NIGHT_FROM, 0, 0)->getTimestamp();
            $end = (clone $d)->setTime(self::INDOOR_NIGHT_TO, 0, 0)->getTimestamp();
            $s = max($start, $since);
            $e = min($end, $now);
            if ($e > $s) {
                $out[] = [$s, $e];
            }
        }
        usort($out, fn($a, $b) => $a[0] <=> $b[0]);
        return $out;
    }

    /** Median of a non-empty float list. */
    public static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        $m = intdiv($n, 2);
        return $n % 2 ? (float)$v[$m] : ((float)$v[$m - 1] + (float)$v[$m]) / 2;
    }

    /**
     * Community sensors whose night-time T/RH history says "heated room".
     *
     * @param array $refs   [{id: 'CHMI_M_…', name, lat, lng}] — referenceStations()
     * @param array $coords [sensor_id => {lat, lng}]           — communityCoords()
     * Returns [{sensor_id, reason: 'indoor', value_type: 'temperature', mean: ΔT, std: ΔRH,
     *           n, range, ref, ref_km, detail}, …] (mean/std keep the PM flag field names
     *           so the admin table and sensor-bans.php need no special case).
     */
    public static function indoorFlags(PDO $db, array $refs, array $coords, ?int $now = null): array
    {
        $now = $now ?? time();
        if (!$refs || !$coords) {
            return [];
        }
        $windows = self::nightWindows($now);
        if (!$windows) {
            return [];
        }

        // Nearest station per community sensor (only sensors we have coordinates for).
        $nearest = [];
        foreach ($coords as $id => $c) {
            if (strpos((string)$id, 'CHMI_') === 0) {
                continue;
            }
            $best = null;
            foreach ($refs as $r) {
                $km = self::distanceKm((float)$c['lat'], (float)$c['lng'], (float)$r['lat'], (float)$r['lng']);
                if ($km <= self::INDOOR_MAX_KM && ($best === null || $km < $best['km'])) {
                    $best = ['id' => $r['id'], 'name' => $r['name'], 'km' => $km];
                }
            }
            if ($best !== null) {
                $nearest[(string)$id] = $best;
            }
        }
        if (!$nearest) {
            return [];
        }

        // SQL predicate for the night windows.
        $parts = [];
        $args = [];
        foreach ($windows as [$s, $e]) {
            $parts[] = '(measured_at >= ? AND measured_at < ?)';
            $args[] = $s;
            $args[] = $e;
        }
        $nightSql = '(' . implode(' OR ', $parts) . ')';

        // ČHMÚ night values per station per hour slot (one value per hour from the source).
        $ref = [];   // id → type → slot → value
        $stmt = $db->prepare(
            "SELECT sensor_id, value_type, measured_at, value FROM measurements
             WHERE sensor_id LIKE 'CHMI\\_M\\_%' ESCAPE '\\'
               AND value_type IN ('temperature', 'humidity') AND $nightSql"
        );
        $stmt->execute($args);
        $acc = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $slot = intdiv((int)$row['measured_at'], 3600);
            $acc[$row['sensor_id']][$row['value_type']][$slot][] = (float)$row['value'];
        }
        foreach ($acc as $id => $types) {
            foreach ($types as $type => $slots) {
                foreach ($slots as $slot => $vals) {
                    $ref[$id][$type][$slot] = array_sum($vals) / count($vals);
                }
            }
        }
        unset($acc);
        if (!$ref) {
            return [];
        }

        // 24 h temperature range per community sensor (cheap aggregate, no rows in PHP).
        $range = [];
        $stmt = $db->prepare(
            "SELECT sensor_id, MIN(value) AS lo, MAX(value) AS hi, COUNT(*) AS n FROM measurements
             WHERE measured_at >= ? AND value_type = 'temperature'
               AND sensor_id NOT LIKE 'CHMI\\_%' ESCAPE '\\'
             GROUP BY sensor_id"
        );
        $stmt->execute([$now - self::INDOOR_WINDOW_SEC]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $range[$row['sensor_id']] = (float)$row['hi'] - (float)$row['lo'];
        }

        // Community night rows, streamed sensor by sensor.
        $stmt = $db->prepare(
            "SELECT sensor_id, value_type, measured_at, value FROM measurements
             WHERE value_type IN ('temperature', 'humidity')
               AND sensor_id NOT LIKE 'CHMI\\_%' ESCAPE '\\' AND $nightSql
             ORDER BY sensor_id"
        );
        $stmt->execute($args);

        $flags = [];
        $cur = null;
        $rows = ['temperature' => [], 'humidity' => []];
        $flush = function () use (&$cur, &$rows, &$flags, $nearest, $ref, $range): void {
            if ($cur === null) {
                return;
            }
            $f = self::evaluateIndoor($cur, $rows, $nearest[$cur] ?? null, $ref, $range[$cur] ?? null);
            if ($f !== null) {
                $flags[] = $f;
            }
            $rows = ['temperature' => [], 'humidity' => []];
        };
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (string)$row['sensor_id'];
            if ($id !== $cur) {
                $flush();
                $cur = $id;
            }
            if (!isset($nearest[$id])) {
                continue; // no station within 30 km or no coordinates → never flagged
            }
            $rows[$row['value_type']][] = [(int)$row['measured_at'], (float)$row['value']];
        }
        $flush();
        return $flags;
    }

    /**
     * Decide one sensor. $rows = {temperature: [[ts, v]…], humidity: [[ts, v]…]} (night only),
     * $near = {id, name, km} nearest station, $ref = station → type → hour slot → value,
     * $range = 24 h max−min of the sensor's temperature (null = no temperature rows).
     */
    public static function evaluateIndoor(string $id, array $rows, ?array $near, array $ref, ?float $range): ?array
    {
        if ($near === null || $range === null || !isset($ref[$near['id']])) {
            return null;
        }
        $station = $ref[$near['id']];
        $pair = static function (array $samples, array $slots): array {
            $diffs = [];
            foreach ($samples as [$ts, $v]) {
                $slot = intdiv($ts, 3600);
                $r = null;
                for ($d = 0; $d <= self::INDOOR_PAIR_SLOTS && $r === null; $d++) {
                    if (isset($slots[$slot - $d])) {
                        $r = $slots[$slot - $d];
                    } elseif ($d > 0 && isset($slots[$slot + $d])) {
                        $r = $slots[$slot + $d];
                    }
                }
                if ($r !== null) {
                    $diffs[] = [$v, $r];
                }
            }
            return $diffs;
        };
        $tPairs = $pair($rows['temperature'] ?? [], $station['temperature'] ?? []);
        $hPairs = $pair($rows['humidity'] ?? [], $station['humidity'] ?? []);
        $n = min(count($tPairs), count($hPairs));
        if ($n < self::INDOOR_MIN_SAMPLES) {
            return null;
        }
        $dT = self::median(array_map(fn($p) => $p[0] - $p[1], $tPairs));    // sensor − ČHMÚ
        $dRH = self::median(array_map(fn($p) => $p[1] - $p[0], $hPairs));   // ČHMÚ − sensor
        if ($dT <= self::INDOOR_DT || $dRH <= self::INDOOR_DRH || $range >= self::INDOOR_RANGE) {
            return null;
        }
        return [
            'sensor_id'  => $id,
            'reason'     => 'indoor',
            'value_type' => 'temperature',
            'mean'       => round($dT, 1),
            'std'        => round($dRH, 1),
            'n'          => $n,
            'range'      => round($range, 1),
            'ref'        => $near['name'],
            'ref_km'     => round($near['km'], 1),
            'detail'     => sprintf(
                'v noci o %.1f °C tepleji a o %.0f b. sušeji než %s (%.0f km), rozkmit 24 h %.1f °C',
                $dT, $dRH, $near['name'], $near['km'], $range
            ),
        ];
    }
}
