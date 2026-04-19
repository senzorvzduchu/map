<?php
// /www/historygraph/get_history.php

header('Content-Type: application/json');

// Configuration
$db_file = __DIR__ . '/air_quality_history.db';

// 1. Validate Input
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'No sensor ID provided']);
    exit;
}

$sensor_id = $_GET['id'];

// Validate format: SC_12345, CHMI_ABCDE1, or SCK_12345 (prevents unexpected input from being echoed back)
if (!preg_match('/^(SC_\d+|CHMI_[A-Z0-9_]+|SCK_\d+|PA_\d+)$/', $sensor_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid sensor ID format']);
    exit;
}

// 2. Connect to Database
try {
    if (!file_exists($db_file)) {
        throw new Exception("Database not found. (Collector hasn't run yet?)");
    }
    
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Fetch Data (Last 5 days, ordered by time)
    // We only select PM2.5 for the main graph
    $stmt = $db->prepare("
        SELECT measured_at, value 
        FROM measurements 
        WHERE sensor_id = ? 
        AND value_type = 'PM2.5' 
        ORDER BY measured_at ASC
    ");
    
    $stmt->execute([$sensor_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Format Data for Chart.js
    $labels = [];
    $data = [];

    foreach ($rows as $row) {
        // Format time as "Mon 14:00" (Day + Hour:Minute)
        // You can change format here: 'd.m. H:i' for "13.01. 14:00"
        $labels[] = date('H:i|D', $row['measured_at']); // "14:30|Mon" — HH:mm | English day abbrev
        $data[] = (float)$row['value'];
    }

    // 5. Quality check: plateau/stuck detection from last 2 hours
    $cutoff_2h = time() - 7200;
    $qstmt = $db->prepare("
        SELECT value FROM measurements
        WHERE sensor_id = ? AND value_type = 'PM2.5' AND measured_at >= ?
        ORDER BY measured_at DESC
    ");
    $qstmt->execute([$sensor_id, $cutoff_2h]);
    $recent = array_column($qstmt->fetchAll(PDO::FETCH_ASSOC), 'value');
    $recent = array_map('floatval', $recent);
    $n = count($recent);

    $quality = ['plateau_high' => false, 'stuck' => false, 'mean_2h' => null, 'std_dev_2h' => null, 'samples_2h' => $n];
    $is_chmi = str_starts_with($sensor_id, 'CHMI_');
    if (!$is_chmi && $n >= 6) {
        $mean = array_sum($recent) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $recent)) / $n;
        $std_dev = sqrt($variance);
        $quality['mean_2h'] = round($mean, 2);
        $quality['std_dev_2h'] = round($std_dev, 2);
        if ($std_dev < 3.0 && $mean > 60 && $n >= 10) $quality['plateau_high'] = true;
        if ($std_dev < 0.5 && $mean > 10) $quality['stuck'] = true;
    }

    // 6. Return JSON
    echo json_encode([
        'sensor_id' => $sensor_id,
        'labels' => $labels,
        'data' => $data,
        'quality' => $quality
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>