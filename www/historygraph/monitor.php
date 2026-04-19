<?php
// ==========================================
// DATABASE WRITE MONITOR — senzorvzduchu.cz
// ==========================================
// Checks if collect.php is writing data to the DB.
// Sends push notification via ntfy.sh if no new data for 20+ minutes.
// CRON: */15 * * * * curl -s "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"
// ==========================================

require_once __DIR__ . '/../config.php';

// Security check
if (!isset($_GET['token']) || $_GET['token'] !== CRON_TOKEN) {
    die("Access Denied");
}

// Configuration
$db_file = __DIR__ . '/air_quality_history.db';
$cooldown_file = __DIR__ . '/monitor_last_alert.txt';
$ntfy_topic = 'senzorvzduchu-monitor';  // Subscribe at https://ntfy.sh/senzorvzduchu-monitor
$stale_threshold = 1200;   // 20 minutes — 4 missed collection cycles
$cooldown_seconds = 7200;  // 2 hours between alerts

// 1. Check cooldown — don't spam
if (file_exists($cooldown_file)) {
    $last_alert = (int)file_get_contents($cooldown_file);
    if (time() - $last_alert < $cooldown_seconds) {
        echo "OK: In cooldown period, skipping check.";
        exit;
    }
}

// 2. Check database freshness
$problem = null;
$age = null;

if (!file_exists($db_file)) {
    $problem = "Database file not found!";
} else {
    try {
        $db = new PDO("sqlite:" . $db_file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $db->query("SELECT MAX(measured_at) as latest FROM measurements")->fetch();
        $latest = (int)($row['latest'] ?? 0);
        $age = time() - $latest;

        if ($age > $stale_threshold) {
            $mins = round($age / 60);
            $problem = "No new data for {$mins} minutes (last write: " . date('Y-m-d H:i:s', $latest) . ")";
        }
    } catch (Exception $e) {
        $problem = "Database error: " . $e->getMessage();
    }
}

// 3. Send alert or report OK
if ($problem !== null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://ntfy.sh/{$ntfy_topic}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $problem);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Title: Senzorvzduchu.cz — data collection stopped",
        "Priority: high",
        "Tags: warning"
    ]);

    $response = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    file_put_contents($cooldown_file, (string)time());

    echo $ok ? "ALERT SENT: {$problem}" : "ALERT FAILED: {$problem}";
} else {
    $mins_ago = $age !== null ? round($age / 60) : '?';
    echo "OK: Data is fresh (last write {$mins_ago} min ago)";
}
?>
