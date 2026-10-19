<?php
// push-api.php — push upozornění (3.6): odběry z mapy + spouštění vyhodnocení
//
//   GET  ?action=vapid                → { publicKey }  (applicationServerKey pro pushManager.subscribe)
//   POST ?action=subscribe   JSON {endpoint, keys:{p256dh,auth}, sensorId, sensorName, pollutant, threshold, hours, smog, orp}
//                                     → { ok, rules:[…] }
//   POST ?action=unsubscribe JSON {endpoint, sensorId?}   → { ok, removed }
//   POST ?action=rules       JSON {endpoint}              → { rules:[…] }
//   POST ?action=test        JSON {endpoint}              → { ok } — zkušební zpráva hned (1× za minutu)
//   GET  ?action=tick                 → spustí Push::run(), nejvýš jednou za 5 min (volá mapa po načtení dat)
//   GET  ?action=tick&token=CRON_TOKEN → vynucený běh (volitelný cron)
// Logika a DB: lib/Push.php, šifrování a VAPID: lib/WebPush.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Push.php';

header("Access-Control-Allow-Origin: " . ALLOWED_DOMAIN);
header('Cache-Control: no-store');

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function pushBody(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

try {
    switch ($action) {
        case 'vapid':
            Http::jsonOut(['publicKey' => Push::vapid()['public']]);

        case 'subscribe':
            if ($method !== 'POST') Http::fail('POST only', 405);
            $r = Push::subscribe(pushBody());
            Http::jsonOut($r, isset($r['error']) ? 400 : 200);

        case 'unsubscribe':
            if ($method !== 'POST') Http::fail('POST only', 405);
            $b = pushBody();
            $endpoint = (string)($b['endpoint'] ?? '');
            if ($endpoint === '') Http::fail('bad endpoint');
            $sensor = isset($b['sensorId']) ? (string)$b['sensorId'] : null;
            Http::jsonOut(['ok' => true, 'removed' => Push::unsubscribe($endpoint, $sensor), 'rules' => Push::rulesFor($endpoint)]);

        case 'rules':
            if ($method !== 'POST') Http::fail('POST only', 405);
            $b = pushBody();
            $endpoint = (string)($b['endpoint'] ?? '');
            if ($endpoint === '') Http::fail('bad endpoint');
            Http::jsonOut(['rules' => Push::rulesFor($endpoint)]);

        case 'test':
            if ($method !== 'POST') Http::fail('POST only', 405);
            $b = pushBody();
            $r = Push::sendTest((string)($b['endpoint'] ?? ''));
            if (isset($r['error']) && !isset($r['code'])) Http::jsonOut($r, 400);
            $ok = (int)($r['code'] ?? 0) >= 200 && (int)($r['code'] ?? 0) < 300;
            Http::jsonOut(['ok' => $ok, 'code' => (int)($r['code'] ?? 0), 'error' => $ok ? '' : (string)($r['error'] ?? '')], $ok ? 200 : 502);

        case 'tick':
            $force = isset($_GET['token']) && hash_equals(CRON_TOKEN, (string)$_GET['token']);
            Http::jsonOut(Push::run($force));

        default:
            Http::fail('unknown action', 404);
    }
} catch (Throwable $e) {
    error_log('push-api: ' . $e->getMessage());
    Http::fail('server error', 500);
}
