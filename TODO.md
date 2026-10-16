# TODO — senzorvzduchu.cz mapa 3.1 (meteostanice ČHMÚ) / 3.0 (teplota a vlhkost)

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
- [ ] Po ~1 dni ověřit, že se plní historie `AG_` (24h graf v popupu AirGradient senzoru).
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

- [ ] **Zrcadlit na GitHub** (zatím NEPROVÁDĚT, jen zapsáno): složka není git repozitář
      (`.gitignore` je, `.git` ne). Cíl: soukromé repo se stejným rozvržením jako nosicheck
      (`hazarizk/…`). Před prvním commitem projít `.gitignore` (config.php, crontoken.txt, DB,
      cache, backup-*/) a `git diff --cached | grep` na tokeny. Deploy zůstává ruční FTP.
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
