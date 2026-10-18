# TODO — senzorvzduchu.cz mapa 3.4 (stanice jiných provozovatelů) / 3.3 (auto-příznak indoor) / 3.2 (tmavý režim) / 3.1 (meteostanice ČHMÚ) / 3.0 (teplota a vlhkost)


## 3.4 — referenční stanice jiných provozovatelů (v produkci od 17. 10. 2026 ~20:25)

- [x] **Nasadit 3.4** — hotovo 17. 10. 2026 ~20:25 (FTP uživatelem), živě ověřeno (`DEPLOY_3.4.md` §7: `elapsed_ms 1855`, `count 30`, popup Radvanice ZÚ).
- [x] **Nahrát 3.4.1**: `map/index.html` + `map/embed.html` s `<title>Mapa - Senzorvzduchu</title>` — hotovo 17. 10. 2026 ~20:50, živě ověřeno.
- [ ] Po dni: 24h graf `CHMI_TORD` plný (1 bod/h), `monitor.php` bez hlášky; po týdnu posoudit Ústí n.L.-Pasteurova
      (PM 3,1/1,0 při 20–40 jinde) a Zlín (NO₂/O₃ 0,0 → null už řeší `ZERO_IS_NULL`).
- ~~E-mail ZÚ Ostrava a ČHMÚ~~ — **zamítnuto uživatelem 17. 10. 2026: žádné e-maily neposílat.**
- [ ] Oznámení (post, bez příkras): „+30 oficiálních stanic — Radvanice, Mariánské Hory, Brno-Svatoplukova…“.
- [ ] Nápad: SK (42) a AT (~46) stanice ze stejné vrstvy jako příhraniční reference (whitelist + souřadnice z EEA
      nebo převod UTM 33N); PL/DE přes GIOŚ a UBA (viz `../SCOPE_3.4-ref-stations.md` §5 a paměť). Bez pokynu neprovádět.
- [ ] Když zdroj změní tvar (ntfy z `monitor.php`): `chmi-other-proxy.php?debug=1` → `diag.probe` (404 na všech
      slotech = změna adresy), `diag.layers` (`stations 0` = změna tvaru `properties`).

## Rekordér dat na VPS (17. 10. 2026) — mimo verze mapy

- [x] **Rekordér běží** od 17. 10. 2026 14:55 UTC na VPS Hostinger (`recorder/` v kořeni projektu,
      `/srv/svz-recorder/` na VPS, cron po 5 min, bez retence, T/RH i surové PM). Podrobně `KONTEXT.md`
      sekce „Rekordér na VPS" a `recorder/README.md`. Tím je splněn předpoklad posouzení korekcí podle
      vlhkosti z `2.9-airgradient/TODO.md`.
- [x] **Webový náhled rekordéru** (`recorder/web/`, 17. 10. 2026): stav + senzory + graf + CSV za basic auth,
      nasazen na VPS (`install-web.sh`), ověřen přes ssh tunel. Heslo vypsáno jednou při instalaci
      (nové: `install-web.sh --reset-password`).
- [x] DNS A `data` → 31.97.33.229 přidán uživatelem 17. 10. 2026 (ověřeno 1.1.1.1, 8.8.8.8, ns1.active24.cz),
      certbot vydal certifikát (do 15. 1. 2027, auto-obnova) → **https://data.senzorvzduchu.cz** živě,
      HTTP přesměrováno, holá IP 404.
- [x] **Hlídač rekordéru** `recorder/monitor.php` (17. 10. 2026): ntfy.sh téma `senzorvzduchu-monitor`, cron po 15 min,
      kontroly cron_dead / fail / empty / disk / db_growth / web / db, opakování po 6 h, zpráva OK po zotavení.
- [ ] Externí dead-man ping pro výpadek celé VPS (healthchecks.io zdarma: ping z cronu po úspěšném `collect.php`,
      e-mail když pingy ustanou) — potřebuje účet uživatele; bez pokynu neprovádět.
- [ ] Po týdnu: `status.php` nebo https://data.senzorvzduchu.cz (chyby v `runs`, velikost DB, počet senzorů),
      velikost `/srv/svz-recorder/data`. (Pozn.: `chmi` vkládá nové řádky 1× za hodinu kolem :40 UTC — 0 nových
      v ostatních bězích je normální, ověřeno 17. 10. 2026.)
- [ ] Zpětný import historie: SC archiv (`archive.sensor.community`, denní CSV), ČHMÚ opendata, SCK API —
      zápis do `measurements` stejným schématem (PK dedupe). PA/AG/Libčich zpětně nejsou.
- [ ] Offsite kopie `backup/recorder-latest.db` (zatím jen na VPS).
- [x] Nosíček + Dancing Curves z VPS smazány 17. 10. 2026 15:04 UTC (zálohy lokálně + `/root/nc-final`,
      `/root/dc-final` na VPS — po ~měsíci smazat).
- [x] DNS nosicheck.cz (Active24/Websupport admin): A `*`, `@`, `www` → 31.97.33.229 smazány uživatelem
      17. 10. 2026, ověřeno z ns1.websupport.cz, 1.1.1.1 a 8.8.8.8 (žádný A záznam). Pošta (MX Active24,
      SPF/DMARC, mail/webmail/smtp/pop3/imap, autodiscover) ponechána; `admin.nosicheck.cz` → 37.9.175.24
      (Active24 hosting) ponechán jako neškodný zbytek.
- [ ] Nápad: datový portál za přihlášením nad DB rekordéru (prodej reportů/analýz) — před tím ověřit
      licence zdrojů (ODbL, CC BY-SA, PurpleAir, Libčich) a statut z.s.; bez pokynu neprovádět.

## 3.3 — před nasazením / po nasazení

- [x] Auto-příznak `indoor` implementován a ověřen lokálně 16. 10. 2026 (`DEPLOY_3.3.md` §5):
      `SensorQuality::indoorFlags()`, admin, info.js, test `tests/test_indoor_flag.php` ALL PASS.
- [x] `ChmiMeteo::STATIONS` +Poděbrady (`0-203-0-11617`, 5,5 km od páru AG 61860/78554) — bez ní nemá
      Polabí žádnou referenci do 30 km (nejbližší Jičín 41 km). 61 stanic.
- [ ] Nasadit 3.0 + 3.1 + 3.2 + 3.3 najednou (`DEPLOY_3.3.md` §1–2, 14 souborů), doplnit §6 „Ověřeno živě".
- [ ] **Ráno po první celé noci** po nasazení: `/admin/bans.php` → řádek `AG_61860 · indoor` (ΔT, ΔRH, rozkmit,
      n); `/sensor-bans.php` → `auto` obsahuje `{"sensor_id":"AG_61860","reason":"indoor"}`; mapa bez něj.
      Když se neobjeví: `chmi-meteo-proxy.php?debug=1` (Poděbrady v `stations`, ne v `missing`), v DB noční
      řádky `CHMI_M_0-203-0-11617` + `AG_61860` (T/RH 00–05 h). Ruční ban zůstává jistější záloha.
- [ ] Po týdnu projít seznam auto-příznaků `indoor` na všech sítích (SC má `location.indoor`, ale majitel
      ho nemusí nastavit) — falešné shody? Prahové hodnoty jsou `SensorQuality::INDOOR_*`.
- [ ] 17 komunitních senzorů nemělo (před Poděbrady) stanici do 30 km (Polabí Poděbrady–Kutná Hora);
      po nasazení ověřit, kolik jich zbývá bez reference (`communityCoords()` × `referenceStations()`).
      Kandidáti z meta1 do 30 km od Poděbrad s 10m souborem: Čáslav `0-203-0-11622`, Nový Bydžov `0-203-0-11625`.
- [ ] Léto: bez topení je ΔT menší — sledovat, zda 6 °C / 15 b. stačí; případně sezónní práh.
- [ ] **Auto-příznak `heated` (ohřev krytu sluncem)** — stejný mechanismus jako `indoor`, ale denní:
      medián (T_senzor − T_ČHMÚ nejbližší do 30 km) v 09–17 h > 8 °C při ≥ 4 párech **a zároveň** noční
      ΔT (00–05 h) < 4 °C (čidlo v noci sedí = je venku, jen se ohřívá; tím se odliší od `indoor`).
      Důsledek: skrýt jen teplotu a vlhkost senzoru (šedý marker / vynechat na vrstvách T a RH),
      PM ponechat; `sensor-bans.php` `auto[].reason = 'heated'`, admin `detail` = ΔT den / ΔT noc / stanice.
      Konkrétní případ 17. 10. 2026: **SC_70222/70223** (Olomouc, 5 km od ČHMÚ Olomouc-Holice):
      noc 4–6 °C vs ČHMÚ 0,4–2,9 °C (Δ ~3 °C), ale 12–14 h 28,8 / 32,4 / 30,1 °C a 20 % RH vs
      ČHMÚ 14–16 °C / 43 % (Δ 13–17 °C); PM2.5 5 µg/m³ v normě. Žádný dnešní příznak nespustil
      (živé auto-příznaky hlídají jen PM) a `indoor` z 3.3 ho správně nechytí (noční Δ malá,
      denní rozkmit 28 °C). Zapsáno na pokyn uživatele 17. 10. 2026 — **bez pokynu neprovádět**;
      navazuje na body „ohřev krytů" v 3.0/3.1 níže.

## 3.2 — před nasazením / po nasazení

- [x] Tmavý režim implementován a ověřen lokálně 16. 10. 2026 (`DEPLOY_3.2.md` §5), jen `index.html`.
- [x] Oprava: `SensorQuality::normalizeId()` nepouštěla `AG_` → senzory AirGradient nešlo v adminu
      zablokovat (DEPLOY_2.9 tvrdil opak). Od 3.2 jde `AG_<id>` i `CHMI_M_` ne (ČHMÚ se neblokuje).
- [x] Nasazeno 3.0 + 3.1 + 3.2 najednou 16. 10. 2026 ~22:40 (ruční FTP uživatelem, 13 souborů),
      živě ověřeno (`DEPLOY_3.2.md` §6, `DEPLOY_3.1.md` §7).
- [x] Po ~1 dni: 24h grafy teploty/vlhkosti plné (komunitní i `CHMI_M_`) — ověřeno 17. 10. 2026 14:45,
      17/24 slotů od 22:00 (přesně od nasazení), auto-příznaky prázdné, meteo proxy 60/60 za 0,89 s.
      Zjištění: cron `collect.php` běží reálně po 15 min (řádky v :00/:15/:30/:45), ne po 5.
- [ ] Po 5 dnech 5denní graf.
- [ ] Po týdnu: velikost `air_quality_history.db` na serveru (odhad ~14–15 MB místo 10).
- [ ] **Zablokovat AG_61860 „Location 2"** (Poděbrady, stejné souřadnice jako 78554 „Předhradí") — od 3.3
      ho má chytit auto-příznak `indoor` (viz výše), ruční ban je přesto jistější:
      16. 10. 2026 večer 27,5 °C / 29 % RH vedle 13,5 °C / 59 % → zjevně v interiéru (PM2.5 37 vs 21).
      Admin: `/admin/bans.php?token=…`, ID `AG_61860`, důvod „interiér". Funguje až po nasazení 3.2
      (oprava `normalizeId`); do té doby jen přes `AirGradient::EXCLUDE_IDS` (vyžaduje FTP).
- [x] **Automatická detekce interiérových senzorů** (všechny komunitní sítě) → **hotovo jako 3.3**
      (`3.3-indoor-flag/`, `DEPLOY_3.3.md`). Původní spec:
      od 3.0 má DB T/RH každých 5 min, od 3.1 referenci ČHMÚ. Heuristika v `SensorQuality` (jako
      auto-příznaky PM, samoléčivá, s tlačítkem „Zablokovat trvale" v adminu): za posledních 24 h
      v nočních hodinách (0–5 h místního času, bez slunce) medián (T_senzor − T_ČHMÚ nejbližší
      do 30 km) > 6 °C **a** medián (RH_ČHMÚ − RH_senzor) > 15 bodů **a** denní rozkmit T senzoru
      < 4 °C (interiér je stabilní) → příznak `indoor` → senzor skrytý z mapy, interpolace i kolektoru
      (jako ban). Ověřit nejdřív na známých párech: AG 61860/78554 Poděbrady, SC indoor flag
      (Sensor.Community má `location.indoor`, PM senzory s ním se už vynechávají — ale majitel ho
      nemusí nastavit). V zimě topná sezóna dělá rozdíl ještě větší; v létě bez topení/klimatizace
      slabší — proto i RH.

## 3.1 — před nasazením / po nasazení

- [x] Implementováno a ověřeno lokálně 16. 10. 2026 (`DEPLOY_3.1.md` §6): 60 meteostanic ČHMÚ,
      proxy + lib + offline test, kolektor (dedupe), popup s grafem, embed.
- [ ] Nasadit spolu s 3.0 (ruční FTP, pořadí v `DEPLOY_3.1.md` §2), doplnit §7 „Ověřeno živě".
      **Klíčová kontrola z hostingu**: `/chmi-meteo-proxy.php?debug=1` → `http_code 200`, `missing []`,
      `elapsed_s` (60 paralelních stažení z opendata.chmi.cz; výhled uvízl 7.–8. 9. 2026 právě na
      dostupnosti opendata z Active24). Když je pomalé → snížit `CHMI_METEO_CONCURRENCY` nebo počet stanic.
- [ ] Po ~1 dni: 24h graf meteostanice plný (24 bodů, 1 bod/h — zdroj publikuje hodinově); po 5 dnech
      5denní; `age_min` v proxy by neměl překračovat ~85 min (jinak ČHMÚ změnil kadenci publikace →
      upravit `STALE_SEC`/TTL).
- [ ] Po půlnoci UTC ověřit přechod dnů (`dates()` zkouší včerejšek, když dnešní soubor ještě není).
- [ ] Seznam stanic: `ChmiMeteo::STATIONS` je ruční; `?debug=1 → missing` ukáže stanice bez souboru.
      Kandidáti k doplnění (mají 10m soubor, ověřeno 16. 10.): Doksany, Kocelovice, Luká, Krnov,
      Holešov, Moravské Budějovice. Strop ~60 kvůli zátěži.
- [ ] Teď je reference vidět vedle komunitních čidel → **auto-příznak ohřevu krytu**: komunitní
      teplota > nejbližší meteostanice ČHMÚ do ~30 km + 8 °C → šedý marker (navazuje na bod v 3.0).
- [ ] Nápad: interpolace teploty jen ze stanic ČHMÚ (60 bodů je na IDW řídké; spíš s doplněním
      stanic nebo jako „noční" režim, kdy komunitní čidla sedí). Bez pokynu neprovádět.
- [ ] Oznámení 3.0 + 3.1 (post) — bez příkras: teplota a vlhkost ze ~160 komunitních míst +
      60 meteostanic ČHMÚ jako reference, graf 5 dní, komunitní hodnoty orientační (kryt na slunci).


## 3.0 — před nasazením / po nasazení

- [x] Implementováno a ověřeno lokálně 16. 10. 2026 (`DEPLOY_3.0.md` §6).
- [ ] Nasadit (ruční FTP, pořadí v `DEPLOY_3.0.md` §2), doplnit §7 „Ověřeno živě".
- [ ] Po ~1 dni: 24h graf teploty/vlhkosti plný; po 5 dnech 5denní. **Po týdnu velikost
      `air_quality_history.db` na serveru** (odhad ~14–15 MB místo 10; SC dedupe část ubere).
- [ ] Ohřev krytů: 16. 10. 16:30 bylo 10 komunitních senzorů nad 25 °C a jeden 44,9 °C
      při ČHMÚ 14–18 °C. Zvážit automatický příznak jako u PM (odchylka od mediánu sousedů
      do 30 km, např. > 8 °C) → šedý marker místo červeného. Zatím jen text v ℹ.
- [ ] Vlhkost 100 % (orosené čidlo, Libčich 11/43): zvážit zobrazení „≥ 99 %" šedě.
- [x] **ČHMÚ meteo jako referenční vrstva** → hotovo jako **3.1** (`3.1-chmi-meteo/`, podmnožina
      60 stanic po 15 min, `chmi-meteo-proxy.php`); interpolace jen z ČHMÚ zůstává jako nápad (výše).
- [ ] Oznámení 3.0 (post) — sloučeno s oznámením 3.1 (viz výše).
- [ ] Hodnoty T/RH pro posouzení korekcí PM (sekce níže) se od 3.0 ukládají i na mapě,
      ale jen 5 dní — rekordér na VPS stále potřeba.

## 2.9 — před nasazením / po nasazení

- [x] Nasazena 2.9 (16. 10. 2026 ~16:00, ruční FTP, živě ověřeno — `DEPLOY_2.9.md` §7).
- [x] Ověřeno `/ag-proxy.php?debug=1` → `http_code: 200` z produkce (16. 10. 2026; hosting dosáhne na
      api.airgradient.com; lokálně OK 16. 10. 2026).
- [x] Po ~1 dni ověřit, že se plní historie `AG_` — ověřeno 17. 10. 2026 (AG_65150 PM2.5 23/24, T/RH 17/24).
- [ ] Rozhodnout o hraničních senzorech: teď **vyřazeny** podle ID (`AirGradient::EXCLUDE_IDS`,
      6 ks DE/PL/SK) stejně jako u PurpleAir. Alternativa = nechat (jako Sensor.Community).
      Seznam je ruční — nové zahraniční senzory v boxu se objeví na mapě, dokud se nepřidají.
- [ ] 61860 „Location 2" + 78554 „Předhradí" mají stejné souřadnice (u Poděbrad) → překryv
      markerů; možná dvojice vedle sebe. Zatím ponecháno, případně blokovat jeden v adminu.
- [ ] Sledovat duplicity: stejní majitelé mohou mít i Sensor.Community senzor na stejném místě.
      Ověřeno 16. 10. 2026: v celé ČR jen **jedna dvojice do 150 m** — Vestec: AG 156556
      (O-1PST, PMS5003) × SC #75038 (SDS011) + #75039 (BME280), 11 m od sebe, jiný hardware,
      jiné hodnoty (např. 22.3 vs 6.9 µg/m³ ve 13:52 UTC). **Není duplicita, ponechat oba** —
      je to jediný pár PMS5003 × SDS011 vedle sebe, užitečný pro posouzení korekcí (níže).
- [ ] Fáze 2 (podle `notes.txt`): ověřit, zda AirGradient senzory nejsou i v OpenAQ (delší
      historie bez vlastního sběru). Vlastní sběr už běží přes `collect.php` (5 min).
- [ ] Oznámení 6. sítě (post) — stylem bez příkras; spolu s větou o barevné škále EAQI 2024
      (viz níže) a atribucí AirGradient CC BY-SA 4.0.
- [ ] Aktualizovat OG obrázek / texty webu, kde se píše „5 sítí" (meta v HTML už říká 6).

## Korekce podle vlhkosti — posouzení dat (zadáno 16. 10. 2026)

- [ ] **Důkladně posoudit korekce PM2.5 podle vlhkosti** napříč sítěmi: AirGradient (surový
      PMS5003, na mapě 16. 10. zjevně výš než ČHMÚ a korigovaný PurpleAir v okolí Prahy),
      Sensor.Community (SDS011 bez korekce, známá citlivost nad ~70 % RH), Smart Citizen.
      Metoda: dvojice komunitní senzor ↔ stanice ČHMÚ do ~2 km, regrese surové PM2.5 proti
      referenci s RH senzoru jako kovariátou (vzor Barkjohn 2021), ověřit, zda vzorec pro
      PurpleAir sedí i na PMS5003 v AirGradientu. Potřeba týdny až měsíce dat (RH 20–95 %,
      podzimní mlhy, zimní inverze), ne 5 dní.
- [ ] **Předpoklad: záznam dat.** Mapa dnes drží jen 5 dní a NEUKLÁDÁ vlhkost ani teplotu
      (`collect.php` jen PM2.5/PM10/NO₂/O₃/hluk). Historii zpětně dodá jen Sensor.Community
      (archive.sensor.community, denní CSV), ČHMÚ (opendata) a Smart Citizen (API); PurpleAir
      (placené body), AirGradient (jen org token) a Libčich zpětně nejsou → **začít nahrávat
      hned**, každý den čekání je ztracený.
- [ ] Rozhodnout kde nahrávat: (a) rekordér na VPS Hostinger (kde běží nosíček: cron, git,
      disk) — každých 5 min stáhne 6 veřejných proxy senzorvzduchu.cz + RH/T, ukládá do vlastní
      DB bez retence, nulový zásah do produkce; (b) levná varianta: na Active24 přidat
      `value_type` humidity/temperature a prodloužit retenci (riziko: sdílený hosting, SQLite
      ~10 MB/5 dní → ~120 MB/60 dní, cleanup už jednou selhal 30. 6.). Doporučení: (a).
- [ ] **Další krok: spec rekordéru na VPS** (stavět v repu NOSICHECK, kde je cron a deploy):
      schéma (sensor_id, network, measured_at ze zdroje, value_type vč. humidity/temperature,
      value), zdroje = 6 proxy senzorvzduchu.cz každých 5 min (PA proxy má cache 15 min, AG 3 min,
      ČHMÚ hodinově → dedupe podle časové značky zdroje), bez retence, noční export do měsíčních
      komprimovaných souborů. Odhad objemu: ~1 100 hodnot/běh, po dedupe ~150 k řádků/den
      (~2 GB/rok SQLite, stovky MB/rok komprimovaně). Zpětně hned stáhnout SC archiv
      (archive.sensor.community), ČHMÚ opendata a SCK API; PA/AG/Libčich jen od spuštění.
      Cíl posouzení: páry senzor ↔ ČHMÚ do ~2 km (Praha, Brno), RH 20–95 %, podzim/zima.

## Převzato z 2.8 (stále otevřené)

- [x] **Zrcadlit na GitHub** — hotovo 18. 10. 2026: tato složka je checkout
      https://github.com/senzorvzduchu/map (veřejné, `main`, tagy `v2.5`–`v3.4` podle složek verzí).
      Mimo repo: `config.php`, cache, DB, `backup*/`, `EMAIL_ADMIN_LIBCICH.md`. Deploy zůstává ruční FTP.
- [ ] Téma ntfy `senzorvzduchu-monitor` je natvrdo v `www/historygraph/monitor.php` (a ve veřejném repu):
      přesunout do `config.php` a téma přejmenovat.
- [ ] Do `config.php` na serveru přidat `ADMIN_TOKEN` (jinak admin blokací používá CRON_TOKEN).
- [ ] Očima zkontrolovat podklad „Barevná" v normálním okně.
- [ ] Sledovat spotřebu CARTO dlaždic (2 vrstvy místo 1; free tier 5 M/měsíc).
- [ ] Popup polish (2.8 + 2.8.1–2.8.6) přenést i do nosíčku — zapsáno v `CODE/NOSICHECK/TODO.md`.
- [ ] V příštím postu jednou větou oznámit změnu barevné škály (EEA EAQI 2024, přísnější zelená).
- [ ] Nápad: chip „IKO" = oficiální index ČHMÚ jen u stanic ČHMÚ; bez pokynu neprovádět.
- [ ] Při změně `lib/Alerts.php` nebo `lib/Forecast.php` v nosíčku přenést i sem (kopie 1:1).

## Hotovo

- [x] 2.8 nasazena 4. 9. 2026, doplňky 2.8.1–2.8.7 do 9. 9. 2026.
- [x] 2.9 AirGradient postaveno a otestováno lokálně 16. 10. 2026 (`DEPLOY_2.9.md` §6).
- [ ] Drobnost (nejen 2.9): produkční PHP serializuje floaty s ~40 desetinnými místy
      (`serialize_precision`), vidět v `ag-proxy.php` i `pa-proxy.php`. Neškodí, jen větší JSON;
      oprava = `ini_set('serialize_precision', '-1')` na začátku proxy.
