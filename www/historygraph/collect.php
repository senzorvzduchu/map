<?php
// ==========================================
// CONFIGURATION
// ==========================================

require_once __DIR__ . '/../config.php';
$cron_token = CRON_TOKEN;

// Lokální testování: definuj PROXY_BASE_URL (např. http://127.0.0.1:8128) v config.php
// nebo před include — všechny proxy se pak čtou z lokálního serveru.
$proxy_base     = defined('PROXY_BASE_URL') ? rtrim(PROXY_BASE_URL, '/') : "https://senzorvzduchu.cz";
$url_sc_proxy   = "$proxy_base/sc-proxy.php";
$url_chmi_meta  = "$proxy_base/chmi-proxy.php?type=metadata";
$url_chmi_data  = "$proxy_base/chmi-proxy.php?type=data";
$url_sck_proxy  = "$proxy_base/sck-proxy.php";
$url_pa_proxy   = "$proxy_base/pa-proxy.php";
$url_libcich    = defined('LIBCICH_PROXY_URL') ? LIBCICH_PROXY_URL : "$proxy_base/libcich-proxy.php?mode=aqi";

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

// 2.8: ručně blokované senzory (admin/bans.php, tabulka sensor_bans) se neukládají.
// Dříve natvrdo vyloučené jednotky SC 46875 a 7213 jsou v tabulce od jejího vzniku.
require_once __DIR__ . '/../lib/SensorQuality.php';
try {
    $bannedSensors = array_flip(SensorQuality::manualBans($db));
} catch (Throwable $e) {
    $bannedSensors = []; // raději sbírat vše než spadnout
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
            // Indoor filter
            if (isset($sensor['location']['indoor']) && $sensor['location']['indoor'] == 1) continue;

            $sensor_id = "SC_" . $sensor['sensor']['id'];
            if (isset($bannedSensors[$sensor_id])) continue;
            
            foreach ($sensor['sensordatavalues'] as $val) {
                $type = $val['value_type'];
                if ($type === 'P2') $type = 'PM2.5';
                elseif ($type === 'P1') $type = 'PM10';
                
                if ($type === 'PM2.5') {
                    $pm25 = floatval($val['value']);
                    if ($pm25 <= 0 || $pm25 > 500) continue; // Implausible value — physically impossible outdoors in CZ
                    $stmt->execute([$sensor_id, $timestamp, $type, $pm25]);
                    $stats['inserted']++;
                } elseif ($type === 'PM10') {
                    // 2.8: PM10 pro vrstvu PM10
                    $pm10 = floatval($val['value']);
                    if ($pm10 <= 0 || $pm10 > 1000) continue;
                    $stmt->execute([$sensor_id, $timestamp, $type, $pm10]);
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

                            // 2.8: všechny čtyři zobrazované látky
                            if (in_array($comp, ['PM2.5', 'PM10', 'NO2', 'O3'])) {
                                $registryMap[$regId] = ['station' => "CHMI_" . $code, 'type' => $comp];
                            }
                        }
                    }
                }
            }
        }
    }

    // Stejný vzor jako Libčich: CHMÚ publikuje hodinové průměry a každý řádek
    // nese startTime (začátek hodiny, UTC). measured_at proto bereme z CSV, NE
    // z time(). Cron běží po 5 min, takže stejnou hodinu DEDUPLIKUJEME. Když ČHMÚ
    // přestane publikovat (zamrzlý zdroj), graf se zastaví na poslední reálné
    // hodině místo aby kreslil falešnou rovnou čáru rostoucí doprava.
    $lines = explode("\n", $chmi_csv);
    $db->beginTransaction();
    $stmt  = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");
    $check = $db->prepare("SELECT 1 FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at = ? LIMIT 1");

    foreach ($lines as $index => $line) {
        if ($index === 0 || empty(trim($line))) continue;

        $cols = str_getcsv($line, ",", "\"", "");
        if (count($cols) < 4) continue;

        $idReg = trim($cols[0]);
        $startTime = trim($cols[1]);          // "2026-06-29T07:00:00Z" (UTC, začátek hodiny)
        $valType = intval($cols[2]);
        $valStr = str_replace(',', '.', trim($cols[3]));
        // Prázdný/nečíselný sloupec NESMÍ skončit jako 0.0 (dedupe by pak správnou
        // hodnotu té hodiny navždy blokovala)
        if (!is_numeric($valStr)) continue;
        $value = floatval($valStr);
        if ($value < 0 || $value > 500) continue;

        // startTime → unix; díky 'Z' je výsledek nezávislý na časové zóně serveru
        $measured_at = strtotime($startTime);
        if ($measured_at === false) continue; // nečitelný čas → přeskočit

        if (($valType === 8 || $valType === 9) && isset($registryMap[$idReg])) {
            $info = $registryMap[$idReg];

            // 2.8: ukládáme PM2.5, PM10, NO2 i O3 (každá látka dedupe per hodina)
            $check->execute([$info['station'], $info['type'], $measured_at]);
            if (!$check->fetch()) {
                $stmt->execute([$info['station'], $measured_at, $info['type'], $value]);
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

            $sensor_id = "SCK_" . $device['id'];
            if (isset($bannedSensors[$sensor_id])) continue;

            if ($pm25 !== null && is_finite($pm25) && $pm25 > 0 && $pm25 <= 500) {
                $stmt->execute([$sensor_id, $timestamp, 'PM2.5', $pm25]);
                $stats['inserted']++;
            }

            // 2.8: PM10 (ID 88)
            if (isset($data['88']) && $data['88'] !== null) {
                $pm10 = floatval($data['88']);
                if (is_finite($pm10) && $pm10 > 0 && $pm10 <= 1000) {
                    $stmt->execute([$sensor_id, $timestamp, 'PM10', $pm10]);
                    $stats['inserted']++;
                }
            }

            // 2.8: hluk dB(A) z MEMS mikrofonu (ID 53 v naší normalizaci)
            if (isset($data['53']) && $data['53'] !== null) {
                $noise = floatval($data['53']);
                if (is_finite($noise) && $noise >= 20 && $noise <= 130) {
                    $stmt->execute([$sensor_id, $timestamp, 'noise', $noise]);
                    $stats['inserted']++;
                }
            }
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
            if (isset($bannedSensors[$sensor_id])) continue;
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
// 5. PROCESS LIBCICH DATA (hodinové průměry z /api/aqi)
// ==========================================
// Jiný vzor než ostatní sítě: libcich-proxy.php?mode=aqi vrací poslední UZAVŘENOU
// hodinu s measured_at = unix čas začátku hodiny. Cron běží po 5 min, proto se
// stejná hodina DEDUPLIKUJE (jinak 12 duplicit/hod → rozbitý graf + falešná
// "stuck" detekce v get_history.php).

$lib_json = fetchUrl($url_libcich);
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
            if ($pm25 < 0 || $pm25 > 500) continue; // 0 je u hodinového průměru validní

            $sensor_id = "LIBCICH_" . $sensor['id'];
            if (isset($bannedSensors[$sensor_id])) continue;
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
        echo "Warning: Libcich Proxy did not return valid JSON.\n";
    }
}

// ==========================================
// 6. CLEANUP (Data Retention)
// ==========================================

$cutoff = time() - ($retention_days * 24 * 60 * 60);
$del = $db->prepare("DELETE FROM measurements WHERE measured_at < ?");
$del->execute([$cutoff]);

echo "Status: OK. Inserted " . $stats['inserted'] . " measurements. Database size optimized.";
?>