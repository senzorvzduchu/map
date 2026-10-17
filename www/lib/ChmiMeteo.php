<?php
declare(strict_types=1);

/**
 * ChmiMeteo — čistá logika (výběr stanic, parsování, normalizace) pro
 * chmi-meteo-proxy.php a tests/test_chmi_meteo.php. Bez sítě, bez souborů.
 *
 * Zdroj: otevřená data ČHMÚ, 10minutová meteorologická měření
 *   https://opendata.chmi.cz/meteorology/climate/now/data/10m-<WSI>-<YYYYMMDD>.json
 *     data.data.header = "STATION,ELEMENT,DT,VAL,FLAG,QUALITY"; řádky za celý den (UTC)
 *     se v souboru hromadí (00:00Z → teď), seřazené podle prvku, pak času.
 *     Prvky: T = teplota vzduchu 2 m (°C), H = relativní vlhkost (%), dále
 *     TMA/TMI, F/Fmax/D (vítr), P (tlak), SRA10M (srážky) … — bereme jen T a H.
 *     Soubor se přegenerovává jednou za hodinu v HH:01 (ověřeno 16. 10. 2026: soubor
 *     15:01Z končí řádkem 14:50Z, v 15:52Z pořád stejný) → zpoždění 10–70 min.
 *     Jeden soubor na stanici a den, ~80–150 kB → proto PODMNOŽINA.
 *     Část stanic z meta1 10m soubor nemá (404) nebo v něm T/H není (srážkoměrné,
 *     ~6 kB) — výběr níže je ověřený 16. 10. 2026 (`?debug=1` → `missing`).
 *   https://opendata.chmi.cz/meteorology/climate/now/metadata/meta1-<YYYYMMDD>.json
 *     header "WSI,GH_ID,FULL_NAME,GEOGR1,GEOGR2,ELEVATION,BEGIN_DATE", GEOGR1 = lon,
 *     GEOGR2 = lat; ~810 stanic v ČR (+ pár zahraničních). Stahuje se 1× denně.
 *   metadata/meta4-*.json = číselník QUALITY: 0 Good, 1 Suspect, 2 Poor,
 *     3 Estimated, 4 Missing, 5 Unknown. Real-time řádky mají prakticky vždy 5
 *     (kontrola kvality běží až zpětně) → GOOD_QUALITY = {0, 5}.
 *
 * Adresář drží jen dnešek a včerejšek. Po půlnoci UTC může dnešní soubor chvíli
 * chybět (404) nebo být prázdný → proxy sáhne po včerejším (ChmiMeteo::dates()).
 */
final class ChmiMeteo
{
    public const BASE_URL = 'https://opendata.chmi.cz/meteorology/climate/now';

    /** QUALITY kódy, které bereme jako platné měření (meta4: 0 = Good, 5 = Unknown/real-time). */
    public const GOOD_QUALITY = [0, 5];

    /**
     * Měření starší než MAX_AGE_SEC se vypouští; starší než STALE_SEC jde ven jako stale (šedý marker).
     * Soubor stanice se generuje HODINOVĚ v HH:01 s řádky do (HH−1):50 (ověřeno 16. 10. 2026:
     * 15:01Z → poslední 14:50Z), takže běžné stáří je 10–70 min + až 15 min TTL cache.
     * 2 h proto znamená skutečný výpadek, ne normální zpoždění publikace.
     */
    public const MAX_AGE_SEC = 3 * 3600;
    public const STALE_SEC   = 2 * 3600;

    /** Fyzikální meze (stejné jako collect.php / POLLUTANTS.valid v app.js). */
    public const T_MIN = -40.0;
    public const T_MAX = 60.0;
    public const H_MIN = 0.0;
    public const H_MAX = 100.0;

    /**
     * Sledované stanice: WSI → záložní název (použije se, když meta1 stanici nemá).
     * Výběr 16. 10. 2026 z meta1: krajská města (Praha 7, Brno 2, Ostrava 3, Plzeň 2,
     * Ústí n. L. 2, Karlovy Vary 2, ostatní po jedné), okresní města a pánevní stanice,
     * horské / venkovské reference (Lysá hora, Sněžka, Churáňov, Milešovka, Praděd,
     * Košetice). Každá stanice = jeden soubor ~80–150 kB po 15 min, takže
     * seznam držet do ~60 položek (viz DEPLOY_3.1.md, tabulka zátěže). Přidání stanice =
     * jeden řádek zde (WSI z meta1-*.json), nic jiného.
     */
    public const STATIONS = [
        // Praha
        '0-20000-0-11519' => 'Praha, Karlov',
        '0-20000-0-11520' => 'Praha, Libuš',
        '0-20000-0-11518' => 'Praha, Ruzyně',
        '0-20000-0-11567' => 'Praha, Kbely',
        '0-203-0-11514'   => 'Praha, Klementinum',
        '0-203-0-10904013001' => 'Praha, Komořany',
        '0-203-0-11201020001' => 'Praha, Vinohrady - Flora',
        // Brno
        '0-20000-0-11723' => 'Brno, Tuřany',
        '0-203-0-11721'   => 'Brno, Žabovřesky',
        // Ostrava
        '0-203-0-11790'   => 'Ostrava, Poruba',
        '0-20000-0-11782' => 'Mošnov',
        '0-203-0-20302008201' => 'Ostrava, Slezská Ostrava',
        // Plzeň
        '0-20000-0-11450' => 'Plzeň, Mikulka',
        '0-203-0-11446'   => 'Plzeň, Bolevec',
        // Krajská města
        '0-203-0-11742'   => 'Olomouc, Holice',
        '0-20000-0-11603' => 'Liberec',
        '0-203-0-11649'   => 'Hradec Králové, Nový Hradec Králové',
        '0-20000-0-11652' => 'Pardubice, letiště',
        '0-20000-0-11546' => 'České Budějovice, Rožnov',
        '0-20000-0-11502' => 'Ústí nad Labem, Kočkov',
        '0-203-0-11503'   => 'Ústí nad Labem, Vaňov',
        '0-203-0-11775'   => 'Zlín',
        '0-203-0-41601049001' => 'Jihlava, Hruškové Dvory',
        '0-203-0-11415'   => 'Karlovy Vary',
        '0-20000-0-11414' => 'Karlovy Vary, Olšová Vrata',
        // Okresní města
        '0-203-0-11461'   => 'Teplice',
        '0-203-0-11501'   => 'Děčín',
        '0-20000-0-11438' => 'Tušimice',
        '0-20000-0-11433' => 'Kopisty',
        '0-20000-0-11406' => 'Cheb',
        '0-20000-0-11609' => 'Jičín',
        '0-203-0-11582'   => 'Tábor, Měšice',
        '0-203-0-11485'   => 'Příbram',
        '0-203-0-11656'   => 'Havlíčkův Brod',
        '0-20000-0-11693' => 'Dukovany',
        '0-20000-0-11698' => 'Kuchařovice',
        '0-203-0-11751'   => 'Kroměříž',
        '0-20000-0-11747' => 'Prostějov',
        '0-203-0-11748'   => 'Přerov',
        '0-203-0-11705'   => 'Šumperk',
        '0-203-0-11763'   => 'Opava',
        '0-203-0-11795'   => 'Karviná',
        '0-203-0-20301053301' => 'Frýdek-Místek, Sviadnov',
        '0-203-0-11788'   => 'Vsetín',
        '0-203-0-11645'   => 'Trutnov',
        '0-203-0-11680'   => 'Rychnov nad Kněžnou',
        '0-203-0-11301092001' => 'Sokolov',
        '0-203-0-11491'   => 'Strakonice, Nové Strakonice',
        '0-203-0-11545'   => 'Český Krumlov, Srnín',
        '0-203-0-11731'   => 'Jeseník',
        '0-203-0-11554'   => 'Česká Lípa',
        '0-203-0-11455'   => 'Klatovy',
        '0-203-0-11635'   => 'Jindřichův Hradec, Děbolín',
        '0-20000-0-11679' => 'Ústí nad Orlicí',
        // Horské a venkovské reference
        '0-20000-0-11628' => 'Košetice, Křešín, Kramolín',
        // 3.3: reference pro auto-příznak „indoor“ v Polabí — pár AirGradient 61860/78554 v Poděbradech
        // neměl žádnou stanici do 30 km (nejbližší Jičín 41 km). 10m soubor má T i H (ověřeno 16. 10. 2026).
        '0-203-0-11617'   => 'Poděbrady',
        '0-20000-0-11787' => 'Lysá hora',
        '0-20000-0-11653' => 'Sněžka, Poštovna',
        '0-20000-0-11457' => 'Churáňov',
        '0-20000-0-11464' => 'Milešovka',
        '0-203-0-11735'   => 'Praděd',
    ];

    /** URL 10min souboru stanice pro den (YYYYMMDD, UTC). */
    public static function stationUrl(string $wsi, string $ymd): string
    {
        return self::BASE_URL . '/data/10m-' . $wsi . '-' . $ymd . '.json';
    }

    /** URL metadat stanic pro den (YYYYMMDD, UTC). */
    public static function metaUrl(string $ymd): string
    {
        return self::BASE_URL . '/metadata/meta1-' . $ymd . '.json';
    }

    /** Dny (UTC) k vyzkoušení: dnešek, pak včerejšek (po půlnoci dnešní soubor chybí). */
    public static function dates(int $now): array
    {
        return [gmdate('Ymd', $now), gmdate('Ymd', $now - 86400)];
    }

    /**
     * Z dekódovaného meta1 vybere sledované stanice.
     * @return array<string, array{name: string, lat: float, lng: float, elevation: ?float}> WSI → info
     */
    public static function parseMeta(array $json): array
    {
        $rows = $json['data']['data']['values'] ?? null;
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || count($r) < 5) continue;
            $wsi = (string)$r[0];
            if (!isset(self::STATIONS[$wsi])) continue;
            $lon = $r[3]; $lat = $r[4];
            if (!is_numeric($lon) || !is_numeric($lat)) continue;
            // Některé stanice jsou v meta1 dvakrát (např. Olomouc, Klášterní Hradisko) — první vyhrává
            if (isset($out[$wsi])) continue;
            $name = trim((string)($r[2] ?? ''));
            $out[$wsi] = [
                'name'      => $name !== '' ? $name : self::STATIONS[$wsi],
                'lat'       => round((float)$lat, 6),
                'lng'       => round((float)$lon, 6),
                'elevation' => is_numeric($r[5] ?? null) ? (float)$r[5] : null,
            ];
        }
        return $out;
    }

    /**
     * Z dekódovaného 10min souboru stanice vybere poslední platný řádek T a H.
     * @return array{T?: array{value: float, ts: int}, H?: array{value: float, ts: int}}
     */
    public static function parseStation(array $json): array
    {
        $rows = $json['data']['data']['values'] ?? null;
        if (!is_array($rows)) return [];
        $latest = [];
        foreach ($rows as $r) {
            if (!is_array($r) || count($r) < 6) continue;
            $el = $r[1];
            if ($el !== 'T' && $el !== 'H') continue;
            $q = $r[5];
            if (!is_numeric($q) || !in_array((int)$q, self::GOOD_QUALITY, true)) continue;
            $val = $r[3];
            if ($val === null || $val === '' || !is_numeric($val)) continue;
            $val = (float)$val;
            if ($el === 'T' && ($val < self::T_MIN || $val > self::T_MAX)) continue;
            if ($el === 'H' && ($val < self::H_MIN || $val > self::H_MAX)) continue;
            $ts = strtotime((string)$r[2]);
            if ($ts === false) continue;
            if (!isset($latest[$el]) || $ts > $latest[$el]['ts']) {
                $latest[$el] = ['value' => $val, 'ts' => $ts];
            }
        }
        return $latest;
    }

    /**
     * Složí výstup proxy: pro každou sledovanou stanici s čerstvým měřením jeden záznam.
     *
     * @param array<string, array> $meta       výsledek parseMeta (může být prázdný → záložní názvy, ale bez souřadnic stanice vypadne)
     * @param array<string, array> $readings   WSI → výsledek parseStation
     * @param int $now                         unix „teď" (parametr kvůli testům)
     * @return array<int, array<string, mixed>> seřazeno podle názvu
     */
    public static function build(array $meta, array $readings, int $now): array
    {
        $out = [];
        foreach (self::STATIONS as $wsi => $fallbackName) {
            $m = $meta[$wsi] ?? null;
            if ($m === null) continue; // bez souřadnic nemá na mapě co dělat
            $r = $readings[$wsi] ?? [];
            $t = $r['T'] ?? null;
            $h = $r['H'] ?? null;
            if ($t === null && $h === null) continue;

            // measured_at = novější z obou; když se liší (jeden prvek o 10 min pozadu),
            // je rozdíl ≤ 10 min — collect.php ukládá oba pod tímto časem.
            $ts = max($t['ts'] ?? 0, $h['ts'] ?? 0);
            $age = $now - $ts;
            if ($age > self::MAX_AGE_SEC) continue;

            // Když je jeden prvek výrazně starší než druhý (> 1 h), nenese aktuální stav → pryč
            if ($t !== null && $ts - $t['ts'] > 3600) $t = null;
            if ($h !== null && $ts - $h['ts'] > 3600) $h = null;

            $out[] = [
                'wsi'         => $wsi,
                'name'        => $m['name'] ?? $fallbackName,
                'lat'         => $m['lat'],
                'lng'         => $m['lng'],
                'elevation'   => $m['elevation'] ?? null,
                'temperature' => $t !== null ? round($t['value'], 1) : null,
                'humidity'    => $h !== null ? round($h['value'], 0) : null,
                'measured_at' => gmdate('c', $ts),
                'age_min'     => (int)floor(max(0, $age) / 60),
                'stale'       => $age > self::STALE_SEC,
            ];
        }
        usort($out, fn($a, $b) => strcoll($a['name'], $b['name']));
        return $out;
    }
}
