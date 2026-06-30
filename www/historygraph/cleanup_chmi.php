<?php
// ==========================================
// cleanup_chmi.php — JEDNORÁZOVÝ úklid (verze 2.6)
// ==========================================
// Smaže "zaseknuté" CHMÚ řádky vzniklé před fixem 2.6, kdy se measured_at
// stampoval přes time() (čas běhu cronu) místo startTime z CSV. Tyto řádky
// NEJSOU zarovnané na celou hodinu (measured_at % 3600 != 0); správně uložené
// řádky po fixu vždy jsou. To je spolehlivý rozlišovač.
//
// Použití (jednou po nasazení 2.6):
//   https://senzorvzduchu.cz/historygraph/cleanup_chmi.php?token=XXXX
//   přidej &dry=1 pro náhled (jen spočítá, nic nemaže)
//
// Po úspěšném úklidu soubor klidně smaž z FTP.

require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

$db_file = __DIR__ . '/air_quality_history.db';

// --- Bezpečnost ---
if (!isset($_GET['token']) || $_GET['token'] !== CRON_TOKEN) {
    http_response_code(403);
    die("Access Denied: Invalid Token");
}

$dry_run = isset($_GET['dry']) && $_GET['dry'] == '1';

try {
    if (!file_exists($db_file)) {
        throw new Exception("Database not found.");
    }

    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $where = "sensor_id LIKE 'CHMI_%' AND (measured_at % 3600) != 0";

    // Kolik řádků padne
    $count = (int) $db->query("SELECT COUNT(*) FROM measurements WHERE $where")->fetchColumn();

    // Kolik správných (hodinově zarovnaných) CHMÚ řádků zůstane — kontrola, že nemažeme vše
    $keep = (int) $db->query("SELECT COUNT(*) FROM measurements WHERE sensor_id LIKE 'CHMI_%' AND (measured_at % 3600) = 0")->fetchColumn();

    if ($dry_run) {
        echo "DRY RUN — nic se nemaže.\n";
        echo "Ke smazání (zaseknuté CHMÚ řádky): $count\n";
        echo "Zůstane (hodinově zarovnané CHMÚ řádky): $keep\n";
        exit;
    }

    $del = $db->prepare("DELETE FROM measurements WHERE $where");
    $del->execute();
    $deleted = $del->rowCount();

    echo "OK. Smazáno $deleted zaseknutých CHMÚ řádků. Hodinově zarovnaných CHMÚ řádků zůstává: $keep.\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
?>
