# Nasazení 2.9 (AirGradient) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **NASAZENO 16. 10. 2026 ~16:00** (ruční FTP uživatelem, živě ověřeno — §7). 2.9 = kopie 2.8 + šestá síť
AirGradient. Původní verze změněných souborů: `backup-2.8.7/` (rychlý rollback).

## 1. Co se nahrává a k čemu to je

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `lib/AirGradient.php` | nový | filtr CZ + normalizace světového feedu (čistá logika) | ag-proxy.php, test |
| `ag-proxy.php` | nový | AirGradient → CZ podmnožina, cache 3 min, zámek, stale fallback | app.js, collect.php |
| `historygraph/collect.php` | změna | blok 4b: ukládá AG_ PM2.5/PM10 (jen online senzory) | cron (beze změny) |
| `historygraph/get_history.php` | změna | whitelist ID + `AG_\d+` | app.js (graf) |
| `map/app.js` | změna | síť airgradient, fetcher, šedý marker starého měření, CO₂ štítek, desetinná místa štítků | obě HTML |
| `map/interp.js` | změna | váha airgradient 0.10, stará měření mimo odhad | obě HTML |
| `map/info.js` | změna | řádek AirGradient v tabulce sítí + atribuce | obě HTML |
| `map/style.css` | změna | `.src-ag` (modrý okraj), `.popup-age` | obě HTML |
| `map/index.html`, `map/embed.html` | změna | řádek legendy AirGradient, meta „6 sítí", `?v=2.9` | návštěvník |

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`,
`backup-2.8.7/`, `tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

**Beze změny v 2.9** (nenahrávat znovu, na serveru už jsou z 2.8): `lib/Http.php` (ag-proxy ho
používá — ověřit, že na serveru je), `lib/Alerts.php`, `lib/Forecast.php`, `lib/SensorQuality.php`,
ostatní proxy, `admin/`, `map/wind.js`, `map/alerts.js`, `map/orp-areas.js`.

## 2. Postup

1. FTP nahrát v pořadí: `lib/AirGradient.php` → `ag-proxy.php` → `historygraph/get_history.php`
   + `collect.php` → `map/app.js`, `interp.js`, `info.js`, `style.css` → `map/index.html` + `embed.html`.
   Backend dřív než frontend, ať nový frontend nevolá neexistující endpoint.
2. Zkontrolovat (v prohlížeči):
   - `/ag-proxy.php` → `{"generated":…,"stale":false,"count":~23,"sensors":[…]}`, hlavička
     `X-Source: AirGradient-Live`, podruhé do 3 min `Local-Cache`
   - `/ag-proxy.php?debug=1` → `http_code: 200`, `world_count: ~2900`, `cz_count: ~23`
     (diagnostika bez zápisu cache; když `http_code` není 200, hosting nedosáhne na api.airgradient.com)
   - `/historygraph/get_history.php?id=AG_67175&hours=24&step=hour` → JSON (ne 400);
     data se plní od prvního běhu cronu, 24h graf je plný za den
   - `/map/` → v legendě řádek „AirGradient" (modrý okraj), ~23 markerů navíc hlavně kolem
     Prahy a Brna; popup: hero PM2.5, štítky PM10/PM1/Teplota/Vlhkost/CO₂, „Zdroj: AirGradient
     (CC BY-SA 4.0)" s odkazem na airgradient.com/map
   - `/map/embed.html` → totéž, interpolace výchozí vypnutá (2.8.7 beze změny)
   - modal ℹ → tabulka sítí má 6 řádků
3. Cron se **nemění** (`collect.php` každých 5 min si ag-proxy stáhne sám). Volitelný URL cron
   pro teplou cache (ag-proxy si ji jinak obnoví první návštěvník po 3 min):
   ```
   */3 * * * * curl -s "https://senzorvzduchu.cz/ag-proxy.php?token=TOKEN" > /dev/null
   ```
4. Po nasazení: `config.php` se **nemění** (AirGradient nepotřebuje klíč ani registraci).

## 3. Co proxy dělá a proč

- Zdroj `https://api.airgradient.com/public/api/v1/world/locations/measures/current` vrací
  **celý svět** (~2 900 senzorů, 1.5 MB gzip / ~10 MB bez komprese), bbox filtr API nemá.
  Proxy stahuje s `CURLOPT_ENCODING ''` (gzip), filtruje bbox ČR (lat 48.55–51.06, lon
  12.09–18.86) a **cachuje jen CZ výsledek** (~5 kB) do `cache/airgradient.json`.
- **Vyřazené ID** (v boxu, ale mimo ČR; `AirGradient::EXCLUDE_IDS`): 90098 Dingolfing,
  187808 Regenstauf, 193064 Dresden-Pappritz, 159894 Gbstr (DE), 182589 Rzuchów (PL),
  196458 Lietavská Lúčka (SK). Stejný přístup jako u PurpleAir (blacklist), aby zahraniční
  senzory neovlivňovaly interpolaci u hranic.
- **Stáří měření**: < 30 min = online (barevný marker); 30 min–2 h = v datech s
  `online=false` → **šedý marker**, popup „Senzor neaktualizuje — poslední měření před X",
  interpolace ho vynechá, collect.php ho neukládá (jinak by se stará hodnota zapisovala
  každých 5 min znovu → plochý průběh → falešný auto-příznak „stuck"); > 2 h nebo
  `offline=true` = vypuštěno. Senzory bez PM (jen CO₂/T/RH, např. 81353 Řeporyje) vypuštěny.
- **Hodnoty jsou surové** (Plantower PMS5003, bez korekce — veřejný feed `pm02_corrected`
  nemá). Proto váha v interpolaci 0.10 = stejná jako Sensor.Community, ne 0.25 jako
  korigovaný PurpleAir.
- **Licence CC BY-SA 4.0** → atribuce „AirGradient (CC BY-SA 4.0)" v popupu (Zdroj),
  v titulku řádku legendy a v modalu ℹ.
- Zátěž zdroje: 1 stažení / 3 min = max 20/h (bez tokenu, bez limitu v dokumentaci).

## 4. Databáze historie — nic se nepřepíše

- `air_quality_history.db` se nenahrává; schéma beze změny. `collect.php` jen přidává řádky
  `AG_<locationId>` s `value_type` PM2.5 a PM10 (hodnota > 0, jako u PurpleAir — PM2.5 0.0
  se neukládá). Retence 5 dní, cleanup časový, beze změny.
- Blokace: `admin/bans.php` umí blokovat i `AG_` ID (ruční zadání celého ID).

## 5. Rollback

Nahrát zpět soubory z `backup-2.8.7/` (`map/*`, `historygraph/*`) a smazat `ag-proxy.php`,
`lib/AirGradient.php`. Řádky `AG_` v DB nevadí (frontend 2.8 je nezná, vyprší za 5 dní).

## 6. Ověřeno lokálně 16. 10. 2026 (PHP built-in server, port 8129)

- `php tests/test_airgradient.php` → ALL PASS (21 kontrol).
- `/ag-proxy.php` živě: 2 916 záznamů světa → 23 CZ senzorů, 5.6 kB cache, podruhé Local-Cache.
- `/map/index.html`: 23 markerů airgradient, legenda, popup (hero, 5 štítků vč. CO₂, atribuce
  s odkazem), šedý marker + hláška stáří (simulováno `meta.stale`), embed stejné.
- Kolektor jeden běh: 22× PM2.5 + 23× PM10 pro AG_ (jeden senzor měl PM2.5 = 0 → přeskočen).

## 7. Ověřeno živě 16. 10. 2026 ~16:05 (po FTP)

- `/ag-proxy.php?debug=1` → `http_code 200`, `world_count 2922`, `cz_count 23` — hosting na
  api.airgradient.com dosáhne. `/ag-proxy.php` → `stale:false`, 23 senzorů, 10 kB, podruhé
  `X-Source: Local-Cache`.
- `/historygraph/get_history.php?id=AG_67175&hours=24&step=hour` → JSON (whitelist OK).
- Obě slupky: `?v=2.9` u style/app/interp/info, řádek legendy `airgradient`, meta „6 sítí";
  sdílené soubory na serveru obsahují nový kód.
- `/map/`: 23 markerů AirGradient, legenda s atribucí v titulku, popup Praha 8 Libeň (hero,
  štítky PM10/PM1/Teplota/Vlhkost/CO₂, „Zdroj: AirGradient (CC BY-SA 4.0)" s odkazem), žádný
  senzor stale. `/map/embed.html`: 23 senzorů, interpolace výchozí vypnutá.
- Poznámka (ne 2.9): produkční PHP vypisuje floaty s ~40 desetinnými místy
  (`serialize_precision`), stejně u `pa-proxy.php`. Funkčně neškodí (parseFloat), jen nafukuje
  JSON; případná oprava = `ini_set('serialize_precision', '-1')` v proxy.
