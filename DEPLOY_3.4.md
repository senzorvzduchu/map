# Nasazení 3.4 (referenční stanice jiných provozovatelů) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **NASAZENO 17. 10. 2026 ~20:25 (ruční FTP uživatelem), živě ověřeno ~20:30 — §7.** Lokálně ověřeno předtím (server `ref-sandbox`, port 8134, §6). **3.4.1 (20:35): titulek záložky „Mapa - Senzorvzduchu“ v obou slupkách — nahráno uživatelem ~20:50, živě ověřeno (md5 shodné, záložka prohlížeče „Mapa - Senzorvzduchu“).** 3.4 = kopie 3.3
(produkce od 17. 10. 2026 16:20) + stanice kvality ovzduší **jiných provozovatelů než ČHMÚ** (zdravotní
ústavy, kraje, města, podniky — Ostrava-Radvanice ZÚ i OZO, Mariánské Hory, Hrušov, Ostrava-Jih, Havířov,
Karviná, Chotěbuz, Jablunkov, Metylovice, Třinec-Kanada, 4 stanice města Brna, Praha 10-Šrobárova, Letiště
Praha, Hradec Králové, Kolín, Kralupy, Klatovy, Strakonice, České Budějovice, Ústí n.L., Jihlava, Žďár,
Pelhřimov, Hodonín, Zlín…). Otevřená data ČHMÚ je nemají (98 lokalit, vše vlastník ČHMÚ); zdrojem je
nedokumentovaný JSON backend map na chmi.cz (`data-provider.chmi.cz`), licence CC BY 4.0 podle patičky
chmi.cz. Pozadí a čísla: `../SCOPE_3.4-ref-stations.md`, inventář `../SCOPE_3.4-stations.json`.
Původní verze změněných souborů: `backup-3.3/` (rychlý rollback).

## 1. Co se nahrává

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `lib/ChmiOther.php` | nový | seznam 40 stanic (kód → název, provozovatel, WGS84), výběr slotu, parsování vrstev („18,0“ → 18.0, „N/A“ → null, plyny 0,0 → null), sloučení dvou slotů | chmi-other-proxy.php, test |
| `chmi-other-proxy.php` | nový | 4 hodinové vrstvy (PM10, PM2_5, NO2, O3) pro poslední existující slot + slot o hodinu starší → `cache/chmi_other.json`, TTL 10 min, zámek, stale fallback, `?debug=1`, `?token=` | app.js, collect.php |
| `historygraph/collect.php` | změna | blok 2c: ukládá `CHMI_<kód>` PM2.5/PM10/NO2/O3 s `measured_at` = začátek hodiny (per látka), dedupe per hodina, nic staršího než 4 h | cron (beze změny) |
| `historygraph/monitor.php` | změna | když DB žije, ale poslední slot v `cache/chmi_other.json` je starší než 3 h → ntfy (zdroj zřejmě změnil tvar/adresu) | cron (beze změny) |
| `map/app.js` | změna | `fetchChmiOtherData()` → `upsertSensor('CHMI_'+kód, 'chmi', …, {owner})`; popup „Zdroj: ČHMÚ (Oficiální), provozovatel stanice: …“; stale → šedý marker + „Stanice neaktualizuje“ | obě HTML |
| `map/info.js` | změna | řádek ČHMÚ v tabulce sítí: věta o stanicích jiných provozovatelů + „Data: ČHMÚ, licence CC BY 4.0“ | obě HTML |
| `map/index.html`, `map/embed.html` | změna | `app.js?v=3.4`, `info.js?v=3.4`, tooltip řádku legendy ČHMÚ | návštěvník |

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`, `backup-3.3/`,
`tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

**Beze změny v 3.4** (na serveru už jsou z 3.3): `lib/Http.php` (proxy ho používá — `Http::fetch`,
`fetchMany`, `tryLock`, `atomicWrite`, `readJson`), `historygraph/get_history.php` (whitelist
`CHMI_[A-Z0-9_]+` kódy pustí, stanice je „hourly“ jako ostatní CHMI_), `map/interp.js` (síť `chmi`
váha 1.0 platí i pro tyto stanice), `map/style.css`, `lib/SensorQuality.php` (CHMI_ se neblokují ani
neoznačují), `admin/`, ostatní proxy. Žádná nová síť v legendě — stanice patří do řádku „ČHMÚ (oficiální)“.
`config.php` se nemění (zdroj nepotřebuje klíč). Cron se nemění (`collect.php` si proxy stáhne sám).

## 2. Postup

1. FTP nahrát v pořadí: `lib/ChmiOther.php` → `chmi-other-proxy.php` → `historygraph/collect.php` +
   `monitor.php` → `map/app.js`, `info.js` → `map/index.html` + `embed.html`. Backend dřív než frontend,
   ať nový `app.js` nevolá neexistující endpoint (zatím by to jen zalogovalo chybu a mapa jela dál).
2. Zkontrolovat (v prohlížeči):
   - `/chmi-other-proxy.php?debug=1` → `slot: "<YYYYMMDDHH00>"` (poslední celá hodina, v první části
     hodiny o 1 h starší), `count: ~30`, `with_pm25: ~28`, `with_pm10: ~29`, `elapsed_ms` do ~2 000,
     `diag.probe` = první slot 404 a druhý 200 nebo rovnou 200, `diag.layers` 8 řádků s `http: 200`.
     `missing` = stanice, které dnes hlásí jen N/A (17. 10. 2026: ARER, BMOK, BMOC, MLOS, MPHR, MSMS,
     MVBY, ZOTM, ZROZ, TNSV) — to je stav zdroje, ne chyba.
   - `/chmi-other-proxy.php` → `{"generated":…,"slot":…,"stale":false,"count":~30,"stations":[…]}`,
     hlavička `X-Source: CHMI-Live`, podruhé do 10 min `Local-Cache`. Když zdroj vypadne:
     `X-Source: Stale-Cache` a `stale: true`; bez cache 502 (mapa pak jen nemá těchto 30 stanic).
   - `/map/` → kolem Ostravy nové černě orámované stanice (Radvanice ZÚ 49.807 N 18.339 E, Radvanice OZO,
     Mariánské Hory, Hrušov, Ostrava-Jih), v Brně Svatoplukova/Lány/Komárov/Arboretum; popup „Zdroj: ČHMÚ
     (Oficiální), provozovatel stanice: Zdravotní ústav Ostrava / město Ostrava“. Konzola bez chyb
     `CHMI Other Data Error`. V konzoli: `[...sensorStore.values()].filter(r => r.meta && r.meta.owner).length`
     → ~30.
   - `/map/embed.html` → totéž (stejný `app.js`).
   - ℹ modal → řádek ČHMÚ končí „Data: ČHMÚ, licence CC BY 4.0.“
   - `/historygraph/get_history.php?id=CHMI_TORD&type=PM2.5&hours=24&step=hour` → JSON (ne 400);
     body přibývají od prvního běhu cronu (1 bod za hodinu), 24h graf je plný za den.
3. Po prvním běhu cronu (do 15 min): `/historygraph/get_history.php?id=CHMI_TORD&type=PM10&hours=24&step=hour`
   má alespoň jeden nenulový bod v poslední hodině. Pak `/historygraph/monitor.php?token=…` → „OK“.
4. Volitelný URL cron pro teplou cache (jinak ji obnoví první návštěvník nebo `collect.php` po 15 min):
   ```
   */10 * * * * curl -s "https://senzorvzduchu.cz/chmi-other-proxy.php?token=TOKEN" > /dev/null
   ```

## 3. Co proxy dělá a proč

- Zdroj: `https://data-provider.chmi.cz/api/data/ovzdusi/stanice/mereni/{PM10|PM2_5|NO2|O3}/1h/{YYYYMMDDHHMM}`
  = GeoJSON všech 258 stanic ISKO (CZ + SK + AT) pro jeden hodinový slot; `properties.id` = kód ISKO,
  `value` = řetězec s desetinnou čárkou nebo `"N/A"`. **Slot je konec průměrovací hodiny (UTC)**:
  slot `…1700` nese totéž co řádek opendata CSV se `startTime 16:00Z` (ověřeno 17. 10. 2026 na šesti
  stanicích ČHMÚ), proto `measured_at` = slot − 1 h — stejná konvence jako u `CHMI_` z opendata, takže
  grafy a dedupe sedí. Slot H se objeví ~50 min po celé hodině; proxy zkouší H, H−1, H−2, H−3 (GET PM10,
  404 = ještě není) a první existující bere.
- Ke slotu S stahuje i S−1: stanice, která v S hlásí N/A (hodinu ještě nedopočítala, analyzátor stojí),
  dostane poslední známou hodnotu se svým vlastním časem (`value_times`), takže NO₂ může být o hodinu
  starší než PM — collect.php ukládá každou látku pod její hodinu. 8 požadavků × ~100 kB paralelně
  (`Http::fetchMany`), lokálně 367 ms, z hostingu počítat do ~2 s. Souřadnice vrstvy jsou UTM 33N a
  nepoužívají se — WGS84 je v `ChmiOther::STATIONS` z `ovzdusi.chmi.cz/lokalita.php`.
- Whitelist `STATIONS` zároveň odfiltruje 88 zahraničních (SK/AT) a 98 stanic ČHMÚ, které už jdou
  z opendata; překryv kódů je nulový (ověřeno), žádná deduplikace není třeba.
- Plyny s hodnotou přesně `0,0` se berou jako výpadek analyzátoru (Zlín-ZŠ Kvítkova hlásil NO₂ i O₃ 0,0,
  PM normálně) → null; nula u PM zůstává. Nejnovější hodnota starší než 3 h → `stale: true` → šedý marker
  a hláška „Stanice neaktualizuje“ (stejná cesta jako u AirGradientu); collect.php nic staršího než 4 h neuloží.
- Riziko: API není dokumentované a může se změnit bez ohlášení (jako v roce 2025 zmizel `web_generator`).
  Proto stale fallback v proxy, hlídač v `monitor.php` (slot starší než 3 h → ntfy s odkazem na `?debug=1`)
  a offline test `tests/test_chmi_other.php` (53 kontrol nad uloženými vrstvami ze 17. 10. 2026 17:00 UTC
  v `tests/fixtures/chmi_other_*_202610171700.json`).
- Záložní zdroj pro stanice ZÚ Ostrava (17 stanic, hodinové hodnoty vložené v HTML grafu, bez API a bez
  licence) je popsán v `../SCOPE_3.4-ref-stations.md` §2 C — nepoužívat bez dohody se ZÚ.

## 4. Rollback

Nahrát zpět `backup-3.3/` (`map/app.js`, `map/info.js`, `map/index.html`, `map/embed.html`,
`historygraph/collect.php`, `historygraph/monitor.php`) a smazat `chmi-other-proxy.php`, `lib/ChmiOther.php`.
Řádky `CHMI_<kód>` v DB nevadí (po 5 dnech je smaže retence); `cache/chmi_other.json` lze smazat.

## 5. Známé limity a co sledovat

- Stanice ORGREZ (8) a VUHU (6) v Ústeckém kraji, Hošťalovice a Křešín u Pacova se v hodinových vrstvách
  ČHMÚ neobjevují vůbec (zřejmě nejsou přenášeny hodinově) — nejsou v seznamu. Manuální stanice (Kladno-Vrapice,
  Buštěhrad, Veltrusy…) hodinová data nemají.
- Ústí n.L.-Pasteurova hlásila 17. 10. PM10 3,1 / PM2.5 1,0 při 20–40 jinde — po týdnu sběru posoudit;
  CHMI_ se v adminu blokovat nedají (`SensorQuality::normalizeId`), případně vyřadit ze `STATIONS`.
- Ve vrstvě jsou i 42 stanic SHMÚ a ~46 rakouských — rozšíření whitelistu + převod UTM→WGS84 (nebo souřadnice
  z EEA) by přidalo příhraniční referenci pro interpolaci; otevřené téma spolu s GIOŚ (PL) a UBA (DE).
- Text v ℹ modalu uvádí „~90 stanic ČHMÚ“ + stanice jiných provozovatelů; `og:description` zůstává „6 sítí“
  (žádná nová síť).
- Doporučeno (neblokuje nasazení): e-mail ZÚ Ostrava (`podatelna@zuova.cz`, správkyně stanic
  `hana.miturova@zuova.cz`) a ČHMÚ (`chmi@chmi.cz`) — informace o zobrazení, potvrzení CC BY pro
  `data-provider`, dotaz na doplnění ostatních vlastníků do `opendata.chmi.cz`.

## 6. Ověřeno lokálně (17. 10. 2026 ~20:15–20:30, port 8134)

- `php tests/test_chmi_other.php` → ALL PASS (53 kontrol: sloty přes půlnoc, čárka/tečka/N/A/0,0/meze,
  reálné vrstvy PM10 38 / NO2 26 / O3 9 sledovaných stanic, sloučení 30 stanic, fallback z předchozího
  slotu s vlastním časem, stale po 3 h, 40 stanic v tabulce s CZ souřadnicemi).
- `/chmi-other-proxy.php?debug=1` z lokálu: probe `202610171800` 404 → `202610171700` 200 (206 features),
  8 vrstev 200, `count 30`, `with_pm25 28`, `with_pm10 29`, `elapsed_ms 367`; živý výdej `X-Source: CHMI-Live`,
  druhý `Local-Cache`; `cache/chmi_other.json` 9,4 kB.
- Mapa: `sensorStore` 373 záznamů, síť `chmi` 189 = 98 opendata + 61 meteo + **30 jiných provozovatelů**;
  `CHMI_TORD` Ostrava-Radvanice ZÚ marker + popup „13.0 µg/m³ PM2.5 … PM10 18.0, O₃ 41.9 … Zdroj: ČHMÚ
  (Oficiální), provozovatel stanice: Zdravotní ústav Ostrava / město Ostrava“; TORO/TOMH/TOHU/TOSJ markery
  vedle TOFF (opendata). Konzola bez chyb fetcherů. Embed: `app.js?v=3.4`, `info.js?v=3.4`, tooltip legendy.
- `collect.php` z CLI proti lokálním proxy (`PROXY_BASE_URL`): 1. běh 1 301 řádků, `CHMI_TORD` PM2.5 13 /
  PM10 18 / O3 41.9 s `measured_at 16:00Z` = stejná hodina jako `CHMI_TOFF` z opendata; 2. běh bez
  duplicit (TORD PM10 1 řádek). `get_history.php?id=CHMI_TORD&type=PM10` → JSON 24 slotů.
- `monitor.php`: s čerstvou cache „OK“; se stubem ntfy a cache 5 h starou hláška „Other-owner stations
  (data-provider.chmi.cz) stale for 5 h …“ (větev se spustí jen když je DB sama čerstvá).

## 7. Ověřeno živě (17. 10. 2026 ~20:27–20:45 UTC+2, po FTP uživatelem)

- `/chmi-other-proxy.php?debug=1` z hostingu: probe `202610171800` 404 → `202610171700` 200 (206 features),
  8 vrstev 200, `count 30`, `with_pm25 28`, `with_pm10 29`, `stale_count 0`, **`elapsed_ms 1855`** (odhad „do 2 s“ sedí),
  `missing` = 10 stanic hlásících jen N/A (stav zdroje).
- `/chmi-other-proxy.php`: `X-Source: CHMI-Live`, `count 30`, `slot 202610171700`, `stale false`; TORD 18/13/41.9,
  TORO 52/43/31.6/38.3, BBMS 30.8/12.9/52.4, `measured_at 16:00Z`; druhé volání `X-Source: Local-Cache`.
- Nahrané soubory = lokální 3.4 (md5 shodné: `app.js`, `info.js`, `index.html`, `embed.html`); obě slupky
  `app.js?v=3.4`, `info.js?v=3.4`.
- `/map/`: `sensorStore` 369, síť `chmi` 188 = 98 opendata + 60 meteo + **30 jiných provozovatelů**; marker
  `CHMI_TORD`, popup „Ostrava-Radvanice ZÚ 13.0 µg/m³ PM2.5 … PM10 18.0, O₃ 41.9 … Zdroj: ČHMÚ (Oficiální),
  provozovatel stanice: Zdravotní ústav Ostrava / město Ostrava“; konzola bez chyb. ℹ modal: řádek ČHMÚ s větou
  o jiných provozovatelích a „Data: ČHMÚ, licence CC BY 4.0.“ `/map/embed.html`: totéž, 30 stanic.
- `get_history.php?id=CHMI_TORD&type=PM10`: JSON; **první běh cronu po nahrání (18:30 UTC) uložil PM10 18 a PM2.5 13 do
  hodiny 16:00Z (v grafu 18:00 SELČ)** — stejná hodina jako stanice ČHMÚ z opendata; graf se plní 1 bod/h.
- **3.4.1 (20:35)**: na přání uživatele `<title>` obou slupek „Mapa Znečištění - Senzorvzduchu.cz“ → **„Mapa - Senzorvzduchu“**
  (`og:title`/`twitter:title` beze změny). Nahráno ~20:50, živě ověřeno (md5 obou slupek shodné s lokálem, titulek záložky).
