# 2.5-libcich — Lokální testování (sandbox)

> Stav: **implementováno a otestováno lokálně 12. 6. 2026. NIC NENASAZENO na produkci.**
> Záloha všeho před změnami: `_ZALOHA_2026-06-12/NEW-MAP-Senzorvzduchu-FULL-2026-06-12-v2.zip` (297 souborů včetně .git, ověřeno).

## Rychlý start

V kořeni `2.5-libcich` spusť:

```powershell
php -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -S 127.0.0.1:8123 -t www
```

a otevři:
- **http://127.0.0.1:8123/map/index.html** — hlavní mapa
- **http://127.0.0.1:8123/map/embed.html** — embed varianta

Pozn.: `curl.cainfo` je potřeba jen lokálně na Windows (PHP CLI nemá CA bundle → SSL chyba). Na Active24 to funguje bez toho.

## Co testovat (vše už prošlo, ale mrkni očima)

1. **Mapa na národním zoomu** — u Libčic (severně od Prahy) je jedna cluster bublina „45" s teal okrajem (#0E7C86).
2. **Legenda → řádek „Libčich"** — klik přepíná viditelnost vrstvy; ikonka **⌖** vpravo = „přiblížit na Libčice" (fitBounds).
3. **Zoom k Libčicím** — cluster se postupně rozpadá (22, 9, 4, 3…), od zoomu 15 jednotlivé senzory.
4. **Klik na senzor** — popup: PM2.5 hodnota, graf historie, PM10/PM1/teplota/vlhkost, „Zdroj: Projekt Libčich (libcich.cz)" s odkazem (přání admina).
5. **Ostatní 4 sítě beze změny** — SC/ČHMÚ/SCK/PA markery i toggly fungují jako dřív, žádný clustering.

## Diagnostika proxy

```
http://127.0.0.1:8123/libcich-proxy.php?debug=1          ← živá data (mode=aq)
http://127.0.0.1:8123/libcich-proxy.php?mode=aqi&debug=1 ← hodinové průměry pro kolektor
```
`debug=1` ukáže volané URL, HTTP kódy a vzorek senzorů (obchází cache).

## Test kolektoru (bez tokenu)

```powershell
php -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" tests\test_libcich_collect.php
```
Replikuje libcich blok z `collect.php` proti lokální proxy (server musí běžet). Druhé spuštění musí vypsat `inserted: 0` (deduplikace hodin). Ověřeno: 92 řádků (46 senzorů × PM2.5+PM10), dedupe OK.

Plný `collect.php` lokálně: do **lokální** `www/config.php` přidej
`define('LIBCICH_PROXY_URL', 'http://127.0.0.1:8123/libcich-proxy.php?mode=aqi');`
a zavolej `http://127.0.0.1:8123/historygraph/collect.php?token=<tvůj token>`. (Bez definice jde na produkční URL.)

## Co se změnilo proti 2.4

| Soubor | Změna |
|---|---|
| `www/libcich-proxy.php` | **NOVÝ** — 2 režimy (`?mode=aq`/`aqi`), cache 120/600 s, fallback na starou cache, `?debug=1` |
| `www/map/index.html` + `embed.html` | identicky: markercluster CDN (JS+CSS), vrstva `libcich` (cluster izolovaně), `fetchLibcichData()`, legenda + ⌖, větve v `createOrUpdateMarker` (teal okraj, atribuce s odkazem) |
| `www/historygraph/collect.php` | blok 5: libcich hodinové průměry s deduplikací per hodina; `LIBCICH_PROXY_URL` override pro lokální test |
| `www/historygraph/get_history.php` | regex whitelist: + `LIBCICH_[A-F0-9]{8,32}` |
| `.gitignore` | + `libcich_data_cache.json`, `libcich_aqi_cache.json` |
| všechny PHP | odstraněno `curl_close()` (deprecated v PHP 8.5, bez efektu od 8.0 — lokální PHP 8.5 jinak rozbíjelo JSON deprecation noticy) |

## Záludnosti API zjištěné při implementaci (ověřeno proti živému API)

- **Parametry `year/month/day/hour` jsou v LOKÁLNÍM čase (Europe/Prague), ale klíče intervalů v odpovědi jsou UTC.** Proxy počítá pražský čas.
- **Bucket aktuální hodiny obsahuje předvytvořený BUDOUCÍ hraniční interval** s placeholder záznamy (vše 0, prázdné devId, lat/lng 0). Proxy je přeskakuje — jinak by přepsaly reálná data nulami.
- `/aq`: pole `pm10_0`; `/aqi`: pole `pm10` (jiné názvy!).
- Senzory občas hlásí vlhkost 1 % nebo 99.9/100 % — surová data, zobrazujeme jak jsou.
- Poslední interval v `/aqi` je rozpracovaná hodina → kolektor bere poslední **uzavřenou** (klíč + 1 h ≤ teď).

## Až budeš spokojený → nasazení (FTP, ruční!)

Soubory k uploadu na Active24 (po finálním odsouhlasení, dle DEPLOYMENT_CHECKLIST):
1. `www/libcich-proxy.php` (nový)
2. `www/map/index.html` + `www/map/embed.html`
3. `www/historygraph/collect.php` + `www/historygraph/get_history.php`

`config.php` na produkci se NEMĚNÍ (žádný nový klíč). Historie se začne plnit po prvním cron běhu, plná po ~5 dnech. **`tests/` a `*.md` se nenahrávají.**
