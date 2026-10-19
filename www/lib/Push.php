<?php
// lib/Push.php — odběry push upozornění a jejich vyhodnocení (3.6)
//
// Uživatel si v mapě (karta „Moje senzory") nastaví pravidlo: senzor + látka + práh + počet hodin,
// volitelně smogové výstrahy ČHMÚ pro ORP, v němž senzor leží. Prohlížeč pošle PushSubscription
// (endpoint + klíče), tady se uloží do SQLite `historygraph/push.db` (MIMO git; .htaccess musí
// *.db zakazovat — viz DEPLOY_3.6.md). Vyhodnocení běží v `Push::run()`:
//   - práh: hodinové PRŮMĚRY z historygraph/air_quality_history.db (stejná DB jako graf v popupu);
//     posledních N hodinových slotů (včetně právě běžící hodiny) musí mít průměr ≥ práh → jedna
//     zpráva na epizodu (`active = 1`); jakmile poslední slot klesne pod práh → zpráva „klesl"
//     a `active = 0`; mezi dvěma zprávami o překročení nejméně RENOTIFY_SEC;
//   - smog: platné výstrahy kategorie `smog` z cache/alerts_latest.json, jejichž seznam ORP
//     obsahuje ORP odběru; každá výstraha (id) nejvýš jednou (`last_alert_id`).
// Spouští se z push-api.php?action=tick (volá mapa po každém načtení dat, provede se nejvýš
// jednou za RUN_EVERY s flock) nebo ?token=CRON_TOKEN (volitelný cron). Bez nového cronu.
// Odeslání: lib/WebPush.php. Zaniklé odběry (404/410) se mažou. Offline test: tests/test_push.php.

require_once __DIR__ . '/WebPush.php';

final class Push
{
    public static string $dbFile = __DIR__ . '/../historygraph/push.db';
    public static string $historyDb = __DIR__ . '/../historygraph/air_quality_history.db';
    public static string $keysFile = __DIR__ . '/../push-vapid.php';
    public static string $alertsCache = __DIR__ . '/../cache/alerts_latest.json';
    public static string $lockFile = __DIR__ . '/../historygraph/push.lock';

    public const SUBJECT = 'mailto:info@senzorvzduchu.cz';
    public const MAX_PER_ENDPOINT = 5;
    public const RUN_EVERY = 300;          // s — tick z mapy běží nejvýš takto často
    public const RENOTIFY_SEC = 6 * 3600;  // s — nejkratší odstup dvou zpráv o překročení
    public const MAX_FAILS = 8;            // po tolika síťových chybách za sebou odběr smazat
    public const TEST_EVERY = 60;          // s — zkušební zpráva nejvýš jednou za minutu na endpoint

    /** Látky, pro které jde pravidlo nastavit (apiType jako v get_history.php) → popisek, pásma EAQI 2024, klíč v app.js. */
    public const POLLUTANTS = [
        'PM2.5' => ['label' => 'PM2.5', 'unit' => 'µg/m³', 'key' => 'pm25', 'bands' => [5, 15, 50, 90, 140]],
        'PM10'  => ['label' => 'PM10',  'unit' => 'µg/m³', 'key' => 'pm10', 'bands' => [15, 45, 120, 195, 270]],
        'NO2'   => ['label' => 'NO₂',   'unit' => 'µg/m³', 'key' => 'no2',  'bands' => [10, 25, 60, 100, 150]],
        'O3'    => ['label' => 'O₃',    'unit' => 'µg/m³', 'key' => 'o3',   'bands' => [60, 100, 120, 160, 180]],
    ];
    public const BAND_LABELS = ['dobrá', 'uspokojivá', 'střední', 'špatná', 'velmi špatná', 'extrémně špatná'];

    /** Jen známé push služby — cizí URL by z našeho serveru jen zbytečně dostávaly požadavky. */
    public const ENDPOINT_HOSTS = [
        'fcm.googleapis.com', 'android.googleapis.com',
        'updates.push.services.mozilla.com', 'push.services.mozilla.com',
        'web.push.apple.com', 'notify.windows.com', 'push.mozilla.com',
    ];

    // --- DB ------------------------------------------------------------------------------------
    private static ?PDO $db = null;

    public static function db(): PDO
    {
        if (self::$db) return self::$db;
        $db = new PDO('sqlite:' . self::$dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            sensor_id TEXT NOT NULL,
            sensor_name TEXT,
            pollutant TEXT NOT NULL,
            threshold REAL NOT NULL,
            hours INTEGER NOT NULL DEFAULT 2,
            smog INTEGER NOT NULL DEFAULT 0,
            orp INTEGER,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 0,
            last_notified_at INTEGER NOT NULL DEFAULT 0,
            last_alert_id TEXT,
            fail_count INTEGER NOT NULL DEFAULT 0,
            UNIQUE(endpoint, sensor_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sub_endpoint ON subscriptions (endpoint)');
        $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
        self::$db = $db;
        return $db;
    }

    /** Pro testy: zavřít spojení, aby se dalo přepnout na jinou DB. */
    public static function reset(): void
    {
        self::$db = null;
    }

    private static function metaGet(string $k): ?string
    {
        $st = self::db()->prepare('SELECT v FROM meta WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    private static function metaSet(string $k, string $v): void
    {
        self::db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
    }

    // --- VAPID klíče ------------------------------------------------------------------------------
    /**
     * ['public' => b64url 65 B, 'private' => b64url 32 B, 'subject' => …].
     * Přednost mají VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY z config.php; jinak soubor push-vapid.php
     * (PHP soubor vracející pole — při přímém otevření z webu nic nevypíše), vytvořený při prvním použití.
     * Klíč se NESMÍ měnit: odběry v prohlížečích jsou na něj vázané (jiný klíč = všechny zanikly).
     */
    public static function vapid(): array
    {
        $subject = defined('VAPID_SUBJECT') ? VAPID_SUBJECT : self::SUBJECT;
        if (defined('VAPID_PUBLIC_KEY') && defined('VAPID_PRIVATE_KEY') && VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '') {
            return ['public' => VAPID_PUBLIC_KEY, 'private' => VAPID_PRIVATE_KEY, 'subject' => $subject];
        }
        if (file_exists(self::$keysFile)) {
            $k = include self::$keysFile;
            if (is_array($k) && !empty($k['public']) && !empty($k['private'])) {
                return ['public' => $k['public'], 'private' => $k['private'], 'subject' => $subject];
            }
        }
        $pair = WebPush::generateKeyPair();
        $pub = WebPush::b64u($pair['public']);
        $priv = WebPush::b64u($pair['private']);
        $php = "<?php\n// VAPID klíče pro web push (3.6) — vytvořeno automaticky " . gmdate('c') . ".\n"
            . "// NEMAZAT a NEMĚNIT: odběry v prohlížečích jsou vázané na veřejný klíč. Mimo git.\n"
            . "return ['public' => " . var_export($pub, true) . ", 'private' => " . var_export($priv, true) . "];\n";
        $tmp = self::$keysFile . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, self::$keysFile)) {
            @unlink($tmp);
            throw new RuntimeException('cannot write VAPID keys file');
        }
        return ['public' => $pub, 'private' => $priv, 'subject' => $subject];
    }

    // --- Odběry ------------------------------------------------------------------------------------
    public static function validSensorId(string $id): bool
    {
        return (bool)preg_match('/^(SC_\d+|CHMI_M_[0-9][0-9-]{3,40}|CHMI_[A-Z0-9_]+|SCK_\d+|PA_\d+|AG_\d+|LIBCICH_[A-F0-9]{8,32})\z/', $id);
    }

    public static function validEndpoint(string $endpoint): bool
    {
        $u = parse_url($endpoint);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || strlen($endpoint) > 1024) return false;
        $host = strtolower($u['host']);
        foreach (self::ENDPOINT_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) return true;
        }
        return false;
    }

    /**
     * Vstup z prohlížeče: {endpoint, keys:{p256dh, auth}, sensorId, sensorName, pollutant, threshold, hours, smog, orp}.
     * Vrací normalizované pole, nebo řetězec s chybou.
     */
    public static function normalize(array $in): array|string
    {
        $endpoint = (string)($in['endpoint'] ?? '');
        if (!self::validEndpoint($endpoint)) return 'bad endpoint';
        $p256dh = (string)($in['keys']['p256dh'] ?? '');
        $auth = (string)($in['keys']['auth'] ?? '');
        if (strlen(WebPush::b64uDecode($p256dh)) !== 65 || strlen(WebPush::b64uDecode($auth)) !== 16) return 'bad keys';
        $sensorId = (string)($in['sensorId'] ?? '');
        if (!self::validSensorId($sensorId)) return 'bad sensorId';
        $pollutant = (string)($in['pollutant'] ?? 'PM2.5');
        if (!isset(self::POLLUTANTS[$pollutant])) return 'bad pollutant';
        $threshold = (float)($in['threshold'] ?? 0);
        if (!is_finite($threshold) || $threshold < 1 || $threshold > 1000) return 'bad threshold';
        $hours = (int)($in['hours'] ?? 2);
        if ($hours < 1 || $hours > 6) return 'bad hours';
        $smog = !empty($in['smog']) ? 1 : 0;
        $orp = isset($in['orp']) && is_numeric($in['orp']) ? (int)$in['orp'] : null;
        if ($smog && ($orp === null || $orp < 1000 || $orp > 9999)) return 'bad orp';
        $name = mb_substr(trim((string)($in['sensorName'] ?? '')), 0, 80);
        return [
            'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
            'sensor_id' => $sensorId, 'sensor_name' => $name !== '' ? $name : $sensorId,
            'pollutant' => $pollutant, 'threshold' => round($threshold, 1), 'hours' => $hours,
            'smog' => $smog, 'orp' => $orp,
        ];
    }

    /** Uloží/přepíše pravidlo pro (endpoint, senzor). Vrací ['ok'=>true,'rules'=>…] nebo ['error'=>…]. */
    public static function subscribe(array $in, int $now = 0): array
    {
        $now = $now ?: time();
        $s = self::normalize($in);
        if (is_string($s)) return ['error' => $s];
        $db = self::db();
        $cnt = $db->prepare('SELECT COUNT(*) FROM subscriptions WHERE endpoint = ? AND sensor_id <> ?');
        $cnt->execute([$s['endpoint'], $s['sensor_id']]);
        if ((int)$cnt->fetchColumn() >= self::MAX_PER_ENDPOINT) return ['error' => 'too many rules'];
        // Nové pravidlo začíná „neaktivní": když je práh překročen už teď, přijde zpráva při dalším běhu.
        $db->prepare('INSERT INTO subscriptions (endpoint, p256dh, auth, sensor_id, sensor_name, pollutant, threshold, hours, smog, orp, created_at, updated_at)
            VALUES (:endpoint, :p256dh, :auth, :sensor_id, :sensor_name, :pollutant, :threshold, :hours, :smog, :orp, :now, :now)
            ON CONFLICT(endpoint, sensor_id) DO UPDATE SET
                p256dh = excluded.p256dh, auth = excluded.auth, sensor_name = excluded.sensor_name,
                pollutant = excluded.pollutant, threshold = excluded.threshold, hours = excluded.hours,
                smog = excluded.smog, orp = excluded.orp, updated_at = excluded.updated_at,
                active = 0, fail_count = 0')
            ->execute($s + ['now' => $now]);
        return ['ok' => true, 'rules' => self::rulesFor($s['endpoint'])];
    }

    /** Smaže pravidlo pro senzor, nebo všechna pravidla endpointu. Vrací počet smazaných. */
    public static function unsubscribe(string $endpoint, ?string $sensorId = null): int
    {
        $db = self::db();
        if ($sensorId !== null && $sensorId !== '') {
            $st = $db->prepare('DELETE FROM subscriptions WHERE endpoint = ? AND sensor_id = ?');
            $st->execute([$endpoint, $sensorId]);
        } else {
            $st = $db->prepare('DELETE FROM subscriptions WHERE endpoint = ?');
            $st->execute([$endpoint]);
        }
        return $st->rowCount();
    }

    /** Pravidla endpointu pro synchronizaci stavu v mapě (bez klíčů). */
    public static function rulesFor(string $endpoint): array
    {
        $st = self::db()->prepare('SELECT sensor_id, sensor_name, pollutant, threshold, hours, smog, orp, active, last_notified_at
            FROM subscriptions WHERE endpoint = ? ORDER BY created_at');
        $st->execute([$endpoint]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'sensorId' => $r['sensor_id'], 'sensorName' => $r['sensor_name'], 'pollutant' => $r['pollutant'],
                'threshold' => (float)$r['threshold'], 'hours' => (int)$r['hours'], 'smog' => (bool)$r['smog'],
                'orp' => $r['orp'] === null ? null : (int)$r['orp'], 'active' => (bool)$r['active'],
                'lastNotifiedAt' => (int)$r['last_notified_at'],
            ];
        }
        return $out;
    }

    // --- Vyhodnocení ------------------------------------------------------------------------------
    public static function bandLabel(string $pollutant, float $v): string
    {
        $bands = self::POLLUTANTS[$pollutant]['bands'] ?? [];
        foreach ($bands as $i => $b) {
            if ($v <= $b) return self::BAND_LABELS[$i];
        }
        return self::BAND_LABELS[count($bands)];
    }

    /**
     * Hodinové průměry posledních $hours slotů (včetně běžící hodiny).
     * @return ?array ['exceeded' => bool, 'avg' => float, 'latest' => float, 'slots' => int] — null bez dat
     */
    public static function evaluate(PDO $hist, string $sensorId, string $pollutant, float $threshold, int $hours, int $now): ?array
    {
        $slot0 = intdiv($now, 3600) * 3600 - ($hours - 1) * 3600;
        $st = $hist->prepare('SELECT (measured_at / 3600) * 3600 AS slot, AVG(value) AS v FROM measurements
            WHERE sensor_id = ? AND value_type = ? AND measured_at >= ? AND measured_at <= ? GROUP BY slot ORDER BY slot');
        $st->execute([$sensorId, $pollutant, $slot0, $now]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $vals = array_map(fn($r) => (float)$r['v'], $rows);
        $latest = end($vals);
        return [
            'exceeded' => count($vals) >= $hours && min($vals) >= $threshold,
            'avg' => array_sum($vals) / count($vals),
            'latest' => $latest,
            'slots' => count($vals),
        ];
    }

    /** Platné smogové výstrahy pro ORP (z cache alerts-proxy), nejvyšší úroveň první. */
    public static function smogAlertsFor(int $orp, array $cache, int $now): array
    {
        $out = [];
        foreach ($cache['alerts'] ?? [] as $a) {
            if (($a['cat'] ?? '') !== 'smog') continue;
            if (!in_array($orp, $a['orp'] ?? [], true) && !in_array((string)$orp, $a['orp'] ?? [], true)) continue;
            $exp = isset($a['expires']) ? strtotime((string)$a['expires']) : false;
            if ($exp !== false && $exp < $now) continue;
            $out[] = $a;
        }
        usort($out, fn($x, $y) => ($y['level'] ?? 0) <=> ($x['level'] ?? 0));
        return $out;
    }

    private static function fmt(float $v): string
    {
        return str_replace('.', ',', (string)round($v, $v < 10 ? 1 : 0));
    }

    /** Text zprávy o překročení. */
    public static function exceedMessage(array $sub, array $ev): array
    {
        $p = self::POLLUTANTS[$sub['pollutant']];
        $h = (int)$sub['hours'];
        $hodin = $h === 1 ? 'hodinu' : ($h < 5 ? 'hodiny' : 'hodin');
        return [
            'title' => $p['label'] . ' nad ' . self::fmt((float)$sub['threshold']) . ' ' . $p['unit'] . ' · ' . $sub['sensor_name'],
            'body' => 'Poslední ' . ($h === 1 ? '' : $h . ' ') . $hodin . ' průměrně ' . self::fmt($ev['avg']) . ' ' . $p['unit']
                . ' (pásmo ' . self::bandLabel($sub['pollutant'], $ev['avg']) . '). Omezte větrání, dokud hodnota neklesne.',
            'tag' => 'sv-' . $sub['id'],
            'url' => '/map/?sensor=' . rawurlencode($sub['sensor_id']) . '&pollutant=' . $p['key'] . '&utm_source=push',
        ];
    }

    public static function clearMessage(array $sub, array $ev): array
    {
        $p = self::POLLUTANTS[$sub['pollutant']];
        return [
            'title' => $p['label'] . ' klesl pod ' . self::fmt((float)$sub['threshold']) . ' ' . $p['unit'] . ' · ' . $sub['sensor_name'],
            'body' => 'Poslední hodina průměrně ' . self::fmt($ev['latest']) . ' ' . $p['unit']
                . ' (pásmo ' . self::bandLabel($sub['pollutant'], $ev['latest']) . '). Můžete větrat.',
            'tag' => 'sv-' . $sub['id'],
            'url' => '/map/?sensor=' . rawurlencode($sub['sensor_id']) . '&pollutant=' . $p['key'] . '&utm_source=push',
        ];
    }

    public static function smogMessage(array $sub, array $alert): array
    {
        $onset = isset($alert['onset']) ? strtotime((string)$alert['onset']) : false;
        $exp = isset($alert['expires']) ? strtotime((string)$alert['expires']) : false;
        $fmt = static function ($ts): string {
            $d = new DateTime('@' . $ts);
            $d->setTimezone(new DateTimeZone('Europe/Prague'));
            return $d->format('j. n. H:i');
        };
        $when = '';
        if ($onset !== false) $when .= ' od ' . $fmt($onset);
        if ($exp !== false) $when .= ' do ' . $fmt($exp);
        return [
            'title' => 'ČHMÚ: ' . ($alert['event'] ?? 'smogová situace'),
            'body' => 'Platí pro oblast senzoru ' . $sub['sensor_name'] . $when . '. Omezte pobyt venku a větrání.',
            'tag' => 'sv-smog-' . ($alert['id'] ?? 'x'),
            'url' => '/map/?sensor=' . rawurlencode($sub['sensor_id']) . '&utm_source=push',
        ];
    }

    /**
     * Jeden průchod všemi odběry. $sender(subscriptionRow, payloadArray) → ['code' => int, 'error' => string];
     * bez něj se posílá přes WebPush::send(). Vrací statistiku.
     */
    public static function run(bool $force = false, ?int $now = null, ?callable $sender = null, ?PDO $hist = null): array
    {
        $now = $now ?? time();
        $db = self::db();
        $last = (int)(self::metaGet('last_run') ?? 0);
        if (!$force && $now - $last < self::RUN_EVERY) return ['skipped' => true, 'next_in' => self::RUN_EVERY - ($now - $last)];
        $lock = Http::tryLock(self::$lockFile);
        if ($lock === null) return ['skipped' => true, 'locked' => true];
        self::metaSet('last_run', (string)$now);

        $stats = ['checked' => 0, 'sent' => 0, 'cleared' => 0, 'smog' => 0, 'deleted' => 0, 'errors' => 0];
        try {
            if ($hist === null && file_exists(self::$historyDb)) {
                $hist = new PDO('sqlite:' . self::$historyDb);
                $hist->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            $alerts = Http::readJson(self::$alertsCache) ?? [];
            $vapid = null;
            $send = function (array $sub, array $msg, string $urgency = 'normal') use (&$vapid, $sender, $db, &$stats): bool {
                $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($sender) {
                    $r = $sender($sub, $msg);
                } else {
                    $vapid = $vapid ?? self::vapid();
                    try {
                        $r = WebPush::send($sub, $payload, $vapid, 6 * 3600, $urgency);
                    } catch (Throwable $e) {
                        $r = ['code' => 0, 'error' => $e->getMessage()];
                    }
                }
                $code = (int)$r['code'];
                if ($code === 404 || $code === 410) {
                    $db->prepare('DELETE FROM subscriptions WHERE endpoint = ?')->execute([$sub['endpoint']]);
                    $stats['deleted']++;
                    return false;
                }
                if ($code < 200 || $code >= 300) {
                    $stats['errors']++;
                    $db->prepare('UPDATE subscriptions SET fail_count = fail_count + 1 WHERE id = ?')->execute([$sub['id']]);
                    if ((int)$sub['fail_count'] + 1 >= self::MAX_FAILS) {
                        $db->prepare('DELETE FROM subscriptions WHERE id = ?')->execute([$sub['id']]);
                        $stats['deleted']++;
                    }
                    return false;
                }
                if ((int)$sub['fail_count'] > 0) $db->prepare('UPDATE subscriptions SET fail_count = 0 WHERE id = ?')->execute([$sub['id']]);
                return true;
            };

            foreach ($db->query('SELECT * FROM subscriptions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $sub) {
                $stats['checked']++;
                // 1. práh
                if ($hist) {
                    $ev = self::evaluate($hist, $sub['sensor_id'], $sub['pollutant'], (float)$sub['threshold'], (int)$sub['hours'], $now);
                    if ($ev) {
                        if ($ev['exceeded'] && !(int)$sub['active']) {
                            if ($now - (int)$sub['last_notified_at'] >= self::RENOTIFY_SEC) {
                                if ($send($sub, self::exceedMessage($sub, $ev), 'high')) {
                                    $db->prepare('UPDATE subscriptions SET active = 1, last_notified_at = ? WHERE id = ?')->execute([$now, $sub['id']]);
                                    $stats['sent']++;
                                }
                            } else {
                                // Ještě běží odstup od minulé zprávy: epizodu označit, ať se neposílá hned po jeho uplynutí
                                $db->prepare('UPDATE subscriptions SET active = 1 WHERE id = ?')->execute([$sub['id']]);
                            }
                        } elseif ((int)$sub['active'] && $ev['latest'] < (float)$sub['threshold']) {
                            if ($send($sub, self::clearMessage($sub, $ev), 'low')) $stats['cleared']++;
                            $db->prepare('UPDATE subscriptions SET active = 0 WHERE id = ?')->execute([$sub['id']]);
                        }
                    }
                }
                // 2. smog
                if ((int)$sub['smog'] && $sub['orp'] !== null) {
                    $list = self::smogAlertsFor((int)$sub['orp'], $alerts, $now);
                    if ($list && ($list[0]['id'] ?? '') !== (string)$sub['last_alert_id']) {
                        if ($send($sub, self::smogMessage($sub, $list[0]), 'high')) $stats['smog']++;
                        $db->prepare('UPDATE subscriptions SET last_alert_id = ? WHERE id = ?')->execute([$list[0]['id'] ?? '', $sub['id']]);
                    }
                }
            }
        } finally {
            Http::unlock($lock);
        }
        return $stats;
    }

    /** Zkušební zpráva na endpoint (nejvýš jednou za TEST_EVERY). */
    public static function sendTest(string $endpoint, int $now = 0, ?callable $sender = null): array
    {
        $now = $now ?: time();
        $db = self::db();
        $st = $db->prepare('SELECT * FROM subscriptions WHERE endpoint = ? ORDER BY updated_at DESC LIMIT 1');
        $st->execute([$endpoint]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sub) return ['error' => 'no subscription'];
        $key = 'test:' . substr(sha1($endpoint), 0, 16);
        $lastTest = (int)(self::metaGet($key) ?? 0);
        if ($now - $lastTest < self::TEST_EVERY) return ['error' => 'rate limited'];
        self::metaSet($key, (string)$now);
        $msg = [
            'title' => 'Upozornění fungují',
            'body' => 'Senzor ' . $sub['sensor_name'] . ': ozveme se, když ' . self::POLLUTANTS[$sub['pollutant']]['label']
                . ' překročí ' . self::fmt((float)$sub['threshold']) . ' ' . self::POLLUTANTS[$sub['pollutant']]['unit']
                . ' po dobu ' . $sub['hours'] . ' h' . ((int)$sub['smog'] ? ' nebo ČHMÚ vyhlásí smogovou situaci' : '') . '.',
            'tag' => 'sv-test',
            'url' => '/map/?sensor=' . rawurlencode($sub['sensor_id']) . '&utm_source=push',
        ];
        if ($sender) return $sender($sub, $msg);
        try {
            return WebPush::send($sub, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::vapid(), 300, 'high');
        } catch (Throwable $e) {
            return ['code' => 0, 'error' => $e->getMessage()];
        }
    }
}
