# Nasazení 3.6 (Moje senzory, push upozornění, Větrat?) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **NASAZENO 19. 10. 2026 ~17:10 (ruční FTP uživatelem), živě ověřeno: get_history JSON, `*.db` 403, `?action=vapid` vydal klíč
(`push-vapid.php` vznikl na serveru), tick `checked:0`, mine.js/badge/lib 200, index `?v=3.6`. Zbývá test push na telefonu (§3 bod 6).** Předpoklad: 3.5.3 nahráno (nebo nahrát spolu s tímhle - 3.6 obsahuje i změny 3.5.3).
Web i aplikace (Android TWA, iPhone PWA z plochy) dostanou vše najednou; aplikace z Play se kvůli webu nepřestavuje,
jen kvůli notifikacím (§4).

## 1. Co je nové

- **Moje senzory** (`map/mine.js`, jen `index.html`): ★ v hlavičce popupu přidá senzor do sledovaných (localStorage
  `sv_my_sensors`, max 5). Karta vlevo dole: hodnota aktivní látky (nebo první dostupné), trend proti stavu před 3 h
  (hodinové sloty z `get_history.php`), zvonek. Klik na řádek = přiblížit + otevřít popup. Při startu se mapa vycentruje
  na sledované senzory. Deep link `?sensor=<id>` otevře popup (používají ho notifikace).
- **Push upozornění** (`push-api.php`, `lib/Push.php`, `lib/WebPush.php`, `sw.js`): pravidlo senzor + látka (PM2.5/PM10/
  NO₂/O₃) + práh + 1–3 h, volitelně smogové výstrahy ČHMÚ pro ORP senzoru. Zpráva při překročení (hodinové průměry po
  celou dobu ≥ práh), zpráva „klesl" při návratu pod práh, nejvýš jedna za 6 h; smog = každá výstraha jednou. Web push
  bez knihoven (RFC 8291 šifrování + RFC 8292 VAPID, čisté PHP + openssl). Odběry v SQLite `historygraph/push.db`.
  Vyhodnocení spouští mapa (`?action=tick`, server nejvýš 1× za 5 min, flock) - **žádný nový cron není nutný**;
  volitelně `*/5 * * * * curl -s "https://senzorvzduchu.cz/push-api.php?action=tick&token=TOKEN"` kdyby mapu nikdo neměl otevřenou.
- **Větrat?** (v kartě, první sledovaný senzor): PM2.5 teď + výhled ČHMÚ (p50 ukotvený na senzor, `forecast-proxy.php`)
  + smogová výstraha pro ORP. Pásma EAQI 2024: ≤ 15 Ano, ≤ 25 Ano krátce, ≤ 50 Jen krátce, > 50 Ne; smog = Ne.
  Z výhledu doplní „od HH:MM se zhorší / pod 25 od HH:MM". Orientační, bez NO₂.

## 2. Co se nahrává

| Soubor na serveru | Nový/změněný | Poznámka |
|---|---|---|
| `historygraph/.htaccess` | nový | **zákaz stahování `*.db`, `*.lock`** jen v této složce - `push.db` nese adresy odběrů. Kořenový `.htaccess` je WordPressu (Kadence, Hummingbird) a NEMĚNÍ se. Po nahrání ověřit §3 |
| `push-api.php` | nový | API odběrů + tick |
| `lib/Push.php`, `lib/WebPush.php` | nové | logika, DB, šifrování, VAPID |
| `config.example.php` | změna | komentář k volitelným `VAPID_*` (do `config.php` NIC přidávat nemusíte) |
| `map/mine.js` | nový | karta, ★, formulář upozornění, Větrat? |
| `map/app.js` | změna | ★ v hlavičce popupu, `svMine.wireStar()`, událost `sv:data`; + 3.5.3 |
| `map/style.css` | změna | karta, ★, formulář (světlý i tmavý); + 3.5.3 |
| `map/index.html` | změna | kontejner `#mine`, `mine.js?v=3.6`, `style.css`/`app.js` `?v=3.6`; + 3.5.3 (is-app v head) |
| `map/embed.html` | změna | jen `?v=3.6` (karta ani mine.js v embedu nejsou) |
| `map/interp.js` | změna (3.5.3) | interpolace v aplikaci vypnutá |
| `map/sw.js` | změna | `push`, `notificationclick`, `pushsubscriptionchange` |
| `map/icons/badge-96.png` | nový | monochromatická ikona do stavové lišty Androidu |
| `map/soukromi.html` | změna | odstavec o push odběrech (Play vyžaduje aktuální zásady) |

**Nenahrávat**: `push-vapid.php` (vznikne na serveru sám při prvním `?action=vapid`; na serveru ho pak NIKDY nemazat ani
nepřepisovat - odběry v prohlížečích jsou vázané na veřejný klíč), `historygraph/push.db`, `tests/`, `*.md`.

## 3. Ověření po nahrání

1. `curl -I https://senzorvzduchu.cz/historygraph/push.db` → **403** (před prvním odběrem 404 - pak zkusit
   `curl -I https://senzorvzduchu.cz/historygraph/air_quality_history.db` → 403; do 3.6 vracel 200).
   Kdyby `/historygraph/get_history.php` přestal fungovat (500), smazat `historygraph/.htaccess` a napsat - blok je psaný pro Apache 2.2 i 2.4.
2. `https://senzorvzduchu.cz/push-api.php?action=vapid` → `{"publicKey":"B…"}` (88 znaků). Tím vznikl `push-vapid.php`.
3. `https://senzorvzduchu.cz/push-vapid.php` → prázdná stránka (soubor se vykoná, nic nevypíše).
4. `https://senzorvzduchu.cz/push-api.php?action=tick` → `{"checked":0,…}` nebo `{"skipped":true,…}`.
5. Mapa v Chrome na PC: klik na senzor → ★ v hlavičce → karta vlevo dole s hodnotou a „Větrat?"; obnovit stránku → mapa
   startuje vycentrovaná na senzor; `/map/?sensor=<id>` otevře jeho popup.
6. **Push na telefonu (Android, nainstalovaná aplikace nebo Chrome)**: zvonek u senzoru → práh 1 h, nízký práh (např. 5)
   → Zapnout → povolit upozornění → zvonek znovu → „Poslat zkušební zprávu" → notifikace do pár sekund. Pak počkat
   na další tick (≤ 5 min po dalším načtení dat v libovolné otevřené mapě): přijde „PM2.5 nad 5 µg/m³ · …".
   Klik na notifikaci otevře mapu s popupem senzoru. Nakonec práh nastavit rozumně (25) nebo Zrušit.
7. iPhone: jen z mapy přidané na plochu (Sdílet → Přidat na plochu); v Safari v záložce formulář ukáže návod.

## 4. Android aplikace (Play) - jen kvůli notifikacím

V `Mobile App Map/twa/twa-manifest.json` je nově `"enableNotifications": true` (delegace notifikací na aplikaci: zprávy
se ukážou jako od „Senzorvzduchu", ne od Chromu, a na Androidu 13+ se aplikace sama zeptá na oprávnění). Postup:
`./build.sh update` (znovu vygeneruje projekt z manifestu, zvedne `appVersionCode`) → `./build.sh build` → nový AAB
do Play Console. Bez přestavby notifikace fungují také, jen se v systému tváří jako notifikace Chromu.
Play Console → Data safety: přibývá „Device or other IDs - collected, not shared, optional, app functionality"
(push endpoint). Zásady: `soukromi.html` už to popisují.

## 5. Rollback

Vrátit `map/index.html`, `map/embed.html`, `map/app.js`, `map/style.css`, `map/sw.js` z 3.5(.3). `push-api.php`,
`lib/Push.php`, `lib/WebPush.php`, `mine.js` mohou zůstat (nic je nevolá). Odběry v prohlížečích přežijí
(`push-vapid.php` nechat) - po návratu 3.6 zase fungují.

## 6. Limity a co sledovat

- Vyhodnocení běží jen když někdo načte mapu (tick). V noci bez návštěv se zpráva zpozdí do prvního návštěvníka;
  URL cron z §1 to řeší.
- Hodinový průměr běžící hodiny se počítá z toho, co už přišlo (i z jednoho měření). „Po dobu 2 h" = tato + minulá hodina.
- Odběr zanikne, když uživatel smaže data webu / odinstaluje aplikaci: push služba vrátí 410 a řádek se smaže sám.
  Výměna `push-vapid.php` = zánik VŠECH odběrů.
- `push.db` roste jen s odběry (řádek ≈ 0,5 kB). Smazat zaniklé ručně není třeba.
- Ventilace bere jen PM2.5 první sledované stanice; ČHMÚ stanice bez PM2.5 → „Senzor teď nehlásí PM2.5".
