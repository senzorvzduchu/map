# Nasazení 3.1 (meteostanice ČHMÚ jako reference teploty a vlhkosti) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **SANDBOX, ověřeno lokálně 16. 10. 2026** (port 8131). 3.1 = kopie 3.0 (teplota a vlhkost
z komunitních senzorů, **nenasazeno**) + meteorologické stanice ČHMÚ z otevřených 10min dat jako
referenční síť pro teplotu a vlhkost. Původní verze změněných souborů: `backup-3.0/` (rychlý
rollback na 3.0). **Nasazuje se 3.0 a 3.1 najednou** — produkce je 2.9, 3.0 na serveru není, takže
soubory 3.1 už obsahují všechno z 3.0 (`DEPLOY_3.0.md` §1 platí dál, tady jsou jen rozdíly 3.1).

## 1. Co se nahrává a k čemu to je

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `lib/ChmiMeteo.php` | **nový** | čistá logika: seznam 60 stanic (`STATIONS`, WSI → záložní název), parsování meta1 a 10min souboru, výběr posledního platného řádku T/H (QUALITY 0 nebo 5), stáří, výstup | chmi-meteo-proxy.php, test |
| `lib/Http.php` | změna | `Http::fetchMany()` — paralelní stažení přes curl_multi (8 najednou) | chmi-meteo-proxy.php |
| `chmi-meteo-proxy.php` | **nový** | self-refreshing proxy: cache `cache/chmi_meteo.json` 15 min, zámek, stale fallback; metadata `cache/chmi_meteo_meta.json` 1× denně; `?token=` vynutí, `?debug=1` diagnostika | app.js, collect.php |
| `historygraph/collect.php` | změna (vůči 3.0) | blok **2b**: ukládá `temperature`/`humidity` stanic `CHMI_M_<WSI>` s `measured_at` ZE ZDROJE, dedupe podle časové značky (jako ČHMÚ PM) | cron (beze změny) |
| `historygraph/get_history.php` | změna (vůči 3.0) | whitelist ID + `CHMI_M_<WSI>` (pomlčky) | app.js (graf) |
| `map/app.js` | změna (vůči 3.0) | `fetchChmiMeteoData()` → záznamy sítě `chmi` jen s T/RH (`meta.meteoOnly`); u látek se nekreslí ani jako prázdné kolečko; zdroj v popupu „ČHMÚ, meteorologická stanice (oficiální), N m n. m."; hláška stáří „Stanice neaktualizuje" | obě HTML |
| `map/info.js` | změna (vůči 3.0) | ČHMÚ řádek: 60 meteostanic jako reference; odstavec Teplota a vlhkost (budka 2 m, 10 min, hodinová publikace); graf „meteostanice po 10 minutách"; lead „šest sítí" | obě HTML |
| `map/index.html`, `map/embed.html` | změna (vůči 3.0) | meta „teplota a vlhkost (reference: meteostanice ČHMÚ)", twitter „6 sítí", `title` řádku ČHMÚ v legendě, `app.js?v=3.1`, `info.js?v=3.1` | návštěvník |

Plus vše z 3.0 (`pa-proxy.php`, zbytek změn v `collect.php`/`get_history.php`/`app.js`/`info.js`/slupkách).

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`, `backup-3.0/`,
`tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

**Beze změny v 3.1**: `style.css` (`?v=3.0` zůstává), `interp.js`, `wind.js`, `alerts.js`,
`orp-areas.js`, `lib/Alerts.php`, `lib/Forecast.php`, `lib/SensorQuality.php`, `lib/AirGradient.php`,
ostatní proxy, `admin/`.

## 2. Postup

1. FTP nahrát v pořadí (backend dřív než frontend): `lib/Http.php` + `lib/ChmiMeteo.php` →
   `chmi-meteo-proxy.php` → `historygraph/collect.php` + `get_history.php` → `pa-proxy.php` (3.0) →
   `map/app.js`, `info.js` → `map/index.html` + `embed.html`.
2. Zkontrolovat (v prohlížeči):
   - `/chmi-meteo-proxy.php?debug=1` → `meta.http_code 200` (nebo `source: cache`), `with_reading`
     blízko `configured` (60), `missing: []`, `elapsed_s` pod ~5 s. **Tohle je klíčová kontrola**:
     hosting musí dosáhnout na `opendata.chmi.cz` (výhled 7.–8. 9. 2026 uvízl právě na dostupnosti
     opendata z Active24). Když `elapsed_s` leze k 20 s, snížit počet stanic nebo
     `CHMI_METEO_CONCURRENCY`.
   - `/chmi-meteo-proxy.php` → `{generated, stale:false, count:~60, stations:[…]}`, `X-Source:
     CHMI-Live`, podruhé `Local-Cache`. Vzniknou `cache/chmi_meteo.json`, `chmi_meteo_meta.json`,
     `chmi_meteo.lock` (složka `cache/` už je zapisovatelná).
   - `/historygraph/get_history.php?id=CHMI_M_0-20000-0-11519&type=temperature&hours=24&step=hour`
     → JSON (ne 400 „Invalid sensor ID format"), `"bucket":"avg"`. Body od prvního běhu cronu.
   - `/map/` → „🌡 Teplota": ~60 markerů s **černým okrajem** (meteostanice ČHMÚ) mezi ~160
     komunitními, stanice kvality ovzduší ČHMÚ pryč; klik na „Praha, Karlov" → hero teplota, štítek
     Vlhkost, zdroj „ČHMÚ, meteorologická stanice (oficiální), 261 m n. m.", graf „24 hodin".
     Zpět na PM2.5 → meteostanice zmizí (žádná prázdná kolečka navíc), ~97 stanic kvality ovzduší
     je zpět. „💧 Vlhkost" totéž.
   - `/map/embed.html` → totéž, Interpolace výchozí vypnutá.
   - modal ℹ → řádek ČHMÚ zmiňuje meteostanice, odstavec Teplota a vlhkost začíná referencí ČHMÚ.
3. Cron se **nemění** (collect.php po 5 min proxy sám obnoví; URL cron na
   `chmi-meteo-proxy.php?token=…` není potřeba — cache se obnoví při prvním požadavku po 15 min).
   `config.php` se **nemění**.

## 3. Rozhodnutí a proč

- **Podmnožina 60 stanic, ne všech ~810.** ČHMÚ publikuje jeden JSON na stanici a den (~80–150 kB,
  řádky se v něm hromadí od půlnoci UTC), žádný souhrnný soubor. 810 × 100 kB po 15 min = 80 MB
  na obnovu, to sdílený hosting neutáhne. 60 stanic = ~6 MB na obnovu, stažení paralelně (8
  najednou) trvá lokálně 0,5–0,9 s. Seznam je v `ChmiMeteo::STATIONS` (WSI → záložní název),
  přidání stanice = jeden řádek; strop držet kolem 60 (test to hlídá). Výběr: Praha 7 (Karlov,
  Libuš, Ruzyně, Kbely, Klementinum, Komořany, Vinohrady), Brno 2 (Tuřany, Žabovřesky), Ostrava 3
  (Poruba, Mošnov, Slezská Ostrava), Plzeň 2, Ústí n. L. 2, Karlovy Vary 2, ostatní krajská města
  po jedné, okresní a pánevní stanice (Tušimice, Kopisty…), horské a venkovské reference (Lysá hora,
  Sněžka, Churáňov, Milešovka, Praděd, Košetice). **Část stanic z meta1 10min soubor nemá** (404:
  Praha Suchdol, Brno Židenice, Chomutov, Mladá Boleslav, Třebíč, Hodonín, Náchod, F-M Místek) nebo
  v něm T/H není (Jablonec, Žďár — ~6 kB srážkoměrné) — nahrazeny sousedními; `?debug=1 → missing`
  to ukáže i do budoucna.
- **Obnova po 15 min, i když zdroj je 10min.** Soubor stanice se přegenerovává **jednou za hodinu
  v HH:01** s řádky do (HH−1):50 (ověřeno 16. 10. 2026: soubor z 15:01Z končí 14:50Z a v 15:52Z je
  pořád stejný). Hodnota na mapě je tedy 10–85 min stará; 15min TTL to zachytí do čtvrt hodiny po
  publikaci a nestahuje zbytečně. `stale` (šedý marker) až po **2 h** — běžné hodinové zpoždění
  není výpadek. Starší než 3 h se vypouští.
- **QUALITY 0 a 5 = platné.** Číselník ČHMÚ (`meta4`): 0 Good, 1 Suspect, 2 Poor, 3 Estimated,
  4 Missing, 5 Unknown. Real-time řádky mají prakticky vždy 5 (kontrola kvality běží zpětně), proto
  „good" = {0, 5}; 1–4 se přeskočí a bere se poslední platný řádek.
- **Síť `chmi`, ne nová síť.** Stanice patří ČHMÚ, mají stejný černý okraj a stejný řádek legendy
  (vypnutí „ČHMÚ (oficiální)" skryje obojí). Záznam nese **jen** teplotu a vlhkost a `meta.meteoOnly`,
  proto se u látek nekreslí vůbec (prázdné kolečko je jen pro stanice kvality ovzduší, které danou
  látku neměří) a stanice kvality ovzduší zůstávají u meteo vrstev skryté (pravidlo 3.0). ID
  `CHMI_M_<WSI>` (WSI má pomlčky, whitelist v `get_history.php` rozšířen) — jiný prostor než
  `CHMI_<LocalityCode>` kvality ovzduší, takže se nikdy nesrazí.
- **Bez interpolace i v 3.1.** Komunitní čidla v krytu na slunci jdou o 5–25 °C výš (16. 10. 2026
  16:30: ČHMÚ Praha 14–18 °C, komunitní max 44,9 °C); interpolace jen z 60 stanic ČHMÚ by byla
  řídká a smíchaná s komunitními chybná. Reference je teď aspoň vidět vedle komunitních hodnot
  — ověření ohřevu krytů proti nejbližší stanici ČHMÚ je možný další krok (TODO).
- **`measured_at` ze zdroje + dedupe v kolektoru.** Zdroj publikuje hodinově, cron běží po 5 min
  → bez dedupe 12 stejných řádků za hodinu (stejný problém jako ČHMÚ PM před 2.6). Blok 2b bere
  čas z `measured_at` a stejnou značku přeskočí. T a H mohou mít různý čas (jeden prvek o 10 min
  pozadu); výstup nese novější z nich a oba se ukládají pod ním; prvek starší o víc než 1 h se
  vypustí.
- **Metadata 1× denně.** `meta1-<den>.json` (~75 kB) nese souřadnice; mění se zřídka. Při výpadku
  se použijí stará metadata; bez metadat proxy vrátí starou cache se `stale:true` (stanice bez
  souřadnic na mapu nepatří). Po půlnoci UTC dnešní soubory chvíli neexistují → zkusí se včerejšek.

## 4. Zátěž a databáze

| Co | Kolik |
|---|---|
| Stažení z opendata.chmi.cz | 60 souborů × 80–150 kB = ~6–9 MB na obnovu, max 4 obnovy/h (~25–35 MB/h, ~0,7 GB/den); meta1 75 kB 1× denně; bez gzip (server ho nenabízí) |
| Doba obnovy | lokálně 0,5–0,9 s (8 paralelně, timeout 12 s/soubor); na Active24 ověřit `elapsed_s` v `?debug=1` |
| Výstup proxy | ~12 kB JSON, cache 15 min; app.js ho čte po 5 min (z cache) |
| DB historie | +120 řádků/h (60 stanic × T + H, dedupe per hodina publikace) = ~2 900/den, ~14 400 za 5denní retenci — zanedbatelné proti ~135 k/den komunitní meteo z 3.0 |

Schéma DB beze změny. ID `CHMI_M_…` v `admin/bans.php` nejde blokovat (ČHMÚ se neblokuje), auto-příznaky
se na ně nepouštějí (nejsou PM).

## 5. Rollback

- Na **3.0**: nahrát zpět soubory z `backup-3.0/` (`map/app.js`, `info.js`, `index.html`,
  `embed.html`, `historygraph/collect.php`, `get_history.php`); `chmi-meteo-proxy.php`,
  `lib/ChmiMeteo.php` a `cache/chmi_meteo*.json` mohou na serveru zůstat (nikdo je nevolá);
  `lib/Http.php` z 3.1 je zpětně kompatibilní (jen přidaná metoda).
- Na **2.9** (produkce před 3.0): podle `DEPLOY_3.0.md` §5 (`backup-2.9/` je v `3.0-meteo/`).
- Řádky `CHMI_M_…` v DB nevadí (starší frontend je nezná, vyprší za 5 dní).

## 6. Ověřeno lokálně 16. 10. 2026 (PHP 8.5 built-in server, port 8131)

- `php tests/test_chmi_meteo.php` → ALL PASS (33 kontrol: parsování meta1 vč. duplicitních řádků,
  výběr posledního řádku podle DT, QUALITY 1–4 a hodnoty mimo meze přeskočeny, stáří/stale, prvek
  o > 1 h starší vypuštěn, řazení, URL, dny UTC, seznam 60 unikátních WSI).
- `/chmi-meteo-proxy.php`: první volání `X-Source: CHMI-Live`, `count 60`, `elapsed_s 0.63–0.86`;
  druhé `Local-Cache`. `?debug=1`: `configured 60, with_reading 60, missing []`, meta z cache.
  Teploty 8,3–20 °C (Sněžka–Brno), stáří 60 min pro všechny (soubor 15:01Z, řádky do 14:50Z).
- Kolektor dva běhy (`PROXY_BASE_URL=http://127.0.0.1:8131`): 1. běh 1 195 řádků (CHMI_M_ 48 + 48,
  cache ještě před rozšířením seznamu), 2. běh po vynucené obnově 694 řádků — CHMI_M_ přibylo jen
  12 nových stanic × 2, stávající časová značka deduplikována → v DB 60 + 60 řádků, 1 na stanici.
- `get_history.php?id=CHMI_M_0-20000-0-11519&type=temperature&hours=24&step=hour` → 24 slotů,
  `bucket avg`; `type=humidity` surově 1 bod, `quality.stale false`.
- `/map/index.html` PM2.5: 97 stanic kvality ovzduší viditelných, 0 meteostanic (ani prázdných
  koleček). Teplota: 60 meteostanic (černý okraj, weight 2) + 72 SC + 43 Libčich + 23 AG + 11 SCK
  + 8 PA, 0 stanic kvality ovzduší, Interpolace zešedlá, legenda „Teplota (°C)". Popup „Praha,
  Karlov": hero „16.0 °C / Teplota vzduchu", štítek „Vlhkost 66 %", zdroj „ČHMÚ, meteorologická
  stanice (oficiální), 261 m n. m.", graf „24 hodin" (24 slotů, zatím 1 bod), „5 dní".
- `/map/embed.html`: Teplota 60 meteostanic + 0 AQ; PM2.5 → 0 meteo / 97 AQ; Vlhkost 60 meteo,
  222 markerů celkem; Interpolace výchozí vypnutá. Konzole bez chyb z app.js (jeden 404 na externí
  zdroj jako v 3.0).

## 7. Ověřeno živě (po FTP)

- (doplnit po nasazení: `?debug=1` z hostingu — `http_code`, `elapsed_s`, `missing`; počet markerů;
  popup; po dni 24h graf meteostanice plný = 24 bodů)
