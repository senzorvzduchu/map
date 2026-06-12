<?php
// SANDBOX TEST — replika libcich bloku z collect.php proti lokální proxy.
// Spouštět z CLI: php -d curl.cainfo="..." tests/test_libcich_collect.php
// NIKDY nenahrávat na produkci.

$proxy_url = "http://127.0.0.1:8123/libcich-proxy.php?mode=aqi";
$db_file = __DIR__ . '/../www/historygraph/air_quality_history.db';

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $r = curl_exec($ch);
    if ($r === false) echo "FETCH ERROR: " . curl_error($ch) . "\n";
    return $r;
}

$db = new PDO("sqlite:" . $db_file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$before = (int)$db->query("SELECT COUNT(*) FROM measurements WHERE sensor_id LIKE 'LIBCICH_%'")->fetchColumn();

// ===== přesná replika bloku 5 z collect.php =====
$stats = ['inserted' => 0];
$lib_json = fetchUrl($proxy_url);
if ($lib_json) {
    $libSensors = json_decode($lib_json, true);
    if (is_array($libSensors)) {
        $db->beginTransaction();
        $stmt  = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");
        $check = $db->prepare("SELECT 1 FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at = ? LIMIT 1");

        foreach ($libSensors as $sensor) {
            if (!isset($sensor['pm25']) || $sensor['pm25'] === null) continue;
            if (empty($sensor['measured_at']) || empty($sensor['id'])) continue;

            $pm25 = floatval($sensor['pm25']);
            if ($pm25 < 0 || $pm25 > 500) continue;

            $sensor_id = "LIBCICH_" . $sensor['id'];
            $ts = intval($sensor['measured_at']);

            $check->execute([$sensor_id, 'PM2.5', $ts]);
            if (!$check->fetch()) {
                $stmt->execute([$sensor_id, $ts, 'PM2.5', $pm25]);
                $stats['inserted']++;
            }

            if (isset($sensor['pm10']) && $sensor['pm10'] !== null) {
                $pm10 = floatval($sensor['pm10']);
                if ($pm10 >= 0 && $pm10 <= 1000) {
                    $check->execute([$sensor_id, 'PM10', $ts]);
                    if (!$check->fetch()) {
                        $stmt->execute([$sensor_id, $ts, 'PM10', $pm10]);
                        $stats['inserted']++;
                    }
                }
            }
        }
        $db->commit();
    } else {
        echo "FAIL: proxy nevrátila validní JSON\n";
        exit(1);
    }
} else {
    echo "FAIL: proxy nedostupná\n";
    exit(1);
}
// ===== konec repliky =====

$after = (int)$db->query("SELECT COUNT(*) FROM measurements WHERE sensor_id LIKE 'LIBCICH_%'")->fetchColumn();
$sample = $db->query("SELECT sensor_id, measured_at, value_type, value FROM measurements WHERE sensor_id LIKE 'LIBCICH_%' ORDER BY measured_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

echo "LIBCICH rows before: $before, inserted: {$stats['inserted']}, after: $after\n";
foreach ($sample as $row) {
    echo "  {$row['sensor_id']} @ " . gmdate('Y-m-d H:i\Z', $row['measured_at']) . " {$row['value_type']}={$row['value']}\n";
}
?>
