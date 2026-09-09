<?php
// admin/bans.php — ruční blokace komunitních senzorů (bez uživatelských účtů:
// chrání token). URL: /admin/bans.php?token=<ADMIN_TOKEN nebo CRON_TOKEN>
//
// - Blokovat lze jen SC_/SCK_/PA_/LIBCICH_ (ČHMÚ nikdy); holé číslo = SC_.
// - Blokovaný senzor zmizí z mapy i z interpolace (sensor-bans.php) a kolektor
//   jeho hodnoty neukládá. Automatické příznaky (stuck/plateau) jsou jen k nahlédnutí —
//   samy se zruší, jakmile senzor zase kolísá.
// - Ban/unban smaže cache/sensor_flags.json, aby se změna projevila hned.
//
// Pozn.: token v URL zůstane v historii prohlížeče správce. Doporučeno definovat
// v config.php samostatný ADMIN_TOKEN (jinak se použije CRON_TOKEN).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/SensorQuality.php';

$adminToken = defined('ADMIN_TOKEN') ? ADMIN_TOKEN : CRON_TOKEN;
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if (!is_string($token) || !hash_equals($adminToken, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Access Denied');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$db = SensorQuality::db();
SensorQuality::ensureTable($db);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = SensorQuality::normalizeId((string)($_POST['sensor_id'] ?? ''));
    if ($id === null) {
        $err = 'Neplatné ID senzoru. Povolené tvary: 46875, SC_46875, SCK_123, PA_123, LIBCICH_<hex>.';
    } elseif ($action === 'ban') {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
        $db->prepare('INSERT INTO sensor_bans (sensor_id, reason, created_at) VALUES (?, ?, ?)
                      ON CONFLICT(sensor_id) DO UPDATE SET reason = excluded.reason')
           ->execute([$id, $reason, time()]);
        $msg = "Senzor $id zablokován.";
    } elseif ($action === 'unban') {
        $db->prepare('DELETE FROM sensor_bans WHERE sensor_id = ?')->execute([$id]);
        $msg = "Senzor $id odblokován.";
    } else {
        $err = 'Neznámá akce.';
    }
    @unlink(__DIR__ . '/../cache/sensor_flags.json');
}

$manual = SensorQuality::manualBansDetailed($db);
$auto = SensorQuality::autoFlags($db);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tok = $h($token);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Blokace senzorů – senzorvzduchu.cz</title>
<style>
  body { font: 14px/1.5 Inter, system-ui, sans-serif; color: #111827; margin: 0; padding: 24px; background: #f9fafb; }
  h1 { font-size: 20px; color: #21275F; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 24px 0 8px; color: #21275F; }
  .box { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; max-width: 860px; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  th { color: #6b7280; font-weight: 600; }
  input[type=text] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
  button { padding: 6px 12px; border: 0; border-radius: 6px; background: #21275F; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  button.secondary { background: #e5e7eb; color: #111827; }
  .ok { color: #047857; } .err { color: #b91c1c; }
  .muted { color: #6b7280; font-size: 12px; }
  code { background: #f3f4f6; padding: 1px 4px; border-radius: 4px; }
</style>
</head>
<body>
<h1>Blokace komunitních senzorů</h1>
<p class="muted">Blokovaný senzor zmizí z mapy i z interpolace a kolektor jeho hodnoty neukládá. Projeví se do 10 min (cache), po ban/unban hned.</p>
<?php if ($msg): ?><p class="ok"><?= $h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>

<div class="box">
  <form method="post">
    <input type="hidden" name="token" value="<?= $tok ?>">
    <input type="hidden" name="action" value="ban">
    <label>ID senzoru <input type="text" name="sensor_id" placeholder="46875 / SC_46875 / SCK_123 / PA_123" required></label>
    <label>Důvod <input type="text" name="reason" placeholder="např. senzor v interiéru" size="36"></label>
    <button type="submit">Zablokovat</button>
  </form>
  <p class="muted">Holé číslo = Sensor.Community. Stanice ČHMÚ blokovat nelze.</p>
</div>

<h2>Ruční blokace (<?= count($manual) ?>)</h2>
<div class="box">
<?php if (!$manual): ?><p class="muted">Žádné.</p><?php else: ?>
  <table>
    <tr><th>Senzor</th><th>Důvod</th><th>Od</th><th></th></tr>
    <?php foreach ($manual as $r): ?>
    <tr>
      <td><code><?= $h($r['sensor_id']) ?></code></td>
      <td><?= $h($r['reason']) ?></td>
      <td><?= date('j. n. Y H:i', (int)$r['created_at']) ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="token" value="<?= $tok ?>">
          <input type="hidden" name="action" value="unban">
          <input type="hidden" name="sensor_id" value="<?= $h($r['sensor_id']) ?>">
          <button type="submit" class="secondary">Odblokovat</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>

<h2>Automatické příznaky (<?= count($auto) ?>)</h2>
<div class="box">
  <p class="muted">Poslední 2 h, ≥ 12 vzorků, jen PM: <em>stuck</em> = směrodatná odchylka &lt; 0,5 při průměru &gt; 10 µg/m³; <em>plateau</em> = odchylka &lt; 3 při průměru &gt; 60 µg/m³. Tyto senzory mapa skrývá automaticky; příznak zmizí, jakmile senzor zase kolísá. Trvalý problém → zablokovat ručně.</p>
<?php if (!$auto): ?><p class="muted">Žádné.</p><?php else: ?>
  <table>
    <tr><th>Senzor</th><th>Příznak</th><th>Látka</th><th>Průměr</th><th>Odchylka</th><th>n</th><th></th></tr>
    <?php foreach ($auto as $f): ?>
    <tr>
      <td><code><?= $h($f['sensor_id']) ?></code></td>
      <td><?= $h($f['reason']) ?></td>
      <td><?= $h($f['value_type']) ?></td>
      <td><?= $h($f['mean']) ?></td>
      <td><?= $h($f['std']) ?></td>
      <td><?= $h($f['n']) ?></td>
      <td>
        <form method="post" style="display:inline">
          <input type="hidden" name="token" value="<?= $tok ?>">
          <input type="hidden" name="action" value="ban">
          <input type="hidden" name="sensor_id" value="<?= $h($f['sensor_id']) ?>">
          <input type="hidden" name="reason" value="Auto-příznak <?= $h($f['reason']) ?>, potvrzeno správcem">
          <button type="submit" class="secondary">Zablokovat trvale</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>
</body>
</html>
