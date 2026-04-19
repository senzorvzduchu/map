<?php
// ==========================================
// CONFIGURATION
// ==========================================

require_once __DIR__ . '/../config.php';
$cron_token = CRON_TOKEN;

$url_sc_proxy   = "https://senzorvzduchu.cz/sc-proxy.php";
$url_chmi_meta  = "https://senzorvzduchu.cz/chmi-proxy.php?type=metadata";
$url_chmi_data  = "https://senzorvzduchu.cz/chmi-proxy.php?type=data";
$url_sck_proxy  = "https://senzorvzduchu.cz/sck-proxy.php";
$url_pa_proxy   = "https://senzorvzduchu.cz/pa-proxy.php";

$db_file = __DIR__ . '/air_quality_history.db';
$retention_days = 5;

// ==========================================
// ROBUST FETCH FUNCTION (cURL)
// ==========================================
function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        echo "<b>Chyba stahování z $url:</b> $error <br>";
    }
    return $response;
}

// ==========================================
// SECURITY CHECK
// ==========================================

if (!isset($_GET['token']) || $_GET['token'] !== $cron_token) {
    die("Access Denied: Invalid Token");
}

// ==========================================
// DATABASE SETUP (SQLite)
// ==========================================

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS measurements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sensor_id TEXT,
        measured_at INTEGER,
        value_type TEXT,
        value REAL
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_sensor_time ON measurements (sensor_id, measured_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sensor_type ON measurements (sensor_id, value_type, measured_at)");

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ==========================================
// 1. PROCESS SENSOR.COMMUNITY DATA
// ==========================================

$stats = ['inserted' => 0, 'errors' => 0];
$timestamp = time();

$sc_json = fetchUrl($url_sc_proxy);
if ($sc_json) {
    $data = json_decode($sc_json, true);
    if (is_array($data)) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");

        foreach ($data as $sensor) {
            if ($sensor['sensor']['id'] == 46875 || $sensor['sensor']['id'] == 7213) continue; // Excluded: faulty sensors with unreliable readings
            // Indoor filter
            if (isset($sensor['location']['indoor']) && $sensor['location']['indoor'] == 1) continue;

            $sensor_id = "SC_" . $sensor['sensor']['id'];
            
            foreach ($sensor['sensordatavalues'] as $val) {
                $type = $val['value_type'];
                if ($type === 'P2') $type = 'PM2.5';
                elseif ($type === 'P1') $type = 'PM10';
                
                if ($type === 'PM2.5') {
                    $pm25 = floatval($val['value']);
                    if ($pm25 <= 0 || $pm25 > 500) continue; // Implausible value — physically impossible outdoors in CZ
                    $stmt->execute([$sensor_id, $timestamp, $type, $pm25]);
                    $stats['inserted']++;
                }
            }
        }
        $db->commit();
    } else {
        echo "Warning: SC Proxy did not return valid JSON.\n";
    }
}

// ==========================================
// 2. PROCESS CHMI DATA
// ==========================================

$chmi_meta = fetchUrl($url_chmi_meta);
$chmi_csv  = fetchUrl($url_chmi_data);

if ($chmi_meta && $chmi_csv) {
    $metaJson = json_decode($chmi_meta, true);
    $registryMap = []; 

    if (isset($metaJson['data']['Localities'])) {
        foreach ($metaJson['data']['Localities'] as $loc) {
            if (!$loc['Active']) continue;
            $code = $loc['LocalityCode'];
            
            if (!empty($loc['MeasuringPrograms'])) {
                foreach ($loc['MeasuringPrograms'] as $prog) {
                    if (!empty($prog['Measurements'])) {
                        foreach ($prog['Measurements'] as $m) {
                            $regId = $m['IdRegistration'];
                            $comp = $m['ComponentCode'];
                            if ($comp === 'PM2_5') $comp = 'PM2.5';

                            if (in_array($comp, ['PM2.5', 'NO2', 'O3'])) {
                                $registryMap[$regId] = ['station' => "CHMI_" . $code, 'type' => $comp];
                            }
                        }
                    }
                }
            }
        }
    }

    $lines = explode("\n", $chmi_csv);
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");

    foreach ($lines as $index => $line) {
        if ($index === 0 || empty(trim($line))) continue;

        $cols = str_getcsv($line);
        if (count($cols) < 4) continue;

        $idReg = $cols[0];
        $valType = intval($cols[2]); 
        $valStr = str_replace(',', '.', $cols[3]); 
        $value = floatval($valStr);

        if (($valType === 8 || $valType === 9) && isset($registryMap[$idReg])) {
            $info = $registryMap[$idReg];
            
            if ($info['type'] === 'PM2.5') {
                $stmt->execute([$info['station'], $timestamp, $info['type'], $value]);
                $stats['inserted']++;
            }
        }
    }
    $db->commit();
} else {
    echo "Warning: CHMI Proxy could not be loaded.\n";
}

// ==========================================
// 3. PROCESS SMART CITIZEN DATA
// ==========================================

$sck_json = fetchUrl($url_sck_proxy);
if ($sck_json) {
    $sckDevices = json_decode($sck_json, true);
    if (is_array($sckDevices)) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");

        foreach ($sckDevices as $device) {
            if (!isset($device['exposure']) || $device['exposure'] !== 'outdoor') continue;
            if (!isset($device['state']) || $device['state'] !== 'has_published') continue;
            if (empty($device['latitude']) || empty($device['longitude'])) continue;

            $data = $device['data'] ?? [];

            // PM2.5: measurement ID 89 (SCK 2.1 PMS5003), fallback 7 (older kits)
            $pm25 = null;
            if (isset($data['89']) && $data['89'] !== null) {
                $pm25 = floatval($data['89']);
            } elseif (isset($data['7']) && $data['7'] !== null) {
                $pm25 = floatval($data['7']);
            }

            if ($pm25 === null || !is_finite($pm25)) continue;

            $sensor_id = "SCK_" . $device['id'];
            $stmt->execute([$sensor_id, $timestamp, 'PM2.5', $pm25]);
            $stats['inserted']++;
        }
        $db->commit();
    } else {
        echo "Warning: SCK Proxy did not return valid JSON.\n";
    }
}

// ==========================================
// 4. PROCESS PURPLEAIR DATA
// ==========================================

$pa_json = fetchUrl($url_pa_proxy);
if ($pa_json) {
    $paSensors = json_decode($pa_json, true);
    if (is_array($paSensors)) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");

        foreach ($paSensors as $sensor) {
            if (!isset($sensor['pm25']) || $sensor['pm25'] === null) continue;

            $pm25 = floatval($sensor['pm25']);
            if ($pm25 <= 0 || $pm25 > 500) continue;

            $sensor_id = "PA_" . $sensor['id'];
            $stmt->execute([$sensor_id, $timestamp, 'PM2.5', $pm25]);
            $stats['inserted']++;

            // PM10 pokud dostupné
            if (isset($sensor['pm10']) && $sensor['pm10'] !== null) {
                $pm10 = floatval($sensor['pm10']);
                if ($pm10 > 0 && $pm10 <= 1000) {
                    $stmt->execute([$sensor_id, $timestamp, 'PM10', $pm10]);
                    $stats['inserted']++;
                }
            }
        }
        $db->commit();
    } else {
        echo "Warning: PurpleAir Proxy did not return valid JSON.\n";
    }
}

// ==========================================
// 5. CLEANUP (Data Retention)
// ==========================================

$cutoff = time() - ($retention_days * 24 * 60 * 60);
$del = $db->prepare("DELETE FROM measurements WHERE measured_at < ?");
$del->execute([$cutoff]);

echo "Status: OK. Inserted " . $stats['inserted'] . " measurements. Database size optimized.";
?>