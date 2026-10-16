# 3.0 — teplota a vlhkost (sandbox, ověřeno lokálně 16. 10. 2026)

Výchozí bod: `2.9-airgradient` (LIVE od 16. 10. 2026). Původní verze změněných souborů:
`backup-2.9/`. Runbook: `DEPLOY_3.0.md`.

## Změněné soubory (3.0, 16. 10. 2026)

| Soubor | Změna |
|---|---|
| `www/historygraph/collect.php` | `insertMeteo()` (meze −40…60 °C, 0…100 %); SC: teplota/vlhkost podle `location.id` + jeden záznam na senzor a běh (`$seenSc`, dřív 2× stejný čas); SCK ID 55/56; PA; AG (jen online); blok **5b Libčich** z výchozího režimu proxy (2min, dedupe `measured_at`) |
| `www/historygraph/get_history.php` | whitelist `temperature`, `humidity`; hodinový slot AVG (látky MAX), `bucket: avg`; heuristiky stuck/plateau se na meteo nepouštějí |
| `www/pa-proxy.php` | teplota −8 °F, vlhkost +4 % (US EPA offset čidla PurpleAir), až PO Barkjohn korekci PM2.5 |
| `www/map/app.js` | `TEMP_COLORS`/`HUM_COLORS`; registr `temperature` (pásma 0/10/20/25/30, `openLow`, `valid`) + `humidity` (30/50/70/85/95, `decimals: 0`), oba `interp:false, meteo:true`; `plausible()` (marker, štítek, cluster Libčich); `colorFor` + legenda berou `cfg.colors`; ČHMÚ u meteo skryto; hero/tooltip `cfg.decimals`; graf bez výhledu, jednobarevná výplň, osa bez nuly; `updatePollutantChips()` posune aktivní čip do záběru |
| `www/map/info.js` | odstavec „Teplota a vlhkost" (čidlo v krytu, PA korekce, 100 % = orosené), věta o barvách, průměr v grafu, ČHMÚ skryto, „neinterpolují / nepředpovídají" |
| `www/map/index.html`, `embed.html` | čipy `temperature`, `humidity`; `aria-label` „Výběr veličiny"; meta „…hluk, teplota a vlhkost…"; `?v=3.0` |
| `.claude/launch.json` (kořen) | `meteo-sandbox`, port 8130 |

Rozhodnutí a důvody v `DEPLOY_3.0.md` §3 (plná vrstva místo „jen graf", vlastní barvy, bez
interpolace kvůli ohřevu krytů, ČHMÚ skryto — otevřená data ČHMÚ mají 10min T/RH pro ~950
stanic, ale jeden soubor na stanici a den).

---

# 2.9 — AirGradient (nasazeno 16. 10. 2026)

Výchozí bod: `2.8-multipollutant` (LIVE 2.8.7 od 9. 9. 2026). Původní verze změněných souborů:
`backup-2.8.7/`. Runbook: `DEPLOY_2.9.md`.

## Změněné / nové soubory (2.9, 16. 10. 2026)

| Soubor | Změna |
|---|---|
| `www/lib/AirGradient.php` | **NOVÝ** — `AirGradient::normalise($world, $now)`: bbox ČR, vyřazená ID (6 DE/PL/SK), `offline`, stáří > 2 h, bez PM → pryč; výstup ve tvaru pa-proxy (+ `pm1`, `co2`, `model`, `measured_at`, `age_min`, `online`) |
| `www/ag-proxy.php` | **NOVÝ** — self-refreshing proxy (cache 3 min `cache/airgradient.json`, flock, stale fallback, `?token=` vynutí, `?debug=1`); stahuje světový feed gzipem, cachuje jen CZ |
| `www/historygraph/collect.php` | blok **4b AirGradient**: `AG_<id>` PM2.5/PM10, jen `online=true` |
| `www/historygraph/get_history.php` | whitelist ID + `AG_\d+` |
| `www/map/app.js` | `NETWORK_LABELS.airgradient` (odkaz + CC BY-SA 4.0), vrstva `airgradient`, `fetchAirGradientData()`, `upsertSensor(…, meta)` — `meta.stale` → šedý marker + `.popup-age` se stářím; `VALUE_LABELS.co2`; třetí prvek VALUE_LABELS = desetinná místa štítku (vlhkost, tlak, CO₂ bez desetin) |
| `www/map/interp.js` | `TRUST.airgradient = 0.10`; `rec.meta.stale` vynechán z odhadu |
| `www/map/info.js` | řádek AirGradient v tabulce sítí (atribuce, šedý = bez měření 30 min), text vah |
| `www/map/style.css` | `.src-ag` (okraj #1E40AF), `.popup-age` |
| `www/map/index.html`, `embed.html` | řádek legendy `data-layer="airgradient"` (title = atribuce), meta „6 sítí … AirGradient", `?v=2.9` u style/app/interp/info |
| `tests/test_airgradient.php` | **NOVÝ** — offline test normalizace (ALL PASS) |

Rozhodnutí: hraniční senzory vyřazeny podle ID (jako PurpleAir); surové PM bez korekce → váha 0.10
(jako Sensor.Community); stará měření 30 min–2 h se ukazují šedě a nevstupují do interpolace ani
historie. Podrobnosti a důvody v `DEPLOY_2.9.md` §3.

---

# 2.8 — multipollutant (test, nenasazeno)

Výchozí bod: `2.7-carto-key` (LIVE 4. 9. 2026). Poznámky k 2.6 (ČHMÚ staleness) jsou
ve složce `2.6-chmi-staleness-fix/MIGRATION.md`.

## Změněné / nové soubory

| Soubor | Změna |
|---|---|
| `www/map/index.html`, `www/map/embed.html` | **přepsány** na tenké slupky: lišta látek, legenda (Vítr, Interpolace, podklad), kontejner výstrahy, modal, toast; JS/CSS externě |
| `www/map/style.css` | **NOVÝ** — sdílené styly (lišta, legenda, popup + přepínač rozsahu grafu, výstraha, modal, vítr, mobil) |
| `www/map/app.js` | **NOVÝ** — jádro: `POLLUTANTS`, `sensorStore`, blokace, 5 fetcherů, `renderSensor`, legenda, podklady, graf 24h+výhled / 5 dní |
| `www/map/interp.js` | **NOVÝ** — IDW overlay s vahami sítí a unášením větrem |
| `www/map/wind.js` | **NOVÝ** — leaflet-velocity vrstva |
| `www/map/info.js` | **NOVÝ** — modal „Jak mapa funguje" |
| `www/map/alerts.js` | **NOVÝ** — lišta výstrah ČHMÚ (jen ovzduší) + ORP lookup |
| `www/map/orp-areas.js` | **NOVÝ** — 206 ORP polygonů (generováno v nosíčku, kopie, 35 kB, načítá se líně) |
| `www/lib/Http.php` | **NOVÝ** — cURL fetch, atomický zápis, zámek |
| `www/lib/Alerts.php`, `www/lib/Forecast.php` | **NOVÉ** — čistá logika, kopie z nosíčku (držet v synchronu) |
| `www/lib/SensorQuality.php` | **NOVÝ** — tabulka `sensor_bans` (+ seed SC_46875/SC_7213), auto-příznaky |
| `www/wind-proxy.php` | **NOVÝ** — Open-Meteo grid, cache 60 min |
| `www/alerts-proxy.php` | **NOVÝ** — CAP bulletin ČHMÚ, cache 10 min, výdej jen `smog` |
| `www/forecast-proxy.php` | **NOVÝ** — boxploty ČHMÚ (KRAJ), cache 30 min, ukotvení na senzor |
| `www/sensor-bans.php` | **NOVÝ** — veřejný seznam blokací + auto-příznaků (cache 10 min) |
| `www/admin/bans.php` | **NOVÝ** — správa blokací, chráněno tokenem |
| `www/sck-proxy.php` | + hluk (`data["53"]`), zařízení jen s hlukem se nezahazují |
| `www/historygraph/collect.php` | SC PM10; ČHMÚ PM10/NO₂/O₃ (+ validace); SCK PM10 + hluk; blokace z tabulky (natvrdo vyloučené ID pryč); `PROXY_BASE_URL`; `str_getcsv` explicitně |
| `www/historygraph/get_history.php` | `?type=` whitelist, `?hours=24&step=hour` (hodinová maxima, `t0`/`step`), okno 5 dní, gap-detekce, Europe/Prague, stale i pro Libčich |
| `tests/test_alerts.php`, `tests/test_forecast.php` | **NOVÉ** — offline testy (z nosíčku, cesty upraveny) |
| `.gitignore` | + `www/cache/*.lock`, `www/cache/forecast/` |
| `CLAUDE.md` | sdílené soubory, self-refreshing proxy, blokace, geolokace |

Beze změny: `sc-proxy.php`, `chmi-proxy.php`, `pa-proxy.php`, `libcich-proxy.php`,
`monitor.php`, `cleanup_chmi.php`.

**`config.php`**: žádná povinná změna. Doporučeno přidat samostatný token pro admin blokací
(jinak se použije `CRON_TOKEN`):

```php
define('ADMIN_TOKEN', '...');   // openssl rand -hex 24
```

**DB**: tabulka `sensor_bans` se v `air_quality_history.db` vytvoří sama při prvním volání
`sensor-bans.php` / `collect.php` / `admin/bans.php`. Schéma `measurements` beze změny.

**PHP na Active24**: potřebuje `simplexml` (výstrahy) a `zlib` (ZIP předpovědi bez ext/zip).
Obojí je ve standardní instalaci; ověřit `phpinfo()` před nasazením.

## Nasazení (ruční FTP na Active24 — git nenasazuje)

Pořadí (backend dřív než frontend):

1. `www/lib/` (celá složka: Http, Alerts, Forecast, SensorQuality)
2. `www/wind-proxy.php`, `www/alerts-proxy.php`, `www/forecast-proxy.php`, `www/sensor-bans.php`
3. `www/admin/bans.php`
4. `www/sck-proxy.php`
5. `www/historygraph/get_history.php`, `www/historygraph/collect.php`
6. `www/map/style.css`, `app.js`, `interp.js`, `wind.js`, `info.js`, `alerts.js`, `orp-areas.js`
7. `www/map/index.html`, `www/map/embed.html`

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`,
`backup-2.7/`, `tests/`, `*.md`, cache soubory. Ověřit, že `www/cache/` je zapisovatelná
(chmi-proxy do ní už píše) — vzniknou v ní `wind_latest.json`, `alerts_latest.json`,
`sensor_flags.json`, `forecast/`.

## Po nasazení

- `wind-proxy.php` → JSON s dnešním `refTime`, `X-Source: OpenMeteo-Live`, podruhé `Local-Cache`.
- `alerts-proxy.php` → `{"filter":"smog","alerts":[…]}` (prázdné pole je normální stav).
- `forecast-proxy.php?area=19&type=PM10` → `run` dnešní, `p50` 48 hodnot.
- `sensor-bans.php` → `banned: ["SC_46875","SC_7213"]`.
- `admin/bans.php?token=…` → stránka se dvěma seznamy.
- `sck-proxy.php?debug=1` → klíč `"53"` u zařízení.
- Po prvním cron běhu: `get_history.php?id=CHMI_ALEGA&type=NO2` vrací řádky; 24h pohled v popupu
  se naplní během dne, 5denní za 5 dní.
- Volitelné URL crony (jinak obnovuje první návštěvník po expiraci):
  ```
  7  * * * * curl -s "https://senzorvzduchu.cz/wind-proxy.php?token=TOKEN" > /dev/null
  */10 * * * * curl -s "https://senzorvzduchu.cz/alerts-proxy.php?token=TOKEN" > /dev/null
  */30 * * * * curl -s "https://senzorvzduchu.cz/forecast-proxy.php?token=TOKEN" > /dev/null
  ```

## Rollback

Nahrát zpět `backup-2.7/` (index.html, embed.html, collect.php, get_history.php,
sck-proxy.php). Nové soubory mohou zůstat, nic je nevolá. Tabulka `sensor_bans` a nové
`value_type` v DB starému kódu nevadí.

## 2.8.1 (7. 9. 2026) — popup stejné velikosti v obou pohledech, viditelné ×

Soubory: `map/app.js`, `map/style.css`, `map/index.html`, `map/embed.html` (cache-buster
`?v=2.8.1` u app.js a style.css), `historygraph/get_history.php`.

- Popup má pevnou šířku (`minWidth = maxWidth`; mobil `min(360, innerWidth − 24)`, desktop
  300). Dřív ji Leaflet měřil z obsahu: „24 h + výhled" (dlouhá poznámka) vyšel na mobilu
  přes celou šířku, „5 dní" užší; při otevření se bublina vykreslila úzká a po načtení grafu
  se roztáhla přes pravý okraj displeje — × bylo mimo obraz, ale klikatelné.
- Box grafu drží výšku i při načítání (`.chart-container.loading`, stav `.chart-status`
  přes plátno; `.empty` = bez dat, 36 px). Poznámka pod grafem je v obou pohledech
  (5 dní: „jednotlivá měření, bez výhledu ČHMÚ"; 24 h bez výhledu: „není k dispozici";
  hluk bez poznámky). Přepnutí 24 h ↔ 5 dní tak nemění velikost bubliny.
- `refreshPopupLayout()` po každém výsledku načtení (i bez dat / chyba — dřív se autoPan
  nepřepočítal). Rychlé přepínání: platí jen poslední požadavek (`historySeq`).
- AutoPan odsazení + šířka se přepočítají při kliku na marker (handler před `bindPopup`),
  ne až po otevření.
- × plná brand barva, na mobilu 30 px (bílý kroužek s šedým × na skoro bílé hlavičce splýval).
- `get_history.php`: stáří posledního měření z `MAX(measured_at)` přes celou DB, ne z
  vráceného okna — zdroj stojící > 24 h v hodinovém režimu vycházel bez stáří, varování
  „neaktualizuje" svítilo jen v 5denním pohledu (a popup měl v každém pohledu jinou výšku).
- Text bez dat v 24h pohledu: „Posledních 24 h bez měření." (5 dní: „Historie zatím není
  k dispozici.").

## 2.8.2 (7. 9. 2026) — zelená výplň grafu i na mobilu

Soubory: `map/app.js` (`?v=2.8.2` v `index.html` a `embed.html`).

- Výplň pod křivkou (zelená → žlutá → červená) se počítá přes skutečnou plochu grafu
  (`chartArea`, scriptable `backgroundColor`, gradient cachovaný podle výšky plochy). Dřív
  byl přechod na pevných 180 px od spodku plátna: na mobilu (box 150 px, legenda nahoře →
  plocha ~30–134 px) ležel spodek plochy už ve žluté a zelená se nikdy nevykreslila.

## 2.8.3 (7. 9. 2026) — ostatní veličiny v popupu jako štítky

Soubory: `map/app.js`, `map/style.css` (`?v=2.8.3` v obou slupkách).

- Řádky „PM10: ……… 28.2 μg/m³" (název vlevo, hodnota u pravého okraje) nahrazeny štítky
  `.popup-chip` vedle sebe (`.popup-others`, flex-wrap): název hned u hodnoty. Látky
  z `POLLUTANTS` mají tečku v barvě pásma (`colorFor`), teplota/vlhkost/tlak/PM1 bez tečky.

## 2.8.4 (7. 9. 2026) — texty

Soubory: `map/app.js`, `map/info.js`, `map/index.html`, `map/embed.html` (`app.js?v=2.8.4`,
`info.js?v=2.8.4`).

- Popup: pohled „5 dní" už nemá poznámku pod grafem (2.8.1 ji přidala kvůli stejné výšce
  obou pohledů; z grafu je bez výhledu vidět). Poznámka zůstává jen v 24 h (výhled / není
  k dispozici).
- Modal „Jak mapa funguje": lead „Barevná plocha mezi senzory je interpolace."; O₃ odstavec
  vysvětluje, proč je mimo města vyšší (NO z dopravy ozon rozkládá); hluk bez „ne pro celou
  čtvrť"; sekce „Interpolace" bez „(odhad mezi senzory)"; hodinové maximum v 24h pohledu
  vysvětleno prostěji; předpověď bez „hodinu po hodině / vychází každou hodinu"; přepočet
  předpovědi (poměr senzor/model, omezení ⅓–3×, fallback) popsán srozumitelněji; výstrahy bez
  „nic nepřepočítáváme".
- Poznámka „Výhled ČHMÚ: max. … min. …" pod grafem odstraněna úplně (i v 24 h) — nikdo ji
  nečetl, popup zahlcovala; křivka výhledu to ukazuje sama. Pryč `forecastNote`, `chartNote`,
  `.chart-fc-note`.
- Štítky látek s pásmy (PM2.5, PM10, NO₂, O₃, hluk) jsou tlačítka: klik přepne mapu, legendu
  i popup na tu látku (stejné jako lišta, `setPollutant`); popup zůstane otevřený a graf se
  nahraje pro novou látku. Klik musí zastavit propagaci — jinak překreslený popup Leaflet
  zavře jako klik do mapy. Teplota/vlhkost/tlak/PM1 zůstávají pasivní štítky.
- Názvy tříd v legendě podle EEA (Good/Fair/Moderate/Poor/Very poor/Extremely poor): Dobrá,
  Uspokojivá, Střední, Špatná, Velmi špatná, Extrémně špatná (dřív Dobrá, Střední, Zhoršená,
  Nezdravá, Velmi nezdravá, Nebezpečná — „Střední" se posunula z 2. na 3. třídu, jako u EEA).
  Hluk má vlastní názvy beze změny.
- **Pásma všech čtyř látek = EEA EAQI po revizi 2024** (ETC HE Report 2024/17, na
  airindex.eea.europa.eu už platí; hodinové hodnoty pro všechny látky, první dvě třídy = WHO
  2021): PM2.5 5 / 15 / 50 / 90 / 140; PM10 15 / 45 / 120 / 195 / 270; NO₂ 10 / 25 / 60 / 100 /
  150; O₃ 60 / 100 / 120 / 160 / 180 µg/m³. Dřív PM2.5 US EPA (12 / 35.4 / 55.4 / 150.4 / 250.4)
  a PM10/NO₂/O₃ EAQI 2017 (20/40/50/100/150, 40/90/120/230/340, 50/100/130/240/380). Platí pro
  markery, legendu, hero, štítky i interpolaci (vše přes `colorFor`). Dopad: zelená je všude
  přísnější (PM2.5 jen do 5), červená u PM2.5 od 50 (dřív 55) a u PM10 až od 120; NO₂ výrazně
  přísnější. Limitní čáry v grafu (EU 2024/2881) beze změny. Text modalu „Barevná škála" upraven.
