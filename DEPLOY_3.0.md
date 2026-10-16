# Nasazení 3.0 (teplota a vlhkost) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **SANDBOX, ověřeno lokálně 16. 10. 2026** (port 8130). 3.0 = kopie 2.9 + teplota a vlhkost
jako sedmá a osmá „látka" v liště (mapa, legenda, popup, graf). Původní verze změněných
souborů: `backup-2.9/` (rychlý rollback).

## 1. Co se nahrává a k čemu to je

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `historygraph/collect.php` | změna | ukládá `temperature` / `humidity` ze všech 5 komunitních sítí; SC: jeden záznam na senzor a běh (dřív 2× stejný čas) | cron (beze změny) |
| `historygraph/get_history.php` | změna | whitelist `?type=temperature\|humidity`; hodinový slot u nich PRŮMĚR; bez heuristik ucpání | app.js (graf) |
| `pa-proxy.php` | změna | teplota −8 °F, vlhkost +4 % (korekce US EPA pro čidlo PurpleAir) | app.js, collect.php |
| `map/app.js` | změna | registr `temperature` + `humidity` (vlastní barvy, `openLow`, `valid`, `meteo`), `plausible()`, legenda „< 0", graf bez gradientu/nuly, aktivní čip se na mobilu posune do záběru | obě HTML |
| `map/info.js` | změna | odstavec Teplota a vlhkost, barevná škála, průměr v grafu, „neinterpoluje / nepředpovídá", ČHMÚ skryto | obě HTML |
| `map/index.html`, `map/embed.html` | změna | čipy 🌡 Teplota + 💧 Vlhkost, `aria-label`, meta „teplota a vlhkost", `?v=3.0` | návštěvník |

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/air_quality_history.db`,
`backup-2.9/`, `tests/`, `*.md`, `notes.txt`, `cache/`, `*_cache.json`.

**Beze změny v 3.0** (na serveru už jsou z 2.8/2.9): `style.css`, `interp.js`, `wind.js`,
`alerts.js`, `orp-areas.js`, `lib/*`, ostatní proxy, `admin/`.

## 2. Postup

1. FTP nahrát v pořadí: `historygraph/collect.php` + `get_history.php` → `pa-proxy.php` →
   `map/app.js`, `info.js` → `map/index.html` + `embed.html`. Backend dřív než frontend.
2. Zkontrolovat (v prohlížeči):
   - `/historygraph/get_history.php?id=SC_71641&type=temperature&hours=24&step=hour` → JSON
     (ne 400 „Invalid value type"); `"bucket":"avg"`. Body se plní od prvního běhu cronu po
     nahrání, 24h graf je plný za den, 5denní za 5 dní.
   - `/pa-proxy.php` → `temperature` o ~4 °C níž než před nasazením, `humidity` o 4 výš.
   - `/map/` → v liště 7 čipů + ℹ; „🌡 Teplota": ~160 barevných markerů, ČHMÚ zmizí,
     legenda „Teplota (°C)" s řádkem „< 0 (Mráz)", řádek Interpolace zešedlý; popup: hero
     „Teplota vzduchu", štítek Vlhkost s tečkou (klik přepne), graf „24 hodin" (bez výhledu)
     / „5 dní". „💧 Vlhkost" totéž s okrovou→modrou škálou, hero bez desetin.
   - `/map/embed.html` → totéž.
   - modal ℹ → odstavec „Teplota a vlhkost" v sekci látek, věta o barvách v sekci škály.
   - mobil: lišta se posouvá, aktivní čip je v záběru i po přepnutí z popupu.
3. Cron se **nemění**. `config.php` se **nemění**.

## 3. Rozhodnutí a proč

- **Plnohodnotná vrstva, ne jen graf.** Registr `POLLUTANTS` řídí lištu, hero, štítky,
  legendu, clustery Libčiche i graf; varianta „jen graf v popupu" by potřebovala druhý stav
  (co je v grafu vs. co je na mapě) a byla by složitější, ne jednodušší.
- **Vlastní barvy** (`TEMP_COLORS` modrá→červená, `HUM_COLORS` okrová→modrá): škála EAQI je
  zdravotní (zelená = dobrá), u 15 °C nedává smysl. Hranice teploty 0/10/20/25/30 °C, vlhkosti
  30/50/70/85/95 %. `openLow` → první řádek legendy „< 0".
- **Bez interpolace** (`interp: false`): čidla sedí v krytech senzorů, na slunci jdou o
  5–25 °C výš než budka (16. 10. 2026 16:30: ČHMÚ Karlov 15,6 °C, Libuš 18,5 °C, Ruzyně
  14,4 °C; komunitní medián 17 °C, ale maximum 44,9 °C a 10 senzorů nad 25 °C). Pole z toho by
  bylo pole chyb čidel. Vlhkost u orosených čidel visí na 100 % (Libčich 11 ze 43).
- **Bez výhledu**: krajský výhled ČHMÚ je jen pro látky.
- **ČHMÚ skryto** (ne prázdné kolečko): ČHMÚ na této mapě teplotu nedodává. Otevřená data
  ČHMÚ (`opendata.chmi.cz/meteorology/climate/now/`) ji mají — 10min data pro ~950 stanic
  (Praha: Ruzyně, Karlov, Libuš, Kbely, Komořany, Klementinum, …), ale **jeden JSON na stanici
  a den (~110 kB)**, žádný souhrnný soubor → stahovat všechny každých 10 min na Active24 nejde.
  Podmnožina (města) je realizovatelná — samostatná verze.
- **Hodnoty mimo fyzikální rozsah** (`valid`: teplota −40…60 °C, vlhkost 0…100 %) se na
  mapě ani v popupu neukazují a `collect.php` je neukládá. Důvod: 7 senzorů Sensor.Community
  hlásilo 16. 10. 2026 −141 až −147 °C (vadný DHT22).
- **PurpleAir korekce** (−8 °F, +4 % RH): hodnoty US EPA pro čidlo PurpleAir (Barkjohn 2021,
  mapa AirNow). Bez ní svítil PA mezi sousedy jako tepelný ostrov. Vlhkost pro korekci PM2.5
  (Barkjohn) zůstává SUROVÁ — úprava se dělá až po výpočtu PM.
- **Hodinový slot = průměr** (ne maximum jako u látek): maximum by u teploty posouvalo celou
  křivku nahoru o špičku čidla v krytu. Heuristiky „stuck/plateau" se na meteo nepouštějí
  (konstantní noční teplota není porucha).
- **SC dedupe za běh**: `sc-proxy.php` vrací pro většinu senzorů dva záznamy (dvě měření za
  posledních 5 min), kolektor je dřív zapsal oba se stejným `time()` → polovina řádků `SC_`
  v DB byla duplicitní (ověřeno: 211 z 406 řádků PM2.5). Od 3.0 jeden záznam na senzor a běh.
  Týká se i PM — graf 5 dní má stejné body, jen bez dvojic.

## 4. Databáze historie

- `air_quality_history.db` se nenahrává; schéma beze změny (`value_type` TEXT). Přibývají
  řádky `temperature` a `humidity`: jeden běh = ~470 řádků (po SC dedupe), tj. ~135 k/den,
  ~0,7 M řádků za 5denní retenci. Produkční DB má dnes ~10 MB/5 dní → odhad ~14–15 MB.
  SC dedupe naopak ubere ~30 % řádků PM. Cleanup je časový, beze změny. **Po týdnu ověřit
  velikost DB na serveru** (TODO).
- Zdroje: SC teplota/vlhkost z čipu BME280/DHT22 stejné `location` (jako app.js), SCK ID 55/56,
  PA/AG z proxy (AG jen `online`), Libčich z výchozího režimu proxy (2min intervaly, dedupe
  podle `measured_at`; `/api/aqi` teplotu nemá).

## 5. Rollback

Nahrát zpět soubory z `backup-2.9/` (`map/app.js`, `info.js`, `index.html`, `embed.html`,
`historygraph/collect.php`, `get_history.php`) a `pa-proxy.php` z 2.9. Řádky `temperature` /
`humidity` v DB nevadí (frontend 2.9 je nezná, vyprší za 5 dní).

## 6. Ověřeno lokálně 16. 10. 2026 (PHP built-in server, port 8130)

- Kolektor jeden běh: 1 648 řádků; `temperature` 232 senzorů, `humidity` 241 senzorů
  (SC 83/82 z 104 PM lokací, SCK 11, PA 9/10, Libčich 43, AG 23). Druhý běh po SC dedupe:
  duplicitní dvojice SC_ nepřibývají.
- `/map/index.html`: 7 čipů + ℹ; Teplota → 157 markerů, ČHMÚ 0, legenda 6 řádků od „< 0
  (Mráz)"; 7 senzorů s −14x °C skryto; popup SC_71641 hero „23.1 °C / Teplota vzduchu", graf
  „24 hodin" s hodinovými průměry, štítky Vlhkost/Tlak/PM1/PM2.5/PM10, Vlhkost s tečkou.
  Vlhkost → 163 senzorů, legenda „0 - 30 (Sucho)" … „95+ (Nasyceno)".
- Mobil 375 px: lišta 472 px široká se posouvá; po kliknutí na Vlhkost `scrollLeft` 117,
  aktivní čip v záběru. `embed.html`: 7 čipů, Teplota 157 markerů, Interpolace zešedlá.
- Konzole bez chyb z app.js (jeden 404 na externí zdroj, nesouvisí).

## 7. Ověřeno živě (po FTP)

- (doplnit po nasazení)
