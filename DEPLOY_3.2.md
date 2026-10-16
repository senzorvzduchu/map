# Nasazení 3.2 (tmavý režim) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **SANDBOX, ověřeno lokálně 16. 10. 2026** (port 8132). 3.2 = kopie 3.1 (3.0 teplota a vlhkost
+ 3.1 meteostanice ČHMÚ, obojí **nenasazeno**) + přepínač tmavého režimu 🌙/☀️ vedle ℹ v horní
liště, **jen fullscreen mapa** (`index.html`); `embed.html` zůstává světlý. Původní verze změněných
souborů: `backup-3.1/`. **Nasazuje se 3.0 + 3.1 + 3.2 najednou** — soubory 3.2 obsahují vše z 3.0
a 3.1 (`DEPLOY_3.0.md` §1 a `DEPLOY_3.1.md` §1 platí dál, tady jsou jen rozdíly 3.2).

## 1. Co se nahrává (rozdíly 3.2)

| Soubor na serveru | Změna | K čemu |
|---|---|---|
| `map/app.js` | změna | `applyTheme()`, `isDarkTheme()`, přepínač `#themeToggle` (jen když v DOM je), podklad `dark` (CARTO dark_nolabels + dark_only_labels s klíčem), `body.basemap-dark`, světlý kroužek ČHMÚ v tmavém režimu, barvy grafu podle režimu, inline barvy hero → proměnné; ochrana: embed nikdy nedostane tmavý podklad z localStorage |
| `map/style.css` | změna | blok `html[data-theme="dark"]` (výměna proměnných + Leaflet + popup-quality + výstrahy + brandové texty/výplně), `body.basemap-dark .interp-overlay { mix-blend-mode: screen }` |
| `map/index.html` | změna | `<meta theme-color>`, inline skript v `<head>` (data-theme před prvním vykreslením), tlačítko `#themeToggle` za ℹ, tlačítko podkladu „Tmavá", `style.css?v=3.2`, `app.js?v=3.2` |
| `map/embed.html` | změna | jen `style.css?v=3.2`, `app.js?v=3.2` (sdílené soubory se změnily) — žádný přepínač, žádný tmavý podklad |
| `lib/SensorQuality.php` | změna | `normalizeId()` přijímá i `AG_` — AirGradient nešlo v adminu zablokovat (DEPLOY_2.9 tvrdil opak; kolektor i app.js ban `AG_` už respektovaly) |
| `admin/bans.php` | změna | text chyby a placeholder uvádějí `AG_123` |

Plus celý seznam z `DEPLOY_3.1.md` §1 (10 souborů; `map/app.js`, `index.html`, `embed.html` z něj
nahradit verzí 3.2). Celkem na FTP: `lib/Http.php`, `lib/ChmiMeteo.php`, `chmi-meteo-proxy.php`,
`historygraph/collect.php`, `historygraph/get_history.php`, `pa-proxy.php`, `map/app.js`,
`map/info.js`, `map/style.css`, `map/index.html`, `map/embed.html`, `lib/SensorQuality.php`,
`admin/bans.php` — 13 souborů, vše ze `3.2-dark-mode/www/`.

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`, `backup-3.1/`,
`tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

## 2. Postup

Pořadí podle `DEPLOY_3.1.md` §2 (backend → frontend), frontend z 3.2: `map/app.js`, `info.js`,
`style.css` → `map/index.html` + `embed.html`. Kontrola navíc pro 3.2:

- `/map/` → v liště za ℹ tlačítko 🌙; klik: panel, legenda, popup a lišta ztmavnou, podklad se
  přepne na „Tmavá", tlačítko se změní na ☀️; druhý klik vrátí světlý režim i předchozí podklad.
  Volba se pamatuje (`sv_theme`). Bez volby jde režim podle systému (prefers-color-scheme).
- Tmavý režim + „🌡 Teplota": kroužek meteostanic ČHMÚ je světlý (ne černý); popup graf má
  světle modrou čáru a světlé mřížky; žluté varování v popupu je tmavě žluté.
- Interpolace na tmavém podkladu svítí (screen), na světlém násobí (multiply) — přepíná se podle
  podkladu, ne podle režimu (světlý režim + podklad „Tmavá" = také screen).
- `/map/embed.html` → beze změny vzhledu: světlý, bez 🌙, podklady jen Světlá / Barevná / OSM,
  i když v témže prohlížeči je fullscreen mapa tmavá.
- `/admin/bans.php?token=…` → zablokovat `AG_61860` („Location 2", Poděbrady, interiér) — od 3.2
  formulář `AG_` přijme; senzor zmizí z mapy do 10 min (cache), z kolektoru hned.

## 3. Rozhodnutí a proč

- **Port z nosíčku** (`nc_theme` → `sv_theme`, `nc_basemap_prev` → `sv_basemap_prev`), stejná
  logika: inline skript v `<head>` nastaví `data-theme` před prvním vykreslením (bez bliknutí),
  uložená volba vítězí nad systémem, změnu v OS mapa sleduje jen dokud uživatel nezvolil ručně.
- **Jen index.html** (zadání): embed nemá skript v hlavičce ani tlačítko → `data-theme` nikdy
  nedostane. Protože localStorage sdílí obě slupky, přepínač v indexu ukládá `sv_basemap = dark`;
  embed proto při startu `dark` ignoruje a vezme světlý (jinak by měl tmavé dlaždice pod světlým
  rozhraním — ověřeno a opraveno lokálně).
- **Podklad jde s režimem**: tmavý režim → „Tmavá" (uloží se předchozí volba), světlý → zpět.
  Ruční volba podkladu v legendě vítězí (uživatel může mít tmavé rozhraní na světlé mapě).
- **Brandová modř #21275F** není na tmavém panelu vidět jako text ani jako výplň → v tmavém
  režimu text světlá indigo (#a5b4fc), výplně sekundární modř (#3845A6). Výjimka proti nosíčku,
  který má světlejší brand.
- **Kroužek ČHMÚ** (černý) by na tmavých dlaždicích zmizel → `#e5e7eb` v tmavém režimu
  (marker i tečka v legendě). Tečka Smart Citizen (tmavě šedá čárkovaná) → světle šedá.
- **Graf**: čára `#A5B4FC` místo `#21275F`, mřížka a popisky os světlé, půlnoční čára světlá;
  gradient zelená→žlutá→červená u látek zůstává (na tmavém plátně funguje). Barvy se vyhodnotí při
  každém `new Chart` → přepnutí režimu překreslí markery (`renderAllSensors`), otevřený popup si
  graf nahraje znovu stejnou cestou jako při přepnutí látky.
- **CARTO**: dark dlaždice se stejným `?key=` (bez klíče vodoznak), stále 2 vrstvy (base + labels).
  Spotřeba stejná jako u světlé.

## 4. Rollback

Nahrát zpět `map/app.js`, `style.css`, `index.html` z `backup-3.1/map/` a `embed.html` z 3.1
(`3.1-chmi-meteo/www/map/embed.html`). Hodnoty `sv_theme` / `sv_basemap = dark` v prohlížečích
návštěvníků nevadí: starší app.js tmavý podklad nezná a spadne na světlý.

## 5. Ověřeno lokálně 16. 10. 2026 (port 8132)

- Prohlížeč s tmavým systémovým režimem: mapa se načte rovnou tmavě (`data-theme=dark`, tlačítko
  ☀️, podklad `dark`, `body.basemap-dark`), interpolace screen. Klik ☀️ → světlý režim, podklad
  `light`, `sv_theme=light`, kroužek ČHMÚ `#000000`, meta theme-color `#21275F`.
- Klik 🌙 s otevřeným popupem (Frýdek-Místek, PM2.5): popup `rgb(31,41,55)`, čára grafu
  `#A5B4FC`, mřížka `rgba(255,255,255,0.08)`, varování `rgb(59,52,16)`, kroužek ČHMÚ `#e5e7eb`.
  Legenda: titulek a ℹ `rgb(165,180,252)`, aktivní čip viditelný.
- `embed.html` v témže prohlížeči: `data-theme` žádný, bez tlačítka, bez „Tmavá", podklad `light`
  i když index uložil `dark`. Konzole bez chyb.
- Mobil a 3.0/3.1 funkce beze změny (jen barvy).

## 6. Ověřeno živě (po FTP)

- Nasazeno 16. 10. 2026 ~22:40 (ruční FTP uživatelem, 13 souborů = 3.0 + 3.1 + 3.2, pořadí §2 /
  `DEPLOY_3.1.md` §2). Backend ověřen před frontendem (`DEPLOY_3.1.md` §7).
- `index.html` živě: `style.css?v=3.2`, `app.js?v=3.2`, `id="themeToggle"`, `data-basemap="dark"`,
  `data-pollutant="humidity"`. `embed.html` živě: `?v=3.2` u style/app, **bez** `themeToggle` a bez
  „Tmavá". Živý `app.js` obsahuje `applyTheme`, `BASEMAPS.dark`, `TEMP_COLORS`; živý `style.css`
  má blok `data-theme="dark"`.
- Uživatel potvrdil mapu funkční („confirmed and working"). Prohlížečový panel Clauda na
  senzorvzduchu.cz nepustil (schválení webu), ověření očima bylo na straně uživatele.
- Otevřené po nasazení: zablokovat `AG_61860` v adminu (teď už `AG_` projde); po dni plné 24h grafy;
  po týdnu velikost DB.
