<?php
// tests/test_push.php — offline test odběrů a vyhodnocení push upozornění (3.6)
//   php tests/test_push.php
// Vlastní dočasná push.db + syntetická historie; odesílání je nahrazené zachytávačem.

require_once __DIR__ . '/../www/lib/Http.php';
require_once __DIR__ . '/../www/lib/Push.php';

if (!defined('USER_AGENT')) define('USER_AGENT', 'test');

$tmp = sys_get_temp_dir() . '/svz-push-test-' . bin2hex(random_bytes(3));
mkdir($tmp);
Push::$dbFile = "$tmp/push.db";
Push::$historyDb = "$tmp/history.db";
Push::$keysFile = "$tmp/vapid.php";
Push::$alertsCache = "$tmp/alerts.json";
Push::$lockFile = "$tmp/push.lock";

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fail++;
}

// --- historie: SC_1 PM2.5 ------------------------------------------------------------------
$hist = new PDO('sqlite:' . Push::$historyDb);
$hist->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hist->exec('CREATE TABLE measurements (id INTEGER PRIMARY KEY, sensor_id TEXT, measured_at INTEGER, value_type TEXT, value REAL)');
$now = intdiv(time(), 3600) * 3600 + 1800; // půl hodiny do běžící hodiny
$ins = $hist->prepare('INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)');
function fill(PDO $hist, PDOStatement $ins, int $now, array $hourValues): void
{
    $hist->exec('DELETE FROM measurements');
    foreach ($hourValues as $hoursAgo => $v) {
        $slot = intdiv($now, 3600) * 3600 - $hoursAgo * 3600;
        for ($m = 0; $m < 30; $m += 5) $ins->execute(['SC_1', $slot + $m * 60, 'PM2.5', $v]);
    }
}
fill($hist, $ins, $now, [0 => 32, 1 => 30, 2 => 12]);

// --- odběr ----------------------------------------------------------------------------------
$sub = [
    'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
    'keys' => ['p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'auth' => 'BTBZMqHH6r4Tts7J_aSIgg'],
    'sensorId' => 'SC_1', 'sensorName' => 'Testovací senzor', 'pollutant' => 'PM2.5', 'threshold' => 25, 'hours' => 2,
    'smog' => true, 'orp' => 1100,
];
$r = Push::subscribe($sub, $now);
check('subscribe ok', !empty($r['ok']) && count($r['rules']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
check('reject foreign endpoint', isset(Push::subscribe(['endpoint' => 'https://evil.example/x'] + $sub, $now)['error']));
check('reject bad sensor', isset(Push::subscribe(['sensorId' => 'DROP TABLE'] + $sub, $now)['error']));
check('reject smog without orp', isset(Push::subscribe(['orp' => null] + $sub, $now)['error']));
check('vapid keys generated', strlen(WebPush::b64uDecode(Push::vapid()['public'])) === 65 && file_exists(Push::$keysFile));
check('vapid keys stable', Push::vapid()['public'] === Push::vapid()['public']);

// --- zachytávač ------------------------------------------------------------------------------
$sent = [];
$sender = function (array $row, array $msg) use (&$sent): array {
    $sent[] = $msg;
    return ['code' => 201, 'error' => ''];
};
file_put_contents(Push::$alertsCache, json_encode(['alerts' => []]));

// 1. překročení: sloty 0 h = 32, 1 h = 30 → oba ≥ 25 → zpráva
$st = Push::run(true, $now, $sender, $hist);
check('exceed → 1 sent', $st['sent'] === 1 && count($sent) === 1, json_encode($st));
check('exceed title', isset($sent[0]['title']) && str_contains($sent[0]['title'], 'PM2.5 nad 25 µg/m³') && str_contains($sent[0]['title'], 'Testovací senzor'), $sent[0]['title'] ?? '');
check('exceed body has avg 31 + band', str_contains($sent[0]['body'] ?? '', '31 µg/m³') && str_contains($sent[0]['body'] ?? '', 'střední'), $sent[0]['body'] ?? '');
check('exceed url deep link', ($sent[0]['url'] ?? '') === '/map/?sensor=SC_1&pollutant=pm25&utm_source=push');

// 2. stále překročeno → nic (active)
$st = Push::run(true, $now + 300, $sender, $hist);
check('still exceeded → nothing', $st['sent'] === 0 && count($sent) === 1, json_encode($st));

// 3. throttling: bez force do 5 min nic
$st = Push::run(false, $now + 400, $sender, $hist);
check('tick throttled', !empty($st['skipped']));

// 4. pokles: poslední hodina 10 → „klesl" + active 0
fill($hist, $ins, $now + 3600, [0 => 10, 1 => 32, 2 => 30]);
$st = Push::run(true, $now + 3600, $sender, $hist);
check('clear → 1 cleared', $st['cleared'] === 1 && count($sent) === 2, json_encode($st));
check('clear title', str_contains($sent[1]['title'] ?? '', 'klesl pod 25'), $sent[1]['title'] ?? '');
check('rule inactive after clear', Push::rulesFor($sub['endpoint'])[0]['active'] === false);

// 5. znovu překročeno hned → odstup 6 h blokuje zprávu, epizoda se jen označí
fill($hist, $ins, $now + 7200, [0 => 40, 1 => 40, 2 => 10]);
$st = Push::run(true, $now + 7200, $sender, $hist);
check('renotify blocked within 6 h', $st['sent'] === 0 && Push::rulesFor($sub['endpoint'])[0]['active'] === true, json_encode($st));

// 6. jen 1 slot s daty při hours=2 → nevyhodnocuje se jako překročení
Push::reset();
Push::subscribe(['sensorId' => 'SC_1'] + $sub, $now);
fill($hist, $ins, $now + 10 * 3600, [0 => 90]);
$st = Push::run(true, $now + 10 * 3600, $sender, $hist);
check('one slot of two → no alert', $st['sent'] === 0, json_encode($st));

// 7. smog: výstraha pro ORP 1100 → 1 zpráva, podruhé nic, jiné id → znovu
file_put_contents(Push::$alertsCache, json_encode(['alerts' => [[
    'id' => 'a1', 'event' => 'Smogová situace', 'cat' => 'smog', 'level' => 2,
    'onset' => gmdate('c', $now - 3600), 'expires' => gmdate('c', $now + 20 * 3600), 'orp' => [1100, 1101],
], [
    'id' => 'a2', 'event' => 'Smogová situace', 'cat' => 'smog', 'level' => 2,
    'onset' => gmdate('c', $now - 3600), 'expires' => gmdate('c', $now + 20 * 3600), 'orp' => [2100],
], [
    'id' => 'a3', 'event' => 'Vítr', 'cat' => 'wind', 'level' => 2, 'orp' => [1100],
]]]));
$n = count($sent);
$st = Push::run(true, $now + 11 * 3600, $sender, $hist);
check('smog → 1 sent', $st['smog'] === 1 && count($sent) === $n + 1, json_encode($st));
check('smog title/body', str_contains($sent[$n]['title'] ?? '', 'ČHMÚ: Smogová situace') && str_contains($sent[$n]['body'] ?? '', 'Testovací senzor'), $sent[$n]['title'] ?? '');
$st = Push::run(true, $now + 12 * 3600, $sender, $hist);
check('same smog alert → nothing', $st['smog'] === 0);

// 8. zaniklý endpoint → smazat
$gone = function (array $row, array $msg): array { return ['code' => 410, 'error' => 'gone']; };
file_put_contents(Push::$alertsCache, json_encode(['alerts' => [['id' => 'a9', 'event' => 'Smogová situace', 'cat' => 'smog', 'level' => 3, 'orp' => [1100]]]]));
$st = Push::run(true, $now + 13 * 3600, $gone, $hist);
check('410 → subscription deleted', $st['deleted'] === 1 && Push::rulesFor($sub['endpoint']) === [], json_encode($st));

// 9. zkušební zpráva + limit
Push::subscribe($sub, $now);
$t = Push::sendTest($sub['endpoint'], $now, $sender);
check('test message sent', ($t['code'] ?? 0) === 201 && str_contains(end($sent)['title'], 'fungují'));
$t = Push::sendTest($sub['endpoint'], $now + 10, $sender);
check('test rate limited', isset($t['error']) && $t['error'] === 'rate limited');

// 10. unsubscribe
check('unsubscribe', Push::unsubscribe($sub['endpoint']) === 1 && Push::rulesFor($sub['endpoint']) === []);

Push::reset();
foreach (glob("$tmp/*") as $f) @unlink($f);
@rmdir($tmp);
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
