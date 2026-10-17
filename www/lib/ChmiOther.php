<?php
declare(strict_types=1);

/**
 * ChmiOther — čistá logika (výběr slotu, parsování vrstev, sloučení) pro
 * chmi-other-proxy.php a tests/test_chmi_other.php. Bez sítě, bez souborů.
 *
 * 3.4: referenční stanice kvality ovzduší JINÝCH provozovatelů než ČHMÚ (zdravotní
 * ústavy, kraje, města, podniky). ČHMÚ je sbírá do ISKO a zobrazuje na chmi.cz, ale
 * v otevřených datech (opendata.chmi.cz/air_quality/now, 98 lokalit) jsou jen
 * stanice vlastněné ČHMÚ. Zdrojem je proto nedokumentovaný JSON backend map na
 * chmi.cz (ověřeno 17. 10. 2026, viz SCOPE_3.4-ref-stations.md):
 *
 *   https://data-provider.chmi.cz/api/data/ovzdusi/stanice/mereni/{PM10|PM2_5|NO2|O3|SO2}/1h/{YYYYMMDDHHMM}
 *     GeoJSON FeatureCollection, 258 stanic (CZ + SK + AT), properties.id = kód ISKO,
 *     properties.value = řetězec s desetinnou ČÁRKOU ("18,0") nebo "N/A",
 *     properties.lastMeasurement = "2026-10-17T17:00:00Z". Souřadnice jsou EPSG:32633
 *     (UTM 33N) — nepoužíváme je, WGS84 je ve STATIONS (z ISKO lokalita.php).
 *   Slot {YYYYMMDDHHMM} je KONEC průměrovací hodiny v UTC: slot 202610171700 nese
 *     stejné hodnoty jako řádek opendata CSV se startTime 2026-10-17T16:00:00Z
 *     (ověřeno na šesti stanicích ČHMÚ). measured_at (= startTime jako u CHMI_ z
 *     opendata) je proto slot − 1 h. Slot H se objevuje ~50 min po celé hodině →
 *     v první části hodiny vrstva pro H ještě neexistuje (404) a bere se H−1.
 *   https://data-provider.chmi.cz/api/map/init/ovzdusi.pm10-hod  → refreshInterval 600 s,
 *     dataParts = 168 slotů (7 dní zpět). Licence: patička chmi.cz — produkty ČHMÚ
 *     dostupné na webu podléhají CC BY 4.0 (atribuce „ČHMÚ“ v popupu i ℹ modalu).
 *
 * Stanice zůstávají v síti `chmi` (ID CHMI_<kód>, černý kroužek, váha interpolace 1.0),
 * jen nesou `owner` do popupu. Kódy se s opendata nepřekrývají (ověřeno: 0 společných).
 */
final class ChmiOther
{
    public const BASE_URL = 'https://data-provider.chmi.cz/api/data/ovzdusi/stanice/mereni';

    /** Vrstva ve zdroji → klíč v mapě (app.js POLLUTANTS). SO2 mapa nezobrazuje → nestahuje se. */
    public const LAYERS = ['PM10' => 'pm10', 'PM2_5' => 'pm25', 'NO2' => 'no2', 'O3' => 'o3'];

    /** Kolik slotů zpět (hodin) se zkouší, když poslední ještě neexistuje nebo má N/A. */
    public const MAX_BACK_SLOTS = 3;

    /** Nejnovější hodnota starší než STALE_SEC → stale (šedý marker, hláška „neaktualizuje“). */
    public const STALE_SEC = 3 * 3600;

    /** Fyzikální meze µg/m³ (jako collect.php blok 2 a POLLUTANTS.valid). */
    public const LIMITS = ['pm25' => [0.0, 1000.0], 'pm10' => [0.0, 2000.0], 'no2' => [0.0, 1000.0], 'o3' => [0.0, 1000.0]];

    /** Plyny s hodnotou přesně 0,0 = výpadek analyzátoru (Zlín-ZŠ Kvítkova 17. 10. 2026), ne nula. */
    public const ZERO_IS_NULL = ['no2', 'o3'];

    /**
     * Sledované stanice: kód ISKO → [název, provozovatel, lat, lon] (WGS84 z ovzdusi.chmi.cz/lokalita.php).
     * Výběr 17. 10. 2026 = aktivní lokality v ČR s jiným vlastníkem než ČHMÚ, které se objevují
     * v hodinových vrstvách (40 z 63; zbylých 15 kontinuálních stanic ORGREZ/VUHU aj. ČHMÚ hodinově
     * nedostává, 11 je manuálních). Stanice, která hlásí jen „N/A“, se z výstupu vypustí sama.
     * Názvy provozovatelů jsou upravené pro popup (ISKO: „ZÚ, SMHa“, „MÚ Rožnov pos Radhoštěm“…).
     * Přidání stanice = jeden řádek zde + nic jiného; seznam všech 63 je v SCOPE_3.4-stations.json.
     */
    public const STATIONS = [
        // Praha a Středočeský kraj
        'ALER' => ['Letiště Praha', 'Letiště Praha a.s.', 50.10709, 14.25723],
        'ARER' => ['Praha 5-Řeporyje', 'Zdravotní ústav Ústí nad Labem', 50.03051, 14.30958],
        'ASRO' => ['Praha 10-Šrobárova', 'Zdravotní ústav Ústí nad Labem / SZÚ', 50.07515, 14.47266],
        'SKOA' => ['Kolín SAZ', 'Zdravotní ústav Ústí nad Labem', 50.02549, 15.20662],
        'SKRP' => ['Kralupy nad Vltavou-sportoviště', 'Zdravotní ústav Ústí nad Labem', 50.25142, 14.31667],
        'STCS' => ['Tobolka-Čertovy schody', 'Vápenka Čertovy schody, a.s.', 49.91850, 14.09449],
        // Čechy
        'CCBT' => ['Čes. Budějovice-Třešň.', 'Zdravotní ústav Ústí nad Labem', 48.96590, 14.50879],
        'CSTR' => ['Strakonice-ZŠ Strakonice', 'Zdravotní ústav Ústí nad Labem', 49.25811, 13.90872],
        'PKLS' => ['Klatovy soud', 'Zdravotní ústav Ústí nad Labem', 49.40061, 13.28692],
        'UUKT' => ['Ústí n.L.-Pasteurova', 'Zdravotní ústav Ústí nad Labem', 50.66463, 14.02681],
        'HHKS' => ['Hr.Král.-Sukovy sady', 'Zdravotní ústav Ústí nad Labem', 50.21170, 15.81415],
        // Vysočina
        'JJIZ' => ['Jihlava-Znojemská', 'Zdravotní ústav Ostrava', 49.39244, 15.59128],
        'JPEM' => ['Pelhřimov', 'Zdravotní ústav Ostrava', 49.43500, 15.20833],
        'JZNZ' => ['Žďár nad Sázavou', 'Zdravotní ústav Ostrava', 49.56456, 15.94100],
        // Jihomoravský kraj
        'BBMA' => ['Brno-Arboretum', 'Statutární město Brno', 49.21609, 16.61384],
        'BBOM' => ['Brno-Komárov', 'Statutární město Brno', 49.17743, 16.62227],
        'BBML' => ['Brno-Lány', 'Statutární město Brno', 49.16526, 16.58081],
        'BBMS' => ['Brno-Svatoplukova', 'Statutární město Brno', 49.20816, 16.64252],
        'BHOD' => ['Hodonín', 'Zdravotní ústav Ostrava', 48.85728, 17.13139],
        'BMOK' => ['Mokrá', 'Českomoravský cement a.s.', 49.21944, 16.75531],
        'BMOC' => ['Sivice', 'Českomoravský cement a.s.', 49.20819, 16.77844],
        // Olomoucký a Zlínský kraj
        'MLOS' => ['Loštice', 'Obec Loštice', 49.74792, 16.92578],
        'MPHR' => ['Hranice', 'Město Hranice', 49.55189, 17.73122],
        'MSMS' => ['Šumperk, ZŠ Vrchlického', 'Město Šumperk', 49.97158, 16.97847],
        'MVBY' => ['Velká Bystřice', 'Město Velká Bystřice', 49.59292, 17.35736],
        'ZOTM' => ['Otrokovice-město', 'Město Otrokovice', 49.20891, 17.53474],
        'ZROZ' => ['Rožnov pod Radhoštěm', 'Město Rožnov pod Radhoštěm', 49.46011, 18.13822],
        'ZZZS' => ['Zlín-ZŠ Kvítkova', 'Město Zlín', 49.22847, 17.67508],
        // Moravskoslezský kraj
        'TCHO' => ['Chotěbuz', 'Zdravotní ústav Ostrava / Moravskoslezský kraj', 49.77801, 18.59978],
        'TJBL' => ['Jablunkov', 'Zdravotní ústav Ostrava / Moravskoslezský kraj', 49.58474, 18.76248],
        'TMTY' => ['Metylovice', 'Zdravotní ústav Ostrava / Moravskoslezský kraj', 49.61529, 18.33355],
        'THAO' => ['Havířov ZÚ', 'Zdravotní ústav Ostrava / město Havířov', 49.77153, 18.44319],
        'TKAO' => ['Karviná ZÚ', 'Zdravotní ústav Ostrava', 49.53345, 18.47852],
        'TNSV' => ['Nošovice', 'Obec Nošovice', 49.65307, 18.43183],
        'TOSJ' => ['Ostrava Jih', 'Zdravotní ústav Ostrava / město Ostrava', 49.79454, 18.24927],
        'TOHU' => ['Ostrava-Hrušov', 'Zdravotní ústav Ostrava / město Ostrava', 49.86772, 18.28375],
        'TOMH' => ['Ostrava-Mariánské Hory', 'Zdravotní ústav Ostrava / město Ostrava', 49.82486, 18.26365],
        'TORO' => ['Ostrava-Radvanice OZO', 'Zdravotní ústav Ostrava / město Ostrava', 49.81856, 18.34039],
        'TORD' => ['Ostrava-Radvanice ZÚ', 'Zdravotní ústav Ostrava / město Ostrava', 49.80706, 18.33914],
        'TTRK' => ['Třinec-Kanada', 'Statutární město Třinec', 49.67238, 18.64304],
    ];

    /** Slot (konec hodiny, UTC) pro daný čas: 17:55Z → "202610171700". */
    public static function slot(int $ts): string
    {
        return gmdate('YmdH', $ts) . '00';
    }

    /**
     * Kandidátní sloty od nejnovějšího: H, H−1, … H−$back. Vrstva pro H existuje až
     * ~50 min po celé hodině, proto se vždy zkouší i starší.
     * @return string[]
     */
    public static function slotCandidates(int $now, int $back = self::MAX_BACK_SLOTS): array
    {
        $out = [];
        for ($i = 0; $i <= $back; $i++) {
            $out[] = self::slot($now - $i * 3600);
        }
        return $out;
    }

    /** Konec průměrovací hodiny slotu jako unix čas (UTC). */
    public static function slotEnd(string $slot): int
    {
        $ts = DateTimeImmutable::createFromFormat('!YmdHi', $slot, new DateTimeZone('UTC'));
        if ($ts === false) {
            throw new InvalidArgumentException('Neplatný slot: ' . $slot);
        }
        return $ts->getTimestamp();
    }

    /** Začátek průměrovací hodiny = measured_at (jako startTime v opendata CSV). */
    public static function slotStart(string $slot): int
    {
        return self::slotEnd($slot) - 3600;
    }

    public static function layerUrl(string $layer, string $slot): string
    {
        return self::BASE_URL . '/' . $layer . '/1h/' . $slot;
    }

    /**
     * "18,0" → 18.0; "N/A", prázdno, nečíslo, mimo meze → null; plyny s 0,0 → null.
     */
    public static function parseValue(mixed $raw, string $key): ?float
    {
        if ($raw === null || is_bool($raw)) return null;
        $s = str_replace([',', "\u{00A0}", ' '], ['.', '', ''], trim((string)$raw));
        if ($s === '' || !is_numeric($s)) return null;
        $v = (float)$s;
        if (!is_finite($v)) return null;
        [$lo, $hi] = self::LIMITS[$key] ?? [0.0, 5000.0];
        if ($v < $lo || $v > $hi) return null;
        if ($v == 0.0 && in_array($key, self::ZERO_IS_NULL, true)) return null;
        return round($v, 1);
    }

    /**
     * Z GeoJSON vrstvy vybere sledované stanice: kód → hodnota|null. Stanice, která
     * ve vrstvě není, v poli chybí (rozdíl proti „je tam s N/A“ je vidět v debugu).
     * @return array<string, ?float>
     */
    public static function parseLayer(array $geojson, string $key): array
    {
        $out = [];
        foreach ($geojson['features'] ?? [] as $f) {
            $p = $f['properties'] ?? null;
            if (!is_array($p)) continue;
            $code = (string)($p['id'] ?? '');
            if ($code === '' || !isset(self::STATIONS[$code])) continue;
            $out[$code] = self::parseValue($p['value'] ?? null, $key);
        }
        return $out;
    }

    /**
     * Sloučí vrstvy více slotů do záznamů stanic. $bySlot = [slot => [klíč => [kód => hodnota|null]]],
     * sloty OD NEJNOVĚJŠÍHO. Pro každou stanici a látku se vezme hodnota z nejnovějšího slotu,
     * kde není null (stanice hlásí N/A, dokud hodinu nedopočítá, nebo analyzátor stojí).
     * Stanice bez jediné hodnoty se vypustí (jako u opendata). Výstup seřazený podle kódu:
     *   code, name, owner, lat, lon, measured_at (ISO, začátek hodiny nejnovější použité hodnoty),
     *   slot, age_min (od konce té hodiny), stale, values {pm25,pm10,no2,o3}, value_times {klíč → unix začátek hodiny}
     * @return array<int, array<string, mixed>>
     */
    public static function merge(array $bySlot, int $now): array
    {
        $slots = array_keys($bySlot);
        usort($slots, fn($a, $b) => strcmp((string)$b, (string)$a)); // nejnovější první
        $out = [];
        foreach (self::STATIONS as $code => [$name, $owner, $lat, $lon]) {
            $values = [];
            $times  = [];
            $newest = null;
            foreach (self::LAYERS as $key) {
                foreach ($slots as $slot) {
                    $v = $bySlot[$slot][$key][$code] ?? null;
                    if ($v === null) continue;
                    $values[$key] = $v;
                    $times[$key]  = self::slotStart((string)$slot);
                    if ($newest === null || strcmp((string)$slot, $newest) > 0) $newest = (string)$slot;
                    break;
                }
            }
            if (!$values) continue;
            $end = self::slotEnd($newest);
            $age = max(0, intdiv($now - $end, 60));
            $out[] = [
                'code'        => $code,
                'name'        => $name,
                'owner'       => $owner,
                'lat'         => $lat,
                'lon'         => $lon,
                'measured_at' => gmdate('c', $end - 3600),
                'slot'        => $newest,
                'age_min'     => $age,
                'stale'       => ($now - $end) > self::STALE_SEC,
                'values'      => $values,
                'value_times' => $times,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }
}
