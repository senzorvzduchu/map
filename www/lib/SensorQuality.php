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
 */
final class SensorQuality
{
    public const WINDOW_SEC = 7200;
    public const MIN_SAMPLES = 12;

    public const STUCK_STD = 0.5;
    public const STUCK_MEAN = 10.0;
    public const PLATEAU_STD = 3.0;
    public const PLATEAU_MEAN = 60.0;

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
     * ČHMÚ reference stations cannot be banned. Returns null when invalid.
     */
    public static function normalizeId(string $raw): ?string
    {
        $id = trim($raw);
        if (preg_match('/^\d+$/', $id)) {
            $id = 'SC_' . $id;
        }
        return preg_match('/^(SC|SCK|PA|LIBCICH)_[A-Za-z0-9_-]{1,40}$/', $id) ? $id : null;
    }

    /**
     * Community sensors whose recent µg/m³ history looks broken.
     * Returns [{sensor_id, reason: 'stuck'|'plateau', value_type, mean, std, n}].
     */
    public static function autoFlags(PDO $db, ?int $now = null): array
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
                ];
            }
        }
        return array_values($flags);
    }
}
