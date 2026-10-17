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
$url_ag_proxy   = "$proxy_base/ag-proxy.php";
$url_chmi_meteo = "$proxy_base/chmi-meteo-proxy.php"; // 3.1: meteostanice ČHMÚ (T/RH)
$url_chmi_other = "$proxy_base/chmi-other-proxy.php"; // 3.4: stanice jiných provozovatelů (přes chmi.cz)
$url_libcich   = defined('LIBCICH_PROXY_URL') ? LIBCICH_PROXY_URL : "$proxy_base/libcich-proxy.php?mode=aqi";

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

// 3.0: teplota a vlhkost (vrstva + graf). Čidlo v krytu senzoru na slunci jde
// i přes 50 °C, pod -40 °C v ČR nebývá; RH 0–100 (orosené SHT/DHT visí na 100 %).
// $check (dedupe podle measured_at) jen u zdrojů s časem ze zdroje (Libčich).
function insertMeteo(PDOStatement $stmt, string $sensor_id, int $ts, $temp, $hum, array &$stats, ?PDOStatement $check = null): void {
    foreach (['temperature' => [$temp, -40, 60], 'humidity' => [$hum, 0, 100]] as $type => [$v, $lo, $hi]) {
        if ($v === null || $v === '' || !is_numeric($v)) continue;
        $v = (float)$v;
        if (!is_finite($v) || $v < $lo || $v > $hi) continue;
        if ($check) {
            $check->execute([$sensor_id, $type, $ts]);
            if ($check->fetch()) continue;
        }
        $stmt->execute([$sensor_id, $ts, $type, $v]);
        $stats['inserted']++;
    }
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
// Automatické příznaky (stuck/plateau, od 3.3 indoor) se tu ZÁMĚRNĚ nepřeskakují: jsou
// samoléčivé a počítají se z této historie — bez dalších nočních vzorků by příznak
// „indoor“ po 24 h zmizel a senzor se vracel na mapu. Skrývá ho mapa (sensor-bans.php).
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

        // 3.0: teplota/vlhkost je v Sensor.Community jiný čip (BME280/DHT22) se
        // samostatným sensor ID na stejné location → přiřadit k prachovému senzoru
        // podle location ID (stejně jako app.js).
        $locEnv = [];
        foreach ($data as $sensor) {
            $lid = $sensor['location']['id'] ?? null;
            if ($lid === null) continue;
            foreach ($sensor['sensordatavalues'] as $val) {
                if ($val['value_type'] === 'temperature') $locEnv[$lid]['temperature'] = $val['value'];
                elseif ($val['value_type'] === 'humidity') $locEnv[$lid]['humidity'] = $val['value'];
            }
        }

        // 3.0: sc-proxy vrací pro většinu senzorů DVA záznamy (dvě měření z posledních
        // 5 min). Oba se dřív zapsaly se stejným time() → polovina řádků SC_ byla
        // duplicitní (stejný senzor, stejný čas). Bere se první záznam senzoru v běhu.
        $seenSc = [];
        foreach ($data as $sensor) {
            // Indoor filter
            if (isset($sensor['location']['indoor']) && $sensor['location']['indoor'] == 1) continue;

            $sensor_id = "SC_" . $sensor['sensor']['id'];
            if (isset($bannedSensors[$sensor_id])) continue;
            if (isset($seenSc[$sensor_id])) continue;
            $seenSc[$sensor_id] = true;
            
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

            // 3.0: teplota a vlhkost téže location
            $env = isset($sensor['location']['id']) ? ($locEnv[$sensor['location']['id']] ?? null) : null;
            if ($env) insertMeteo($stmt, $sensor_id, $timestamp, $env['temperature'] ?? null, $env['humidity'] ?? null, $stats);
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
// 2b. PROCESS CHMI METEO DATA (3.1 — teplota a vlhkost z meteostanic ČHMÚ)
// ==========================================
// chmi-meteo-proxy.php vrací { stations: [ {wsi, temperature, humidity, measured_at, …} ] }.
// Zdroj publikuje 10min hodnoty, ale soubor jednou za hodinu; cron běží po 5 min →
// measured_at bereme ZE ZDROJE a stejnou časovou značku DEDUPLIKUJEME (jako ČHMÚ PM
// a Libčich). Jinak by se stejná hodnota zapsala 12× za hodinu pod time().
// Stanice bez měření za 3 h se neukládá (proxy ji stejně vypustí).

$cm_json = fetchUrl($url_chmi_meteo);
if ($cm_json) {
    $cmData = json_decode($cm_json, true);
    if (is_array($cmData) && isset($cmData['stations']) && is_array($cmData['stations'])) {
        $db->beginTransaction();
        $stmt  = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");
        $check = $db->prepare("SELECT 1 FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at = ? LIMIT 1");

        foreach ($cmData['stations'] as $st) {
            if (empty($st['wsi']) || empty($st['measured_at'])) continue;
            $sensor_id = "CHMI_M_" . $st['wsi'];
            $ts = strtotime($st['measured_at']);
            if ($ts === false || $timestamp - $ts > 3 * 3600) continue;
            insertMeteo($stmt, $sensor_id, $ts, $st['temperature'] ?? null, $st['humidity'] ?? null, $stats, $check);
        }
        $db->commit();
    } else {
        echo "Warning: CHMI Meteo Proxy did not return valid JSON.
";
    }
}

// ==========================================
// 2c. PROCESS CHMI OTHER-OWNER STATIONS (3.4 — ZÚ, kraje, města, podniky přes chmi.cz)
// ==========================================
// chmi-other-proxy.php vrací { stations: [ {code, values:{pm25,pm10,no2,o3}, value_times:{klíč → unix
// ZAČÁTEK hodiny}, measured_at, …} ] }. Stejná ID (CHMI_<kód>) a stejná konvence jako blok 2:
// measured_at = začátek průměrovací hodiny, dedupe per (stanice, látka, hodina). Každá látka
// má vlastní čas — proxy doplňuje N/A z předchozího slotu, takže NO₂ může být o hodinu starší
// než PM. Hodnota starší než 4 h se neukládá (proxy ji dává jen jako stale pro mapu).

$co_json = fetchUrl($url_chmi_other);
if ($co_json) {
    $coData = json_decode($co_json, true);
    if (is_array($coData) && isset($coData['stations']) && is_array($coData['stations'])) {
        $typeMap = ['pm25' => 'PM2.5', 'pm10' => 'PM10', 'no2' => 'NO2', 'o3' => 'O3'];
        $db->beginTransaction();
        $stmt  = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");
        $check = $db->prepare("SELECT 1 FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at = ? LIMIT 1");

        foreach ($coData['stations'] as $st) {
            if (empty($st['code']) || !preg_match('/^[A-Z0-9]{4,6}$/', (string)$st['code'])) continue;
            $sensor_id = "CHMI_" . $st['code'];
            $values = is_array($st['values'] ?? null) ? $st['values'] : [];
            $times  = is_array($st['value_times'] ?? null) ? $st['value_times'] : [];
            $fallbackTs = !empty($st['measured_at']) ? strtotime((string)$st['measured_at']) : false;

            foreach ($typeMap as $key => $type) {
                if (!isset($values[$key]) || !is_numeric($values[$key])) continue;
                $value = (float)$values[$key];
                if ($value < 0 || $value > 500) continue;
                $ts = isset($times[$key]) && is_numeric($times[$key]) ? (int)$times[$key] : $fallbackTs;
                if ($ts === false || $ts <= 0 || $timestamp - $ts > 4 * 3600) continue;

                $check->execute([$sensor_id, $type, $ts]);
                if (!$check->fetch()) {
                    $stmt->execute([$sensor_id, $ts, $type, $value]);
                    $stats['inserted']++;
                }
            }
        }
        $db->commit();
    } else {
        echo "Warning: CHMI Other Proxy did not return valid JSON.\n";
    }
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

            // 3.0: teplota (ID 55) a vlhkost (ID 56)
            insertMeteo($stmt, $sensor_id, $timestamp, $data['55'] ?? null, $data['56'] ?? null, $stats);
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

            // 3.0: teplota (°C z pa-proxy) a vlhkost
            insertMeteo($stmt, $sensor_id, $timestamp, $sensor['temperature'] ?? null, $sensor['humidity'] ?? null, $stats);
        }
        $db->commit();
    } else {
        echo "Warning: PurpleAir Proxy did not return valid JSON.\n";
    }
}

// ==========================================
// 4b. PROCESS AIRGRADIENT DATA
// ==========================================
// ag-proxy.php vrací { sensors: [...] } (ne holé pole jako PurpleAir). Ukládají se
// jen senzory s online=true (měření < 30 min): starší hodnota by se každých 5 min
// zapsala znovu → plochý průběh → falešný auto-příznak „stuck".

$ag_json = fetchUrl($url_ag_proxy);
if ($ag_json) {
    $agData = json_decode($ag_json, true);
    if (is_array($agData) && isset($agData['sensors']) && is_array($agData['sensors'])) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");

        foreach ($agData['sensors'] as $sensor) {
            if (empty($sensor['online'])) continue;
            $sensor_id = "AG_" . $sensor['id'];
            if (isset($bannedSensors[$sensor_id])) continue;

            if (isset($sensor['pm25']) && $sensor['pm25'] !== null) {
                $pm25 = floatval($sensor['pm25']);
                if ($pm25 > 0 && $pm25 <= 500) {
                    $stmt->execute([$sensor_id, $timestamp, 'PM2.5', $pm25]);
                    $stats['inserted']++;
                }
            }
            if (isset($sensor['pm10']) && $sensor['pm10'] !== null) {
                $pm10 = floatval($sensor['pm10']);
                if ($pm10 > 0 && $pm10 <= 1000) {
                    $stmt->execute([$sensor_id, $timestamp, 'PM10', $pm10]);
                    $stats['inserted']++;
                }
            }

            // 3.0: teplota a vlhkost (jen online senzory, stejně jako PM)
            insertMeteo($stmt, $sensor_id, $timestamp, $sensor['temperature'] ?? null, $sensor['humidity'] ?? null, $stats);
        }
        $db->commit();
    } else {
        echo "Warning: AirGradient Proxy did not return valid JSON.\n";
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
// 5b. LIBCICH — teplota a vlhkost (živá data, 2min intervaly)
// ==========================================
// /api/aqi (hodinové průměry) teplotu ani vlhkost nenese; výchozí režim proxy
// (poslední 2min interval, cache) ano. measured_at = ISO klíč intervalu →
// dedupe stejně jako u hodinových PM (cron po 5 min, interval se opakuje).

$url_libcich_live = defined('LIBCICH_PROXY_URL')
    ? preg_replace('/[?&]mode=aqi\b/', '', LIBCICH_PROXY_URL)
    : "$proxy_base/libcich-proxy.php";
$lib_live_json = fetchUrl($url_libcich_live);
if ($lib_live_json) {
    $libLive = json_decode($lib_live_json, true);
    if (is_array($libLive)) {
        $db->beginTransaction();
        $stmt  = $db->prepare("INSERT INTO measurements (sensor_id, measured_at, value_type, value) VALUES (?, ?, ?, ?)");
        $check = $db->prepare("SELECT 1 FROM measurements WHERE sensor_id = ? AND value_type = ? AND measured_at = ? LIMIT 1");

        foreach ($libLive as $sensor) {
            if (empty($sensor['id']) || empty($sensor['measured_at'])) continue;
            $sensor_id = "LIBCICH_" . $sensor['id'];
            if (isset($bannedSensors[$sensor_id])) continue;
            $ts = strtotime($sensor['measured_at']);
            if ($ts === false) continue;
            insertMeteo($stmt, $sensor_id, $ts, $sensor['temperature'] ?? null, $sensor['humidity'] ?? null, $stats, $check);
        }
        $db->commit();
    } else {
        echo "Warning: Libcich live Proxy did not return valid JSON.\n";
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