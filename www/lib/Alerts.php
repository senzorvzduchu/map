<?php
declare(strict_types=1);

/**
 * ČHMÚ weather / air-quality warnings (SIVS) from the CAP 1.2 open-data feed.
 * Pure functions — no I/O, no globals — so tests/test_alerts.php runs offline.
 * Ported verbatim from nosicheck.cz (src/Alerts.php); senzorvzduchu serves only cat=smog.
 *
 * Source: https://opendata.chmi.cz/meteorology/weather/alerts/cap/
 * The directory holds a rolling archive named alert_cap_50_<DDHHMM>.xml (no year
 * in the name, so the NEWEST is found by the index's Last-Modified column, never
 * by sorting filenames). Each file is a complete bulletin that supersedes the
 * previous one — there is no incremental feed to merge.
 *
 * Every bulletin also carries a "Žádná výstraha před …" (no warning for …) info
 * block for each of ČHMÚ's 18 phenomenon categories; those are severity Minor
 * and MUST be dropped, or the map would permanently claim 17 active warnings.
 *
 * Areas are ORP districts referenced by CISORP geocode only — the CAP has no
 * polygons. The matching geometry lives in www/map/orp-areas.js (copied from nosicheck.cz,
 * generated there from ČHMÚ's own warning map by bin/build_orp_areas.php).
 */
final class Alerts
{
    /** Open-data directory index (parsed for the newest bulletin). */
    public const INDEX_URL = 'https://opendata.chmi.cz/meteorology/weather/alerts/cap/';

    /**
     * Stable "current bulletin" URL behind ČHMÚ's own warning site. Used only
     * when the open-data index is unreachable or unparseable — same document,
     * different door.
     */
    public const FALLBACK_URL = 'https://vystrahy-cr.chmi.cz/data2/XOCZ50_OKPR.xml';

    /** Only the _50_ bulletins are full warnings; _70_ is the outlook-only run. */
    private const FILE_RE = '~<a href="(alert_cap_50_\d{6}\.xml)">[^<]*</a>\s+(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2})~';

    /** Longest description/instruction we keep — ČHMÚ texts run long. */
    private const TEXT_MAX = 1400;

    /**
     * Display priority when several warnings are in force at the same level.
     * Air quality wins: this is an air-pollution map, and a smog situation is the
     * one warning our own sensor layer can corroborate. Thunderstorms sit last —
     * every other app already shouts about those.
     */
    public const PRIORITY = [
        'smog'  => 90,
        'heat'  => 70,
        'cold'  => 65,
        'fire'  => 60,
        'wind'  => 50,
        'flood' => 45,
        'rain'  => 40,
        'snow'  => 35,
        'ice'   => 33,
        'fog'   => 25,
        'other' => 20,
        'storm' => 10,
    ];

    /**
     * Czech event name → category. ČHMÚ names the phenomenon in `event`; there is
     * no machine-readable category in the CAP (the SIVS eventCode is a bare roman
     * numeral with no published lookup). Order matters — the first hit wins, and
     * air-quality words are tested before the weather ones.
     */
    private const KEYWORDS = [
        // Bare stems, not "oxid dusičitý": ČHMÚ declines it ("koncentrace oxidu dusičitého").
        'smog'  => ['smog', 'regulac', 'ozón', 'ozon', 'prach', 'dusičit', 'siřičit', 'polétav', 'pm10'],
        'heat'  => ['vysoké teplot', 'vysokými teplot', 'horko', 'vedro'],
        // NOT a bare 'mraz': "námrazové jevy" contains it and belongs to `ice`.
        'cold'  => ['nízké teplot', 'nízkými teplot', 'mráz', 'mrazy', 'mrazů', 'mrazivé', 'pokles teplot', 'arktick'],
        'fire'  => ['požár'],
        'flood' => ['povodň', 'povodeň', 'vzestup hladin', 'dotok'],
        'storm' => ['bouř'],
        'rain'  => ['déšť', 'dešt', 'srážk'],
        'wind'  => ['vítr', 'větr', 'vichřic', 'orkán'],
        'snow'  => ['sníh', 'sněž', 'sněhov', 'vánic', 'závěj'],
        'ice'   => ['ledovk', 'náled', 'námraz', 'zmrazk'],
        'fog'   => ['mlha', 'mlhy', 'mlze'],
    ];

    /** ČHMÚ awareness levels 2/3/4 = yellow/orange/red; 1 (green) is "no warning". */
    private const SEVERITY_LEVEL = ['Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];

    /**
     * Directory index HTML → newest bulletin filename, or null when the listing
     * has no usable rows (format change, error page, empty archive).
     */
    public static function newestFromIndex(string $html): ?string
    {
        if (!preg_match_all(self::FILE_RE, $html, $m, PREG_SET_ORDER)) {
            return null;
        }
        $best = null;
        $bestTs = -1;
        foreach ($m as $row) {
            $ts = strtotime($row[2] . ' UTC');
            if ($ts !== false && $ts > $bestTs) {
                $bestTs = $ts;
                $best = $row[1];
            }
        }
        return $best;
    }

    /** Czech event name → one of the PRIORITY keys. */
    public static function categorize(string $event): string
    {
        $e = mb_strtolower($event, 'UTF-8');
        foreach (self::KEYWORDS as $cat => $words) {
            foreach ($words as $w) {
                if (mb_strpos($e, $w, 0, 'UTF-8') !== false) {
                    return $cat;
                }
            }
        }
        return 'other';
    }

    /**
     * CAP XML → list of real warnings (Czech info blocks only, "no warning" and
     * Minor blocks dropped). Returns null when the body is not a CAP alert — the
     * caller must treat that as a failed refresh and keep the previous cache.
     */
    public static function parseCap(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false || $doc->getName() !== 'alert') {
            return null;
        }

        $ns = $doc->getNamespaces()[''] ?? 'urn:oasis:names:tc:emergency:cap:1.2';
        $doc->registerXPathNamespace('cap', $ns);
        $infos = $doc->xpath('//cap:info');
        if ($infos === false) {
            return null;
        }

        $issued = self::isoOrNull((string)$doc->sent);
        $alerts = [];

        foreach ($infos as $info) {
            if ((string)$info->language !== 'cs') {
                continue;   // the bulletin repeats every block in en-GB
            }
            $event = trim((string)$info->event);
            if ($event === '' || self::isNoWarning($event)) {
                continue;
            }
            $severity = (string)$info->severity;
            $level = self::level($info, $severity);
            if ($level < 2) {
                continue;   // green = "no warning" said a second way
            }

            $expires = self::isoOrNull((string)$info->expires)
                ?? self::isoOrNull(self::parameter($info, 'eventEndingTime'));
            $onset = self::isoOrNull((string)$info->onset);

            [$regions, $orp] = self::areas($info);
            if ($orp === []) {
                continue;   // a warning we cannot place is a warning we cannot show
            }

            $alerts[] = [
                'id'        => substr(sha1($event . '|' . ($onset ?? '') . '|' . ($expires ?? '') . '|' . implode(',', $orp)), 0, 12),
                'event'     => $event,
                'cat'       => self::categorize($event),
                'level'     => $level,
                'severity'  => $severity,
                'onset'     => $onset,
                'expires'   => $expires,
                'desc'      => self::clip((string)$info->description),
                'instr'     => self::clip((string)$info->instruction),
                'situation' => self::clip(self::parameter($info, 'situation') ?? ''),
                'regions'   => $regions,
                'orp'       => $orp,
                'issued'    => $issued,
            ];
        }

        return $alerts;
    }

    /**
     * Drop warnings that have already ended and sort the rest for display:
     * highest level first, then already-in-force before upcoming, then category
     * priority. The client shows [0] in the banner and the whole list in detail.
     */
    public static function active(array $alerts, int $now): array
    {
        $live = [];
        foreach ($alerts as $a) {
            $exp = isset($a['expires']) ? strtotime((string)$a['expires']) : false;
            if ($exp !== false && $exp <= $now) {
                continue;
            }
            $onset = isset($a['onset']) ? strtotime((string)$a['onset']) : false;
            $a['inforce'] = ($onset === false || $onset <= $now);
            $live[] = $a;
        }

        usort($live, static function (array $x, array $y): int {
            return ($y['level'] <=> $x['level'])
                ?: (($y['inforce'] ? 1 : 0) <=> ($x['inforce'] ? 1 : 0))
                ?: ((self::PRIORITY[$y['cat']] ?? 0) <=> (self::PRIORITY[$x['cat']] ?? 0))
                ?: strcmp((string)$x['onset'], (string)$y['onset']);
        });

        return $live;
    }

    /**
     * Final cache payload served by api/alerts.php. $issued keeps the bulletin's
     * own publication time even when every warning in it has expired away.
     */
    public static function build(array $alerts, int $generatedUtc, string $source, ?string $issued = null): array
    {
        return [
            'generated' => gmdate('Y-m-d\TH:i:s\Z', $generatedUtc),
            'source'    => $source,
            'issued'    => $issued ?? ($alerts[0]['issued'] ?? null),
            'alerts'    => array_values($alerts),
        ];
    }

    /** "Žádná výstraha před deštěm" / "Žádný výhled nebezpečných jevů". */
    private static function isNoWarning(string $event): bool
    {
        return mb_strpos(mb_strtolower($event, 'UTF-8'), 'žádn', 0, 'UTF-8') === 0;
    }

    /** awareness_level "3; orange; Severe" → 3; falls back to the severity word. */
    private static function level(SimpleXMLElement $info, string $severity): int
    {
        $raw = self::parameter($info, 'awareness_level');
        if ($raw !== null && preg_match('/^\s*([1-4])\s*;/', $raw, $m)) {
            return (int)$m[1];
        }
        return self::SEVERITY_LEVEL[$severity] ?? 1;
    }

    /** First `parameter` with the given valueName, or null. */
    private static function parameter(SimpleXMLElement $info, string $name): ?string
    {
        foreach ($info->parameter as $p) {
            if ((string)$p->valueName === $name) {
                return (string)$p->value;
            }
        }
        return null;
    }

    /**
     * info/area[] → [region names, ORP codes].
     * areaDesc is "Plzeňský kraj" for a whole region and
     * "Plzeňský kraj (Domažlice, Tachov, …)" when only some districts are hit —
     * we keep the region name for the headline and the codes for the geometry.
     */
    private static function areas(SimpleXMLElement $info): array
    {
        $regions = [];
        $orp = [];
        foreach ($info->area as $area) {
            $desc = trim((string)$area->areaDesc);
            if ($desc !== '') {
                $short = trim(preg_replace('/\s*\(.*$/u', '', $desc) ?? $desc);
                if ($short !== '' && !in_array($short, $regions, true)) {
                    $regions[] = $short;
                }
            }
            foreach ($area->geocode as $g) {
                if ((string)$g->valueName !== 'CISORP') {
                    continue;
                }
                $code = (int)(string)$g->value;
                if ($code > 0) {
                    $orp[$code] = true;
                }
            }
        }
        $codes = array_keys($orp);
        sort($codes);
        return [$regions, $codes];
    }

    /** Collapse whitespace and cap length; empty string → null. */
    private static function clip(string $text): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return null;
        }
        return mb_strlen($t, 'UTF-8') > self::TEXT_MAX
            ? mb_substr($t, 0, self::TEXT_MAX - 1, 'UTF-8') . '…'
            : $t;
    }

    /** CAP local time "2026-08-21T16:00:00+02:00" → ISO UTC, or null. */
    private static function isoOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
