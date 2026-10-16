# 3.1-chmi-meteo — Lokální testování (sandbox)

> Stav 3.1: **ověřeno lokálně 16. 10. 2026**, nenasazeno. Výchozí bod: kopie `3.0-meteo`;
> původní verze změněných souborů: `backup-3.0/`.
> Server 3.1: `.claude/launch.json` → `chmi-meteo-sandbox`, port **8131** (8130 = 3.0, 8129 = 2.9).
> Test: `php tests/test_chmi_meteo.php` (ALL PASS). Kolektor lokálně: příkaz níže s `PROXY_BASE_URL`
> = `http://127.0.0.1:8131`. Před prvním během smazat `www/cache/chmi_meteo*.json`, aby proxy
> stáhla čerstvě; `?debug=1` ukáže stanice bez souboru (`missing`). Co testovat očima: „🌡 Teplota"
> → ~60 markerů s černým okrajem mezi komunitními, popup „Praha, Karlov" (zdroj „ČHMÚ, meteorologická
> stanice (oficiální), 261 m n. m.", graf), zpět na PM2.5 → meteostanice zmizí bez prázdných koleček.
> Podrobně `DEPLOY_3.1.md` §2, §6.

# 3.0-meteo — Lokální testování (sandbox)

> Stav 3.0: **ověřeno lokálně 16. 10. 2026**, nenasazeno. Výchozí bod: kopie `2.9-airgradient`;
> původní verze změněných souborů: `backup-2.9/`.
> Server 3.0: `.claude/launch.json` → `meteo-sandbox`, port **8130** (8129 = 2.9, 8128 = 2.8).
> Kolektor lokálně: stejný příkaz jako níže s `PROXY_BASE_URL` = `http://127.0.0.1:8130`.
> Co testovat očima: lišta „🌡 Teplota" / „💧 Vlhkost" (ČHMÚ zmizí, Interpolace zešedne,
> legenda od „< 0"), popup (hero, štítek druhé veličiny s tečkou, graf „24 hodin" bez výhledu,
> „5 dní"), mobil (lišta se posouvá, aktivní čip v záběru), embed. Podrobně `DEPLOY_3.0.md` §2, §6.

# 2.9-airgradient — Lokální testování (sandbox)

> Stav 2.9: **AirGradient nasazen 16. 10. 2026 ~16:00 a živě ověřen** (`DEPLOY_2.9.md` §7).
> Výchozí bod 2.9: kopie `2.8-multipollutant`; původní verze změněných souborů: `backup-2.8.7/`.
> Server 2.9: `.claude/launch.json` → `airgradient-sandbox`, port **8129** (níže uvedený 8128 patří 2.8).
> Test 2.9: `php tests/test_airgradient.php` (ALL PASS); ověření: `DEPLOY_2.9.md` §2 a §6.
>
> Stav 2.8: **implementováno a otestováno lokálně 4. 9. 2026 (dvě kola).**
> Výchozí bod: kopie `2.7-carto-key` (LIVE). Původní verze změněných souborů: `backup-2.7/`.

## Co je nového (převzato z nosicheck.cz)

Kolo 1 (lišta, interpolace, vítr):
- **Lišta látek** nahoře: PM2.5 · PM10 · NO₂ · O₃ · 🔊 Hluk · ℹ. Přepíná barvy markerů,
  pásma legendy, hero v popupu i graf. Volba se pamatuje (`sv_pollutant`). Na mobilu centrovaná.
- **Interpolační vrstva** (IDW, váhy sítí ČHMÚ 1.0 > PurpleAir 0.25 > SCK 0.15 > SC = Libčich 0.10,
  unášení větrem, nic dál než 25 km od senzoru). Zapnutá ve výchozím stavu, vypíná se v legendě.
- **Vítr** (leaflet-velocity, Open-Meteo 15×12 bodů, hodinově). Vypnutý ve výchozím stavu.
- **Popisky map nad interpolací**, přepínač podkladu Světlá / Barevná / OSM.
- **Modal „Jak mapa funguje"**, ČHMÚ bez zvolené látky = prázdné kolečko.

Kolo 2 (výstrahy, předpověď, blokace):
- **Výstrahy ČHMÚ na kvalitu ovzduší** — lišta vyjíždějící zleva (stejná logika jako nosíček),
  jen kategorie smog (smogová situace, prach, ozon, NO₂, SO₂). Ostatní (bouřky, vítr…) se nezobrazují.
  Když nějaká platí, mapa se zeptá na polohu a ukáže jen výstrahy pro ORP návštěvníka; bez polohy
  celostátní přehled. **Bez platné výstrahy se mapa na polohu neptá.** Po 5 s se lišta stáhne do
  záložky, klik otevře plný text v modalu.
- **Graf v popupu**: výchozí **24 h historie + 24 h výhled ČHMÚ** („teď" uprostřed, hodinová MAXIMA),
  přepínač „5 dní" = surové body bez předpovědi (`sv_chart_range`). Předpověď je ukotvená na
  úroveň senzoru (průměr 3 h, jinak mapa ČHMÚ, clamp 0,33–3), světlý pás = rozpětí v kraji,
  jednořádková poznámka max/min pod grafem. Hluk bez předpovědi.
- **Ruční blokace senzorů**: `admin/bans.php?token=…` (bez účtů, chrání token), ban/unban,
  holé číslo = SC_. Blokovaný senzor zmizí z mapy, interpolace i kolektoru. Dřívější natvrdo
  vyloučené SC 46875 a 7213 jsou v tabulce od jejího vzniku, v kódu už nejsou.
- **Automatické příznaky kvality** (`SensorQuality`): za 2 h ≥ 12 vzorků PM, *stuck* (odchylka
  < 0,5 při průměru > 10) nebo *plateau* (odchylka < 3 při průměru > 60) → senzor se skryje
  z mapy i interpolace, samo se zruší. Vidět v adminu s tlačítkem „Zablokovat trvale".
- **Backend**: `wind-proxy.php`, `alerts-proxy.php`, `forecast-proxy.php`, `sensor-bans.php`,
  `admin/bans.php`, `lib/{Http,Alerts,Forecast,SensorQuality}.php` (nové); `sck-proxy.php` (+ hluk),
  `collect.php` (PM10/NO₂/O₃/hluk, blokace, validace), `get_history.php` (`?type=`, hodinový režim,
  výpadky, timezone). Vše bez cronu — cache se obnovuje při prvním požadavku po expiraci
  (zámek + fallback na starou cache), `?token=CRON_TOKEN` vynutí obnovu.
- Frontend rozdělen na sdílené soubory `app.js` / `interp.js` / `wind.js` / `info.js` / `alerts.js` /
  `orp-areas.js` / `style.css` + dvě HTML slupky.

## Rychlý start

V kořeni `2.8-multipollutant` spusť (nebo `.claude/launch.json` → `multipollutant-sandbox`):

```powershell
php -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -d display_errors=0 -S 127.0.0.1:8128 -t www
```

- **http://127.0.0.1:8128/map/index.html** — hlavní mapa
- **http://127.0.0.1:8128/map/embed.html** — embed varianta
- **http://127.0.0.1:8128/admin/bans.php?token=…** — blokace (token = CRON_TOKEN z lokální config.php,
  nebo ADMIN_TOKEN, pokud ho definuješ)

Offline testy (bez sítě, bez DB):

```powershell
php tests/test_alerts.php
php tests/test_forecast.php
```

Oba: `ALL PASS` (4. 9. 2026).

## Kolektor lokálně (bez znalosti tokenu)

Před prvním během smaž zkopírované cache (proxy servírují staré soubory podle mtime):

```powershell
Remove-Item www\cache\chmi_*.* , www\*_cache.json -ErrorAction SilentlyContinue
```

Pak (server musí běžet):

```powershell
php -d curl.cainfo="C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt" -r 'define("PROXY_BASE_URL","http://127.0.0.1:8128"); require "www/config.php"; $_GET["token"]=CRON_TOKEN; require "www/historygraph/collect.php";'
```

Ověřeno: první běh 806 řádků (ČHMÚ NO₂ 60 / O₃ 55 / PM10 81 / PM2.5 56 stanic, Libčich 43×2,
SCK 11×3, SC ~100×2, PA), druhý běh ČHMÚ/Libčich 0 nových (dedupe per hodina).

## Co testovat očima

1. **Lišta**: PM10 → NO₂ (jen ČHMÚ, ostatní prázdná kolečka) → O₃ → Hluk (jen SCK, „Interpolace" zešedne).
2. **Interpolace**: barevné pole, nic dál než ~25 km, vypnutí sítě v legendě ji odebere i z odhadu.
3. **Vítr**: klik na „Vítr" → částice + readout vpravo dole.
4. **Popup**: hero = aktivní látka; graf „24 h + výhled": šedá pravá polovina s čárkovanou
   předpovědí, pás rozpětí, poznámka „Výhled ČHMÚ (kraj): max… min…"; „5 dní" = surová historie.
   Přepnutí látky nad otevřeným popupem překreslí graf. Hlavička jde přes celou šířku bubliny,
   × je uvnitř hlavičky; na mobilu se popup po načtení grafu posune pod lištu i sbalenou legendu
   (×  vždy dostupné), obsah má vnitřní scroll, na desktopu se nevejde pod legendu vpravo.
5. **Libčich**: cluster barvou podle mediánu aktivní látky, ⌖ přiblíží.
6. **ℹ modal**: sekce sítě, látky, škála, interpolace, vítr, graf, předpověď, výstrahy, kontrola kvality.
7. **Podklad**: Světlá / Barevná / OSM.
8. **Mobil (≤768 px)**: lišta přes šířku s centrovaným obsahem, zoom pod ní, legenda sbalená.
9. **Embed**: totéž bez přepínače podkladu.
10. **Výstraha**: dnes žádná smogová neplatí → lišta se neukáže (správně). Vzhled se dá ověřit
    v konzoli: `ncAlertRender([{event:'Smogová situace',cat:'smog',level:3,inforce:true,regions:['Moravskoslezský kraj'],orp:[8101],expires:new Date(Date.now()+36e5).toISOString(),desc:'test'}],'cz')`.
11. **Admin**: ban `99999` → v `sensor-bans.php` přibude `SC_99999`, na mapě zmizí (po reloadu);
    unban → zmizí ze seznamu; ban `CHMI_…` → odmítnuto.

Ověřeno v Browser pane 4. 9. 2026: 1–6, 8–11 OK (výstraha přes syntetická data — karta, záložka
po 5 s, modal), žádné chyby v konzoli. Bod 7 „Barevná" zkontrolovat v normálním okně (skrytý panel
nespouští `requestAnimationFrame`, Leaflet nedokončí fade-in dlaždic).

## Diagnostika

```
http://127.0.0.1:8128/wind-proxy.php                                   ← X-Source: OpenMeteo-Live | Local-Cache | Local-Cache-Stale
http://127.0.0.1:8128/alerts-proxy.php                                 ← {generated, source, filter:"smog", alerts:[…]}
     www/cache/alerts_latest.json                                      ← všechny kategorie (jen ke kontrole)
http://127.0.0.1:8128/forecast-proxy.php?area=19&type=PM10&sensor=CHMI_ALEGA  ← anchor_src: sensor | chmi_map | null
     www/cache/forecast/meta.json                                      ← run, 14 krajů
http://127.0.0.1:8128/sensor-bans.php                                  ← {banned, auto}
http://127.0.0.1:8128/sck-proxy.php?debug=1                            ← hluk = klíč "53"
http://127.0.0.1:8128/historygraph/get_history.php?id=SCK_19410&type=noise
http://127.0.0.1:8128/historygraph/get_history.php?id=CHMI_ABRE&type=NO2&hours=24&step=hour
```

Kódy krajů pro `area=`: 19 Praha, 27 Středočeský, 35 Jihočeský, 43 Plzeňský, 51 Karlovarský,
60 Ústecký, 78 Liberecký, 86 Královéhradecký, 94 Pardubický, 108 Vysočina, 116 Jihomoravský,
124 Olomoucký, 141 Zlínský, 132 Moravskoslezský.

## Známé meze

- Historie PM10/NO₂/O₃/hluku a hodinový 24h pohled se na produkci naplní až po nasazení
  `collect.php` (plné za 5 dní; 24h pohled za den).
- První požadavek po expiraci cache čeká na zdroj: vítr ~2–5 s, výstrahy do ~10 s (jen když se
  bulletin změnil), předpověď ~5–15 s (4 ZIPy, jen když ČHMÚ vydal nový běh). Volitelné URL crony
  to schovají (viz MIGRATION.md).
- Token adminu zůstává v historii prohlížeče správce — proto samostatný `ADMIN_TOKEN`.
- Automatické příznaky nikdy nezachytí hodinové zdroje (Libčich) — ty nemají 12 vzorků za 2 h.
