# TODO — senzorvzduchu.cz mapa 2.8

- [ ] **Zrcadlit na GitHub** (zatím NEPROVÁDĚT, jen zapsáno): složka 2.8 není git repozitář
      (`.gitignore` je, `.git` ne). Cíl: soukromé repo se stejným rozvržením jako nosicheck
      (`hazarizk/…`), aby šlo vyvíjet a opravovat i v terénu z jiného stroje. Před prvním
      commitem projít `.gitignore` (config.php, crontoken.txt, DB, cache, backup-2.7/) a
      `git diff --cached | grep` na tokeny. Deploy zůstává ruční FTP — git nenasazuje.
- [x] Nasadit 2.8 podle `DEPLOY_2.8.md` (4. 9. 2026; doplňky 2.8.1–2.8.4 nasazeny 7. 9. 2026).
- [ ] Do `config.php` na serveru přidat `ADMIN_TOKEN` (jinak admin blokací používá CRON_TOKEN).
- [ ] Očima zkontrolovat podklad „Barevná" v normálním okně (Browser pane to nedokázal vykreslit).
- [ ] Po nasazení sledovat spotřebu CARTO dlaždic (2 vrstvy místo 1; free tier 5 M/měsíc).
- [ ] Popup polish (2.8 + 2.8.1–2.8.4: pevná šířka, box grafu, štítky-tlačítka, ×) přenést i do
      nosíčku — zapsáno v `CODE/NOSICHECK/TODO.md`.
- [ ] V příštím postu jednou větou oznámit změnu barevné škály (EEA EAQI 2024, přísnější zelená).
- [ ] Nápad: chip „IKO" = oficiální index ČHMÚ jen u stanic ČHMÚ (3h průměry, vzorec ČHMÚ);
      bez pokynu neprovádět.
- [ ] Po ~5 dnech od nasazení ověřit, že se plní historie PM10/NO₂/O₃/hluk a 24h pohled.
- [ ] Při změně `lib/Alerts.php` nebo `lib/Forecast.php` v nosíčku přenést i sem (kopie 1:1).
- [x] Nahrát 2.8.5 (nasazeno 8. 9. 2026): `forecast-proxy.php` (diagnostika `last_error`), `map/app.js` + `index.html` + `embed.html`
      (auto-reload záložky staré > 6 h; `?v=2.8.5`) — viz DEPLOY_2.8.md §10.
- [x] URL cron `forecast-proxy.php?token=TOKEN` každých 30 min nastaven v administraci Active24 (8. 9. 2026);
      refresh výhledu už nezávisí na návštěvách.
