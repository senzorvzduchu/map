# Nasazení 3.3 (auto-příznak „indoor") na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **SANDBOX, ověřeno lokálně 16. 10. 2026 pozdě večer** (port 8133). 3.3 = kopie 3.2 (3.0 teplota
a vlhkost + 3.1 meteostanice ČHMÚ + 3.2 tmavý režim, vše **nenasazeno**) + automatický příznak
`indoor`: komunitní senzor, který je v noci výrazně teplejší a sušší než nejbližší meteostanice ČHMÚ
a jehož teplota se přes den nemění, mapa skryje sama (jako příznaky stuck/plateau u PM). Původní verze
změněných souborů: `backup-3.2/`. **Nasazuje se 3.0 + 3.1 + 3.2 + 3.3 najednou** — soubory 3.3 obsahují
vše z předchozích (`DEPLOY_3.0.md` §1, `DEPLOY_3.1.md` §1 a `DEPLOY_3.2.md` §1 platí dál, tady jsou jen
rozdíly 3.3).

## 1. Co se nahrává (rozdíly 3.3)

| Soubor na serveru | Změna | K čemu |
|---|---|---|
| `lib/SensorQuality.php` | změna | `autoFlags()` = `flatnessFlags()` (stuck/plateau, beze změny) + `indoorFlags()`; nové `referenceStations()` (z `cache/chmi_meteo.json`), `communityCoords()` (z 5 proxy cache), `nightWindows()`, `distanceKm()`, `median()`, `evaluateIndoor()`; každý příznak má navíc `detail` (text pro admin) |
| `lib/ChmiMeteo.php` | změna | +1 stanice `0-203-0-11617` **Poděbrady** (61 stanic) — pár AirGradient 61860/78554 neměl žádnou stanici do 30 km (nejbližší Jičín 41 km); 10m soubor má T i H, ~137 kB |
| `sensor-bans.php` | jen komentář | výstup `{banned, auto:[{sensor_id, reason}]}` beze změny tvaru, `reason` nově i `indoor`; cache 10 min `cache/sensor_flags.json` stejná |
| `admin/bans.php` | změna | tabulka auto-příznaků: sloupce Veličina / **Detail** / n (místo Průměr / Odchylka), popis heuristiky indoor, tlačítko „Zablokovat trvale" ukládá důvod „Auto-příznak indoor (senzor v interiéru), potvrzeno správcem" |
| `historygraph/collect.php` | jen komentář | auto-příznaky se v kolektoru **záměrně nepřeskakují** (viz §3) |
| `map/info.js` | změna | sekce „Kontrola kvality": odrážka o automatickém skrývání senzorů v interiéru. **3.3.1 (17. 10. 2026, revize textů uživatelem)**: zkrácen odstavec „Teplota a vlhkost" (bez 10–85 min, bez výčtu sítí), smazán první odstavec Interpolace (IDW, váhy — váhy zůstávají v tabulce sítí), zkrácen odstavec „Proč předpověď přepočítáváme" a čtyři odrážky Kontroly kvality (bez číselných prahů stuck/plateau); opraveny tři fakta: „ukládáme každých 15 minut“ (cron reálně 15 min), meteostanice ČHMÚ „jednou za hodinu“ (v DB 1 hodnota/h), „61 meteorologických stanic“. `info.js?v=3.3.1` v obou shellech |
| `map/index.html`, `map/embed.html` | změna | `info.js?v=3.3.1`, `style.css?v=3.3.1`; **3.3.1 (17. 10. 2026)**: čipy Teplota/Vlhkost mají dva štítky `<span class="chip-long">Teplota</span><span class="chip-short">°C</span>` (resp. Vlhkost / %) + `aria-label` s plným názvem |
| `map/style.css` | změna (3.3.1) | `.chip-short` skrytý, v `@media (max-width: 768px)` naopak skrytý `.chip-long` → na mobilu lišta „PM2.5 · PM10 · NO₂ · O₃ · 🔊 Hluk · 🌡 °C · 💧 %“ (čip teploty 49 px místo ~90 px vedle O₃ 34 px); Hluk záměrně s ikonou i slovem; lišta se při 375 px stále posouvá (462 px obsahu / 355 px), to je přijato |

Plus celý seznam z `DEPLOY_3.2.md` §1 (13 souborů; `lib/SensorQuality.php`, `lib/ChmiMeteo.php`,
`admin/bans.php`, `map/info.js`, `map/index.html`, `map/embed.html` z něj nahradit verzí 3.3). Celkem na
FTP: `lib/Http.php`, `lib/ChmiMeteo.php`, `lib/SensorQuality.php`, `chmi-meteo-proxy.php`,
`sensor-bans.php`, `historygraph/collect.php`, `historygraph/get_history.php`, `pa-proxy.php`,
`admin/bans.php`, `map/app.js`, `map/info.js`, `map/style.css`, `map/index.html`, `map/embed.html` —
14 souborů, vše ze `3.3-indoor-flag/www/`. `app.js`, `interp.js` se v 3.3 nemění (filtrují podle
`sensor_id` bez ohledu na `reason`).

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`, `backup-3.2/`,
`tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

## 2. Postup

Pořadí podle `DEPLOY_3.2.md` §2 (backend → frontend); navíc pro 3.3: `lib/ChmiMeteo.php` a
`lib/SensorQuality.php` před `sensor-bans.php` a `admin/bans.php`. Kontroly navíc:

- `/chmi-meteo-proxy.php?token=<CRON_TOKEN>` (vynutí obnovu) → `count: 61`, ve `stations` je
  `"name":"Poděbrady"` s teplotou a vlhkostí; `?debug=1` → `missing []`.
- `/sensor-bans.php` → `auto: []` nebo seznam; `reason` může být `stuck`, `plateau`, `indoor`.
  **První noc po nasazení** (00–05 h) teprve naplní noční vzorky — příznak `indoor` se může objevit
  nejdřív ráno po první celé noci, do té doby je `auto` jen PM.
- Druhý den ráno `/admin/bans.php?token=…` → sekce „Automatické příznaky". **Pozor (zjištěno 17. 10.
  2026 před nahráním): AG_61860 je od 16. 10. ručně zablokovaný a `collect.php` ručně blokované senzory
  neukládá (řádek `if (isset($bannedSensors[$sensor_id])) continue;`), takže nemá žádné noční T/RH
  řádky a příznak `indoor` se u něj NEOBJEVÍ.** Očekávání: `auto` bez `indoor` je normální stav.
  Chceš-li automatiku ověřit na známém případu, ruční ban AG_61860 v adminu zrušit, počkat jednu celou
  noc (00–05 h) a ráno čekat řádek `AG_61860 · indoor · temperature · „v noci o X °C tepleji a o Y b.
  sušeji než Poděbrady (5 km), rozkmit 24 h Z °C" · n ≥ 6`; pak „Zablokovat trvale". Jinak ban nechat
  (jistější) a sledovat jen, zda se `indoor` neobjeví u senzoru, který venku evidentně je (falešný
  poplach → prahy `SensorQuality::INDOOR_*`).
- `/map/` → senzor s příznakem `indoor` chybí na mapě i v interpolaci (do 10 min od vzniku příznaku,
  cache `sensor_flags.json`; mapa si seznam stahuje každých 5 min před ostatními zdroji).
- ℹ „Jak mapa funguje" → sekce „Kontrola kvality a blokace senzorů" má odrážku „automaticky skrývá
  senzory v interiéru".

## 3. Rozhodnutí a proč

- **Heuristika** (zadání z `TODO.md` 3.2): za posledních 24 h, jen **noční hodiny 00:00–05:00
  Europe/Prague** (bez slunce → ohřev krytu neplatí): medián (T_senzor − T_ČHMÚ) > **6 °C** **a**
  medián (RH_ČHMÚ − RH_senzor) > **15 bodů** **a** rozkmit teploty senzoru za 24 h (max − min)
  < **4 °C** (interiér je stabilní; čidlo na slunci má přes den velký rozkmit i když v noci sedí).
  Reference = **nejbližší meteostanice ČHMÚ do 30 km** (`CHMI_M_` z 3.1, souřadnice z
  `cache/chmi_meteo.json`); **≥ 6 párových nočních vzorků** (vzorek senzoru spárovaný s hodnotou
  stanice ve stejné nebo sousední hodině — ČHMÚ ukládáme hodinově, komunitní po 5 min). ČHMÚ se
  nikdy neflaguje. Ověřeno na syntetickém páru Poděbrady (`tests/test_indoor_flag.php`): AG_61860
  27,5 °C / 29 % → indoor; AG_78554 kopírující stanici → ne; senzor na slunci (38 °C v poledne,
  v noci jako ČHMÚ) → ne (rozkmit); teplo bez sucha (zvlhčovač) → ne; < 6 vzorků → ne; bez stanice do
  30 km → ne (fail open).
- **Souřadnice komunitních senzorů** se berou z proxy cache, které mapa už drží (`sc_data_cache.json`,
  `sck_data_cache.json`, `pa_data_cache.json`, `libcich_data_cache.json`, `cache/airgradient.json`) —
  žádný nový sloupec v DB, žádné další API volání. Senzor, který právě v cache není (offline), se
  nespáruje a neflaguje.
- **Samoléčivé, nikde neuložené**: počítá se v `autoFlags()` při obnově `cache/sensor_flags.json`
  (10 min), stejně jako stuck/plateau. Zmizí samo, jakmile noc vyjde jinak (otevřené okno, senzor
  přenesen ven) nebo když chybí reference.
- **Kolektor senzor dál ukládá** (na rozdíl od ručního banu). Kdyby přeskakoval, za 24 h by nebyly
  noční vzorky → příznak by zmizel → senzor by se na den vrátil na mapu → znovu příznak. Historie
  skrytého senzoru nikomu nevadí (popup není vidět).
- **Poděbrady do `ChmiMeteo::STATIONS`**: bez stanice do 30 km by motivující případ nešel
  zachytit. Meta1 ČHMÚ má v okruhu 30 km od Poděbrad 16 stanic; `0-203-0-11617` Poděbrady (5,5 km)
  má 10m soubor s T i H (ověřeno 16. 10. 2026 22:40, 137 kB, QUALITY 5). Pokrytí: z 276 komunitních
  senzorů se souřadnicemi má stanici do 30 km 259 (bez Poděbrad), zbylých 17 je v Polabí mezi
  Poděbrady a Kutnou Horou (SC 56893/56894, 59830/59831, 97287, 77383…) — Poděbrady pokryjí většinu.
  Zátěž +1 soubor / 15 min (~0,5 MB/h navíc), strop „~60" posunut na 61.
- **Výkon**: na lokální DB (4 000 řádků) 11 ms, 4 MB; v produkci (~5 dní × ~1 100 hodnot/5 min)
  tři dotazy přes index `idx_sensor_type`: noční řádky ČHMÚ (~120 × 2), agregát rozkmitu
  (`MIN/MAX GROUP BY sensor_id` nad T za 24 h), noční řádky komunitní (~235 × 60 × 2 = ~28 k řádků,
  streamované `fetch()`, ne `fetchAll`) — odhad < 0,5 s jednou za 10 min. Výjimka v indoor části
  se loguje a PM příznaky se vrátí dál (`try/catch` v `autoFlags`).
- **Prahové hodnoty** jsou konstanty `SensorQuality::INDOOR_*` (DT 6, DRH 15, RANGE 4, MAX_KM 30,
  MIN_SAMPLES 6, noc 0–5 h). Zimní topná sezóna rozdíl zvětší (jistější), léto bez topení zmenší —
  proto i podmínka RH. Letní falešná shoda je nepravděpodobná (musí být zároveň teplo, sucho a
  stabilní teplota v noci).

## 4. Rollback

Nahrát zpět `lib/SensorQuality.php`, `lib/ChmiMeteo.php`, `admin/bans.php`, `sensor-bans.php`,
`historygraph/collect.php`, `map/info.js`, `map/style.css`, `map/index.html`, `map/embed.html` z `backup-3.2/`
(= stav 3.2). Smazat `cache/sensor_flags.json` (jinak až 10 min starý seznam) a
`cache/chmi_meteo.json` (61 → 60 stanic). DB beze změny schématu — nic se nemigruje.

## 5. Ověřeno lokálně 16. 10. 2026 (port 8133)

- `php tests/test_indoor_flag.php` → ALL PASS (34 kontrol); `test_chmi_meteo.php`, `test_airgradient.php`
  dál ALL PASS.
- Kolektor 2× proti lokálním proxy (1 230 + 681 řádků), `cache/chmi_meteo.json` má 61 stanic včetně
  Poděbrad (9,0 °C / 95 %, `age_min` 48), řádky `CHMI_M_0-203-0-11617` v DB.
- Reálná DB má v tuto chvíli jen večerní řádky (vznikla 17:54), noc tedy chybí → pro ověření cesty
  **vloženy syntetické noční řádky** (16. 10. 00:00–05:00: AG_61860 27,5 °C / 29 %, AG_78554
  5,8 °C / 92 %, ČHMÚ Poděbrady 5 °C / 95 % hodinově; 254 řádků, po testu smazány):
  - `sensor-bans.php` → `auto: [{"sensor_id":"AG_61860","reason":"indoor"}]`;
  - `admin/bans.php` → řádek `AG_61860 · indoor · temperature · „v noci o 22.1 °C tepleji a o 66 b.
    sušeji než Poděbrady (5 km), rozkmit 24 h 0.7 °C" · n 60` + tlačítko „Zablokovat trvale" s důvodem
    „Auto-příznak indoor (senzor v interiéru), potvrzeno správcem";
  - `/map/` → `sensorBans` = {SC_46875, SC_7213, AG_61860}; `sensorStore`/`markers` bez AG_61860,
    AG_78554 přítomen (343 záznamů) → chybí i v interpolaci (čte `sensorStore`); konzole bez chyb
    kromě `favicon.ico` 404 (bylo i dřív);
  - ℹ modal → odrážka „automaticky skrývá senzory v interiéru" viditelná; `embed.html` 200 s
    `info.js?v=3.3`.
- Po smazání syntetických řádků `auto: []` (samoléčení na reálných datech).
- `autoFlags()` na lokální DB 11 ms.

## 5b. Ověřeno lokálně 17. 10. 2026 — 3.3.1 krátké štítky (port 8133)

- Viewport 375 × 812: lišta `PM2.5 | PM10 | NO₂ | O₃ | 🔊 Hluk | 🌡 °C | 💧 %`, šířky čipů
  56/53/42/34/65/49/51 px (dřív Teplota ~90 px); aktivní „💧 %“ + legenda „Vlhkost (%)“; `embed.html` totéž.
- Desktop 1024 px: `🌡 Teplota | 💧 Vlhkost` beze změny. Konzole bez chyb (jen 404 kořene `/`).

## 5c. Před nahráním 17. 10. 2026 (kontrola)

- `diff -rq` 3.2-dark-mode/www × 3.3-indoor-flag/www → přesně 9 souborů (tabulka §1 vč. `map/style.css`).
- Testy `tests/test_*.php`: airgradient, alerts, chmi_meteo, forecast, indoor_flag ALL PASS;
  `test_libcich_collect.php` FAIL jen proto, že lokální proxy na 8123 neběžela (síťový test, ne kód).
- Kód prohlédnut: `SensorQuality` (autoFlags = flatnessFlags + indoorFlags v try/catch, 3 dotazy,
  `value REAL` → MIN/MAX číselně, LIKE s ESCAPE, DST přes DateTime), ostatní diffy jen komentáře/admin UI.
- `backup-3.2/` doplněn o 3.2 verze všech 9 souborů (rollback §4). Konce řádků `style.css`/`info.js` LF.

## 6. Ověřeno živě (po FTP) — 17. 10. 2026 16:26

- `/map/` a `/map/embed.html` 200, odkazují `style.css?v=3.3.1` + `info.js?v=3.3.1`, obsahují `chip-short`
  a `aria-label="Teplota"`. `style.css` živě má obě pravidla `.chip-short`/`.chip-long`.
- `info.js` živě: „každých 15 minut“, „61 meteorologických stanic“, odrážka „senzory v interiéru“, bez IDW
  odstavce, bez „10–85 minut“.
- `/sensor-bans.php` → `banned` 5 (AG_61860, SC_46875, SC_7213, SC_83829, SC_88269), `auto: []`.
- `/admin/bans.php` bez tokenu → 403 (správně).
- `/chmi-meteo-proxy.php?debug=1` → `configured 61, with_reading 61, missing [], elapsed_s 0.83`, ale
  `in_output 60` a veřejný výstup bez Poděbrad: **metadata stanic `cache/chmi_meteo_meta.json` mají TTL
  24 h a obsahují včerejších 60 stanic; `ChmiMeteo::build()` stanici bez souřadnic vynechá.** Náprava:
  jednou otevřít `/chmi-meteo-proxy.php?token=<CRON_TOKEN>` (`$force` obnoví i metadata), jinak se
  Poděbrady objeví samy po expiraci metadat (~22:40 místního času 17. 10.). Do té doby nemá pár
  AG 61860/78554 referenci do 30 km (bez vlivu, AG_61860 je ručně blokovaný).
- 16:28 uživatel otevřel `?token=…`: výstup stále `count 60` bez Poděbrad a `?debug=1` poté ukazuje
  `meta.source cache, age_s 64653` → metadata se NEPŘEPSALA. Poděbrady v meta1 jsou
  (`0-203-0-11617, H3PODE01, 15.133554, 50.140503`), takže buď token neseděl (`$force` false, šlo jen
  o běžnou 15min obnovu stanic), nebo stažení meta1 (~75 kB) z Active24 selhalo a proxy tiše použila
  starou kopii (`fallback: stale-cache`). Rozlišení: `?token=…&debug=1` → `meta.source` + `http_code`.
  Pokud stažení meta1 z hostingu padá, Poděbrady se neobjeví ani po expiraci TTL (fallback na starou
  kopii) → nutná oprava (např. ruční souřadnice ve `STATIONS` jako záloha).
- `indoor` u AG_61860 se neobjeví, dokud trvá ruční ban — viz §2.
