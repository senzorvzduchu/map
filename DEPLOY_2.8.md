# Nasazení 2.8 na senzorvzduchu.cz (Active24, ruční FTP)

Stav k 4. 9. 2026: **nenasazeno**, produkce = 2.7. Záloha produkčního stavu:
`../_ZALOHA_2026-09-04/2.7-carto-key-PROD-2026-09-04.zip` (celá složka 2.7 včetně DB a cache).

## 1. Co se nahrává a k čemu to je

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `lib/Http.php` | nový | cURL fetch, atomický zápis, zámek | alerts/forecast/sensor-bans/admin |
| `lib/Alerts.php` | nový | parser CAP výstrah ČHMÚ (čistá logika, z nosíčku) | alerts-proxy.php |
| `lib/Forecast.php` | nový | ZIP reader + parser + ukotvení předpovědi (z nosíčku) | forecast-proxy.php |
| `lib/SensorQuality.php` | nový | tabulka blokací, auto-příznaky | sensor-bans, admin, collect |
| `wind-proxy.php` | nový | větrný grid Open-Meteo, cache 60 min | interp.js, wind.js |
| `alerts-proxy.php` | nový | výstrahy ČHMÚ, jen ovzduší, cache 10 min | alerts.js |
| `forecast-proxy.php` | nový | předpověď ČHMÚ po krajích, cache 30 min | app.js (graf) |
| `sensor-bans.php` | nový | veřejný seznam blokací + auto-příznaků, cache 10 min | app.js |
| `admin/bans.php` | nový | správa blokací, `?token=` | správce ručně |
| `sck-proxy.php` | změna | + hluk (klíč 53) | app.js, collect |
| `historygraph/collect.php` | změna | ukládá PM10/NO₂/O₃/hluk, přeskakuje blokované | cron (beze změny) |
| `historygraph/get_history.php` | změna | `?type=`, hodinový režim, výpadky | app.js (graf) |
| `map/style.css` | nový | sdílené styly | obě HTML |
| `map/app.js` | nový | jádro mapy | obě HTML |
| `map/interp.js`, `wind.js`, `info.js`, `alerts.js` | nové | vrstvy, modal, výstrahy | obě HTML |
| `map/orp-areas.js` | nový | polygony ORP (35 kB), načítá se líně jen když je potřeba | alerts.js, app.js |
| `map/index.html`, `map/embed.html` | změna | slupky | návštěvník |

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`,
`backup-2.7/`, `tests/`, `*.md`, `cache/`, `*_cache.json`.

## 2. Postup

1. Ověřit na hostingu PHP moduly `simplexml` a `zlib` (`phpinfo()` nebo zkusmo otevřít
   `alerts-proxy.php` po nahrání — vrátí JSON, ne bílou stránku).
2. (Doporučeno) do `config.php` na serveru přidat `define('ADMIN_TOKEN', '…');`
   (`openssl rand -hex 24`). Bez toho admin používá `CRON_TOKEN`.
3. FTP nahrát v pořadí: `lib/` → čtyři nové proxy + `sensor-bans.php` → `admin/` → `sck-proxy.php`
   → `historygraph/get_history.php` + `collect.php` → `map/*.js` + `style.css` → `map/index.html`
   + `embed.html`. Backend dřív než frontend, ať nový frontend nevolá neexistující endpoint.
4. Zkontrolovat (v prohlížeči):
   - `/wind-proxy.php` → JSON, hlavička `X-Source: OpenMeteo-Live`, podruhé `Local-Cache`
   - `/alerts-proxy.php` → `{"filter":"smog","alerts":[…]}` (prázdné pole = žádná výstraha, OK)
   - `/forecast-proxy.php?area=19&type=PM10` → `run` s dnešním datem, 48 hodnot `p50`
   - `/sensor-bans.php` → `banned: ["SC_46875","SC_7213"]`
   - `/admin/bans.php?token=…` → stránka se dvěma seznamy
   - `/map/` → lišta, interpolace, popup s grafem (PM2.5 historie je hned, ostatní látky do 5 dní)
5. Cron se **nemění** (`collect.php` každých 5 min, `monitor.php` každých 15 min).
   Volitelné URL crony, aby první návštěvník nečekal na zdroj:
   ```
   7    * * * * curl -s "https://senzorvzduchu.cz/wind-proxy.php?token=TOKEN" > /dev/null
   */10 * * * * curl -s "https://senzorvzduchu.cz/alerts-proxy.php?token=TOKEN" > /dev/null
   */30 * * * * curl -s "https://senzorvzduchu.cz/forecast-proxy.php?token=TOKEN" > /dev/null
   ```

## 3. Databáze historie — nic se nepřepíše

- `historygraph/air_quality_history.db` se **nenahrává**; zůstává produkční soubor.
- Schéma tabulky `measurements` je beze změny. Nový `collect.php` do ní jen přidává řádky
  s novými `value_type` (`PM10`, `NO2`, `O3`, `noise`) vedle stávajících `PM2.5`.
  Historie PM2.5 pokračuje bez přerušení — stejný kód, stejné ID senzorů, stejný cron.
- Nová tabulka `sensor_bans` se vytvoří sama při prvním volání (`sensor-bans.php`, `collect.php`
  nebo admin) a naplní se dvěma dosud natvrdo vyloučenými senzory. Nic jiného se v DB nemění.
- Retence zůstává 5 dní, mazání je časové (`measured_at < now − 5 d`), nezávislé na typu.
- Ověřeno lokálně na kopii produkční DB: po dvou bězích kolektoru přibyly jen nové typy, PM2.5
  řádky netknuté, ČHMÚ/Libčich hodiny bez duplicit.

## 4. Zátěž externích API — nic se nevolá častěji než dřív

| Zdroj | Dřív (2.7) | Teď (2.8) | Poznámka |
|---|---|---|---|
| **PurpleAir (placené)** | `pa-proxy.php` cache 15 min → max. 4 volání/h | **beze změny** | frontend i kolektor jdou přes proxy; nový kód proxy nemění |
| Sensor.Community | proxy cache 5 min | beze změny | |
| ČHMÚ měření | proxy cache 15 min | beze změny | frontend nově parsuje i PM10 ze stejného CSV |
| Smart Citizen | proxy cache 5 min | beze změny | hluk je ve stejné odpovědi |
| Libčich | proxy cache 2 min | beze změny | |
| Open-Meteo (nové, zdarma, bez klíče) | – | max. 3 požadavky/h (grid 180 bodů ve 3 dávkách) | cache 60 min sdílená všemi návštěvníky |
| ČHMÚ výstrahy (nové, open data) | – | index 91 kB max. 6×/h; bulletin 1,7 MB jen když se změnil název | cache 10 min |
| ČHMÚ předpověď (nové, open data) | – | 4 ZIPy (~280 kB) max. 1×/h, jen když vyšel nový běh | cache 30 min, kontrola po 30 min |
| CARTO dlaždice | 1 vrstva | **2 vrstvy** (podklad bez popisků + popisky) | jediný reálný nárůst: ~2× dlaždic na zobrazení; free tier 5 M dlaždic/měsíc — sledovat |
| Vlastní server | popup = 1 dotaz do DB | popup = 1–2 dotazy (historie + předpověď z cache) + `sensor-bans.php` každých 5 min (cache 10 min) | lokální SQLite, bez externího volání |

Interval obnovy mapy zůstává 5 minut. Všechny nové zdroje mají cache sdílenou mezi návštěvníky
a zámek proti souběžnému stahování, takže počet volání nezávisí na návštěvnosti.

## 5. Návrat zpět (rollback)

Nahrát z `backup-2.7/` (nebo ze zálohy zip) pět původních souborů:
`map/index.html`, `map/embed.html`, `historygraph/collect.php`, `historygraph/get_history.php`,
`sck-proxy.php`. Nové soubory (`lib/`, nové proxy, `map/*.js`, `style.css`, `admin/`) mohou na
serveru zůstat — starý frontend je nevolá. DB není třeba vracet: staré verzi nové `value_type`
ani tabulka `sensor_bans` nevadí.

## 6. Doplněk 2.8.1 (7. 9. 2026) — popup — NASAZENO 7. 9. 2026 ~13:50 (ověřeno na iPhonu)

Nahrát přes FTP (přepsat): `map/app.js`, `map/style.css`, `map/index.html`, `map/embed.html`,
`historygraph/get_history.php`. Žádná změna DB ani configu. Kontrola na mobilu: otevřít
senzor, přepnout „5 dní" a zpět — bublina stejně široká, × vpravo nahoře tmavé a vidět;
`get_history.php?id=CHMI_ALEGA&type=PM2.5&hours=24&step=hour` vrací `quality.latest_age_sec`.

## 7. Doplněk 2.8.2 (7. 9. 2026) — zelená výplň grafu na mobilu — NASAZENO 7. 9. 2026 ~14:00

Nahrát přes FTP (přepsat): `map/app.js`, `map/index.html`, `map/embed.html` (cache-buster
`app.js?v=2.8.2`). Kontrola na mobilu: senzor s nízkými hodnotami → výplň pod křivkou začíná
zeleně jako na desktopu.

## 8. Doplněk 2.8.3 (7. 9. 2026) — štítky ostatních veličin — NASAZENO 7. 9. 2026

Nahrát přes FTP (přepsat): `map/app.js`, `map/style.css`, `map/index.html`, `map/embed.html`
(cache-buster `?v=2.8.3` u app.js i style.css). Kontrola: pod grafem jsou hodnoty jako
štítky vedle sebe (např. „● PM10 28.2 μg/m³"), ne řádky přes celou šířku.

## 9. Doplněk 2.8.4 (7. 9. 2026) — texty, štítky, EAQI 2024 — NASAZENO 7. 9. 2026 ~16:40 (ověřeno živě: soubory identické, legenda, klik na štítek)

Nahrát přes FTP (přepsat): `map/app.js`, `map/style.css`, `map/info.js`, `map/index.html`,
`map/embed.html` (`?v=2.8.4` u app.js, style.css i info.js).
Kontrola: ℹ modal → lead končí „…je interpolace."; popup bez poznámky pod grafem; klik na štítek
„PM10 …" pod grafem přepne mapu i popup na PM10 a popup zůstane otevřený;
legenda PM2.5 začíná „0 - 5 (Dobrá)" a končí „140+ (Extrémně špatná)", PM10 „0 - 15 … 270+",
NO₂ „0 - 10 … 150+", O₃ „0 - 60 … 180+" — revidované EAQI 2024; méně zelené, u NO₂ víc
červené, u PM10 široká oranžová 45–120. Záměr.

## 10. Doplněk 2.8.5 (8. 9. 2026) — diagnostika výpadku výhledu + auto-reload staré záložky — NASAZENO 8. 9. 2026 ~13:10 (ověřeno: app.js/index/embed shodné, proxy vrací běh 2026-09-08T09; iPhone po zavření staré záložky bez poznámky – potvrzeno uživatelem)

Co se stalo: výhled ČHMÚ v grafu chyběl od 7. 9. ~16:00 SELČ do 8. 9. 12:51 SELČ. Cache
(`cache/forecast/meta.json`) zůstala na běhu `2026-09-07T12` s `refresh_failed: true`, i když
ČHMÚ běhy 13z–23z normálně vydal (ověřeno: `datumVytvoreni` v ZIPech čerstvé). Příčina první
části bez logů serveru nezjistitelná (nosicheck.cz s cronem uvízl stejně → spíš dostupnost
opendata.chmi.cz z hostingu než chyba kódu). Druhá část je jasná: ČHMÚ 8. 9. nevydal běhy
00z–08z (soubory h_00–h_08 mají last-modified 7. 9.), první nový je 09z v 12:38 SELČ.
Opraveno ručně: `forecast-proxy.php?token=CRON_TOKEN` (vynucený refresh) → běh `2026-09-08T09`,
14 krajů; ověřeno v mobilním i desktop zobrazení, graf má řady „Výhled" a „Rozpětí v kraji".

Změna: `forecast-proxy.php` při neúspěšném refreshi zapíše do `meta.json` klíč `last_error`
(čas + prvních 6 důvodů: HTTP kód/cURL chyba, „není ZIP", „žádný čerstvý soubor") a totéž do
`error_log`. Při úspěchu nebo stejném běhu se `refresh_failed`/`last_error` mažou.

Druhá změna — proč iPhone ukazoval poznámku „Výhled ČHMÚ (kraj): max… min…" ještě 8. 9., den
po jejím odstranění v 2.8.4: nebyla to cache serveru ani WordPressu (homepage posílá `no-cache`,
`index.html` i `embed.html` mají `max-age=0` a živě jsou shodné s lokálem, `app.js` shodný bajt po
bajtu na senzorvzduchu.cz i www.; iPhone měl otevřené přímo `/map/`). Safari drží záložku s mapou otevřenou celé dny a v ní běží jen 5min
obnovení dat starým JS (na screenshotu byly štítky z 2.8.3 + poznámka, tj. build z 7. 9.
15:00–16:40). `app.js` proto po 6 h od načtení stránku sám obnoví (při návratu do záložky,
při obnově z bfcache nebo při dalším 5min ticku; ne když je otevřený popup).

Nahrát přes FTP (přepsat): `forecast-proxy.php`, `map/app.js`, `map/index.html`, `map/embed.html`
(`?v=2.8.5` u app.js). Žádná změna DB ani configu.
Jednorázově na telefonu: zavřít záložku s mapou (nebo stáhnout stránku prstem dolů = reload) —
teprve tím se starý JS pustí; pojistka výše řeší až příští verze.
Kontrola: `https://senzorvzduchu.cz/cache/forecast/meta.json` — po výpadku obsahuje `last_error`.
Rychlá oprava při příštím výpadku (když ČHMÚ už zase vydává): otevřít
`https://senzorvzduchu.cz/forecast-proxy.php?token=<CRON_TOKEN z config.php>`.

Cron (8. 9. 2026): v administraci Active24 přidán URL cron `https://senzorvzduchu.cz/forecast-proxy.php?token=TOKEN`
každých 30 min (odpověď `{"ok":true,"run":…,"areas":14}`). Ostatní crony beze změny.

## 11. Doplněk 2.8.6 (8. 9. 2026) — podbarvení přepínače „24 h + výhled / 5 dní" — NASAZENO 8. 9. 2026 ~13:40 (ověřeno živě: soubory shodné, scénář 5 dní → jiný senzor → 24 h prošel)

Chyba: po volbě „5 dní" si graf rozsah pamatoval i u dalšího senzoru, ale modře svítilo
„24 h + výhled" (HTML popupu vzniká při vykreslení markeru se stavem z té doby) a klik na
„24 h + výhled" se ignoroval, protože tlačítko už bylo `active`. `wirePopupChart` teď při každém
otevření popupu srovná podbarvení podle skutečného rozsahu.

Nahrát přes FTP (přepsat): `map/app.js`, `map/index.html`, `map/embed.html` (`?v=2.8.6`).
Kontrola: zvolit „5 dní", zavřít, otevřít jiný senzor → graf 5 dní a modře „5 dní"; klik na
„24 h + výhled" přepne graf i tlačítko.

## 12. Doplněk 2.8.7 (9. 9. 2026) — interpolace v embedu ve výchozím stavu vypnutá — NASAZENO 9. 9. 2026 (ověřeno živě uživatelem)

`interp.js` pozná `embed.html` podle `location.pathname` a používá vlastní klíč
`sv_interp_embed` (výchozí vypnuto); plná mapa `/map/` má dál `sv_interp` (výchozí zapnuto).
Volba v iframe a na plné mapě se tak neovlivňují. Přepínač „Interpolace (odhad)" v legendě
embedu funguje dál, jen startuje šedý.

Nahrát přes FTP (přepsat): `map/interp.js`, `map/index.html`, `map/embed.html` (`?v=2.8.7`
u interp.js). Žádná změna DB ani configu.
Kontrola: `/map/embed.html` v anonymním okně → bez barevného pole, řádek „Interpolace (odhad)"
šedý; klik ho zapne. `/map/` v anonymním okně → pole zapnuté jako dřív.
