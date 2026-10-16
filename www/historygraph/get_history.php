<?php
// /www/historygraph/get_history.php
// Historie jedné látky pro graf v popupu (posledních 5 dní).
// 2.8: ?type= (PM2.5 výchozí | PM10 | NO2 | O3 | noise), detekce výpadků dat
// (null bod s příznakem "gap"), popisky v čase Europe/Prague, staleness i pro Libčich.
// 3.0: ?type=temperature | humidity — hodinový slot bere PRŮMĚR (ne maximum),
// heuristiky ucpání se na ně nepouštějí (konstantní noční teplota není porucha).
// 3.1: ID CHMI_M_<WSI> = meteostanice ČHMÚ (10min hodnoty, publikované hodinově);
// jako ostatní CHMI_ bez heuristik, staleness práh 3 h.

header('Content-Type: application/json');

$db_file = __DIR__ . '/air_quality_history.db';
$retention_days = 5;

// 1. Validace vstupu
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'No sensor ID provided']);
    exit;
}

$sensor_id = $_GET['id'];

// SC_12345, CHMI_ABCDE1, CHMI_M_0-20000-0-11519 (3.1 meteostanice ČHMÚ, WSI s pomlčkami),
// SCK_12345, PA_12345, AG_12345, LIBCICH_<hex devEid>
if (!preg_match('/^(SC_\d+|CHMI_M_[0-9][0-9-]{3,40}|CHMI_[A-Z0-9_]+|SCK_\d+|PA_\d+|AG_\d+|LIBCICH_[A-F0-9]{8,32})\z/', $sensor_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid sensor ID format']);
    exit;
}

$value_type = $_GET['type'] ?? 'PM2.5';
if (!in_array($value_type, ['PM2.5', 'PM10', 'NO2', 'O3', 'noise', 'temperature', 'humidity'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid value type']);
    exit;
}

// Volitelný hodinový režim (?hours=24&step=hour): pevné hodinové sloty, aby graf
// mohl vedle historie položit předpověď na stejné časové mřížce (osa x je
// kategorická — 5min body vedle hodinových by budoucnost roztáhly 12×). Slot
// bere MAX hodiny, ne průměr: krátká špička nesmí zprůměrováním zmizet.
$max_hours = $retention_days * 24;
$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : $max_hours;
if ($hours < 1 || $hours > $max_hours) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid hours range']);
    exit;
}
$hourly = ($_GET['step'] ?? '') === 'hour';
// 3.0: u teploty/vlhkosti je hodinové maximum zkreslené (polední špička čidla
// v krytu), slot proto bere průměr; u látek zůstává maximum (špička nesmí zmizet).
$is_meteo = in_array($value_type, ['temperature', 'humidity'], true);
$slot_agg = $is_meteo ? 'AVG' : 'MAX';

// 2. Databáze
try {
    if (!file_exists($db_file)) {
        throw new Exception("Database not found. (Collector hasn't run yet?)");
    }

    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Data (časově ohraničené — nespoléhat jen na retenci kolektoru)
    $window_start = time() - ($hours * 3600);
    if ($hourly) {
        $slot0 = intdiv(time(), 3600) * 3600 - ($hours - 1) * 3600;
        $stmt = $db->prepare("
            SELECT (measured_at / 3600) * 3600 AS slot, $slot_agg(value) AS value
            FROM measurements
            WHERE sensor_id = ? AND value_type = ? AND measured_at >= ?
            GROUP BY slot ORDER BY slot ASC
        ");
        $stmt->execute([$sensor_id, $value_type, $slot0]);
        $by_slot = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $by_slot[(int)$row['slot']] = round((float)$row['value'], 1);
        }
        $rows = [];
        for ($i = 0; $i < $hours; $i++) {
            $slot = $slot0 + $i * 3600;
            $rows[] = ['measured_at' => $slot, 'value' => $by_slot[$slot] ?? null];
        }
    } else {
        $stmt = $db->prepare("
            SELECT measured_at, value
            FROM measurements
            WHERE sensor_id = ? AND value_type = ? AND measured_at >= ?
            ORDER BY measured_at ASC
        ");
        $stmt->execute([$sensor_id, $value_type, $window_start]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Popisky v českém čase bez ohledu na zónu serveru
    $tz = new DateTimeZone('Europe/Prague');
    $fmt = function (int $ts, string $format) use ($tz): string {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($format);
    };

    // Kadence vzorkování (medián rozestupu): ČHMÚ/Libčich hodinově, komunitní ~5 min.
    $deltas = [];
    for ($i = 1, $c = count($rows); $i < $c; $i++) {
        $deltas[] = (int)$rows[$i]['measured_at'] - (int)$rows[$i - 1]['measured_at'];
    }
    $cadence = 0;
    if ($deltas) {
        sort($deltas);
        $cadence = $deltas[intdiv(count($deltas), 2)];
    }
    // Výpadek = chybí ≥1 hodinový vzorek (ČHMÚ), nebo ≥30 min 5min vzorků.
    $gap_threshold = max(2 * $cadence, 1800);

    // 4. Formát pro Chart.js
    $labels = [];
    $data = [];
    $prev_ts = null;
    foreach ($rows as $row) {
        $ts = (int)$row['measured_at'];
        if (!$hourly && $prev_ts !== null && $cadence > 0 && ($ts - $prev_ts) >= $gap_threshold) {
            // Jeden null bod na výpadek: přeruší čáru a nese rozsah pro tooltip ("gap")
            $labels[] = $fmt($prev_ts, 'D H:i') . ' – ' . $fmt($ts, 'D H:i')
                . '|' . $fmt($prev_ts, 'D') . '|gap';
            $data[] = null;
        }
        $labels[] = $fmt($ts, 'H:i|D'); // "14:30|Mon"
        // Prázdný hodinový slot zůstává null — (float)null by nakreslilo nulu
        $data[] = $row['value'] === null ? null : (float)$row['value'];
        $prev_ts = $ts;
    }

    // 5. Kvalita: plateau/stuck z posledních 2 hodin
    $cutoff_2h = time() - 7200;
    $qstmt = $db->prepare("
        SELECT value FROM measurements
        WHERE sensor_id = ? AND value_type = ? AND measured_at >= ?
        ORDER BY measured_at DESC
    ");
    $qstmt->execute([$sensor_id, $value_type, $cutoff_2h]);
    $recent = array_map('floatval', array_column($qstmt->fetchAll(PDO::FETCH_ASSOC), 'value'));
    $n = count($recent);

    $quality = ['plateau_high' => false, 'stuck' => false, 'stale' => false, 'latest_age_sec' => null, 'mean_2h' => null, 'std_dev_2h' => null, 'samples_2h' => $n];
    $is_chmi = str_starts_with($sensor_id, 'CHMI_');
    $is_hourly = $is_chmi || str_starts_with($sensor_id, 'LIBCICH_');

    // Heuristiky ucpání jsou µg/m³ pro optické prachové senzory — ne pro dB(A)
    // ani referenční analyzátory.
    if (!$is_chmi && $value_type !== 'noise' && !$is_meteo && $n >= 6) {
        $mean = array_sum($recent) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $recent)) / $n;
        $std_dev = sqrt($variance);
        $quality['mean_2h'] = round($mean, 2);
        $quality['std_dev_2h'] = round($std_dev, 2);
        if ($std_dev < 3.0 && $mean > 60 && $n >= 10) $quality['plateau_high'] = true;
        if ($std_dev < 0.5 && $mean > 10) $quality['stuck'] = true;
    }

    // Staleness: stáří nejnovějšího bodu. Hodinové zdroje (ČHMÚ, Libčich) mají
    // zdravý feed mladší než ~2 h; práh 3 h toleruje zpoždění a chytí zamrzlý zdroj.
    // Bere se z celé DB, ne z vráceného okna: zdroj stojící déle než 24 h by
    // v hodinovém režimu (samé prázdné sloty) jinak vyšel jako bez stáří, a popup
    // by varoval jen v pohledu „5 dní" — kvalita musí být v obou pohledech stejná.
    $nstmt = $db->prepare("SELECT MAX(measured_at) FROM measurements WHERE sensor_id = ? AND value_type = ?");
    $nstmt->execute([$sensor_id, $value_type]);
    $newest = $nstmt->fetchColumn();
    $newest = ($newest === null || $newest === false) ? null : (int)$newest;
    if ($newest !== null) {
        $age = time() - $newest;
        $quality['latest_age_sec'] = $age;
        if ($is_hourly && $age > 10800) $quality['stale'] = true;
    }

    // 6. JSON
    echo json_encode([
        'sensor_id' => $sensor_id,
        'type' => $value_type,
        'labels' => $labels,
        'data' => $data,
        'step' => $hourly ? 3600 : null,
        't0' => ($hourly && $rows) ? (int)$rows[0]['measured_at'] : null,
        'bucket' => $hourly ? ($is_meteo ? 'avg' : 'max') : null,
        'quality' => $quality
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log('get_history.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Historie momentálně není dostupná.']);
}
?>
