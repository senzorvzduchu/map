# Nasazení 3.5 (PWA + Android aplikace) na senzorvzduchu.cz (Active24, ruční FTP)

Stav: **NASAZENO 18. 10. 2026 ~19:40 (ruční FTP uživatelem), živě ověřeno ~19:50 - §6. 3.5.1 (19:55): `?pollutant=` v app.js + manifest bez screenshotu - čeká na nahrání. 3.5.3 (19. 10. 2026): chování v aplikaci - §7, čeká na nahrání.** 3.5 = kopie 3.4 (produkce od 17. 10. 2026) + vrstva PWA, díky které jde
mapa publikovat na Google Play jako Trusted Web Activity (TWA): aplikace je tenká slupka, která otevře
`https://senzorvzduchu.cz/map/` přes Chrome bez adresního řádku. Žádný backend, žádný VPS, nic nového na
serveru kromě statických souborů. Android projekt (bubblewrap, keystore, texty do obchodu) je mimo tento
repozitář: `C:\Users\Michael Lažan\CODE\Mobile App Map`. Původní verze změněných souborů: `backup-3.4/`.

Balíček aplikace: `cz.senzorvzduchu.mapa`. Účet Play Console: organizace, ID 6298217822339645919.

## 1. Co se nahrává

| Soubor na serveru | Nový/změněný | K čemu slouží | Kdo ho volá |
|---|---|---|---|
| `map/manifest.json` | nový | web app manifest: název, ikony, `start_url=/map/?utm_source=pwa`, `scope=/`, barva `#21275F`, zástupci PM2.5/NO₂ | Chrome (instalace), bubblewrap (čte ho při `init`) |
| `map/sw.js` | nový | service worker: navigace a `*.php` síť-first (stará data lépe než žádná), statika a CDN stale-while-revalidate, dlaždice cache-first s limitem 400, offline stránka | prohlížeč po registraci z index.html |
| `map/offline.html` | nový | stránka „Jste offline“ s tlačítkem Zkusit znovu | sw.js |
| `map/soukromi.html` | nový | zásady ochrany soukromí (povinná URL v Play Console) | Play Console, uživatel |
| `map/icons/*.png` | nový (6) | `icon-192/512`, `icon-maskable-192/512`, `apple-touch-icon`, `favicon-48` (pin z loga na `#21275F`) | manifest, index.html |
| `.well-known/assetlinks.json` | nový | Digital Asset Links: důkaz, že doména patří k aplikaci. **Zatím se zástupnými otisky** - viz §3 | Chrome v Androidu při startu TWA |
| `map/app.js` | změna (3.5.1) | `?pollutant=pm25|…` v URL má přednost před `sv_pollutant` - zástupci z manifestu a odkazy z webu otevřou rovnou danou vrstvu | obě HTML |
| `map/config.js` | nový (3.5.2) | klíč CARTO Basemaps (`window.SV_CONFIG.CARTO_KEY`) - mimo git, šablona `map/config.example.js`; nahrát PŘED `app.js` | index.html, embed.html |
| `map/index.html` | změna | `<link rel=manifest>`, ikony, meta pro instalaci; registrace `sw.js` před `</body>`; `style.css?v=3.5`, `app.js?v=3.5` | návštěvník |
| `map/embed.html` | změna | jen `style.css?v=3.5`, `app.js?v=3.5` (žádný manifest ani SW - embed zůstává čistý iframe jako u dark mode) | partnerské weby |
| `map/style.css` | změna | `html.is-app body { padding-bottom: env(safe-area-inset-bottom) }` | obě slupky |

**Nenahrávat**: `config.php`, `crontoken.txt`, `historygraph/*.db`, `backup-3.4/`, `tests/`, `*.md`, `cache/`.

**Beze změny v 3.5**: všechny proxy, `lib/`, `historygraph/`, `interp.js`, `wind.js`, `info.js`, `alerts.js`.
`config.php` a cron se nemění.

### .htaccess (kořen webu, přidat, pokud tam není)

```apache
# PWA (3.5)
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^\.well-known/ - [L]
</IfModule>
<FilesMatch "^(manifest\.json|assetlinks\.json)$">
  Header set Content-Type "application/json; charset=utf-8"
</FilesMatch>
<FilesMatch "^sw\.js$">
  Header set Cache-Control "no-cache, no-store, must-revalidate"
</FilesMatch>
```

Bez první části může Active24 blokovat složku začínající tečkou; bez třetí části by Active24 cachoval
`sw.js` dlouho a nová verze workeru by se k uživatelům dostala až po expiraci.

## 2. Postup

1. FTP: `map/icons/` → `map/manifest.json`, `map/offline.html`, `map/soukromi.html`, `map/sw.js` →
   `map/style.css` → `map/index.html` + `map/embed.html` → `.well-known/assetlinks.json` → `.htaccess`.
2. Zkontrolovat (v prohlížeči):
   - `/map/manifest.json` → JSON, `Content-Type: application/json`.
   - `/map/sw.js` → JS, hlavička `Cache-Control: no-cache`.
   - `/.well-known/assetlinks.json` → JSON (ne 403/404).
   - `/map/soukromi.html`, `/map/offline.html`, `/map/icons/icon-512.png`.
   - `/map/` v Chrome na PC: DevTools → Application → Manifest bez chyb, Service Workers → `sw.js` activated;
     Network → při druhém načtení `style.css`, `app.js`, dlaždice „(ServiceWorker)“; `*-proxy.php` vždy ze sítě.
   - `/map/` v Chrome na Androidu: menu → „Přidat na plochu“ nabízí instalaci (ne jen zástupce); po instalaci
     se mapa otevře bez adresního řádku, ikona = pin. Režim letadlo + otevřít → `offline.html`.
   - `/map/embed.html` → beze změny chování, v DevTools žádný service worker registrovaný z embedu
     (pokud byl registrován z `/map/`, embed pod ním běží také - to je v pořádku, pravidla cache jsou stejná).
   - Dark mode, chipy, legenda, popupy: beze změny (3.5 nesahá na app.js).
3. Teprve potom Android: ve složce `Mobile App Map/twa/` → `./build.sh init` (čte živý manifest, vytvoří
   keystore) → `./build.sh sha` → otisk do `assetlinks.json` (první položka) → znovu nahrát → `./build.sh build`
   → `app-release-bundle.aab` do Play Console (interní test). Podrobně `Mobile App Map/README.md`.

## 3. Digital Asset Links - dvě fáze

`assetlinks.json` musí obsahovat SHA-256 otisk **obou** klíčů:
1. upload key z `android.keystore` (`build.sh sha`) - stačí pro sideload z `app-release-signed.apk`,
2. **app signing key z Play Console** (Setup → App signing → App signing key certificate → SHA-256) -
   ten Google použije k podpisu toho, co stahují uživatelé z obchodu. Bez něj má aplikace z Play
   adresní řádek, i když sideload funguje.

Ověření: `https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://senzorvzduchu.cz&relation=delegate_permission/common.handle_all_urls`
→ `statements` obsahuje `cz.senzorvzduchu.mapa` s oběma otisky. Dokud jsou v souboru zástupné hodnoty
`REPLACE_WITH_…`, Chrome je ignoruje a nic se nerozbije - web i instalovatelná PWA fungují.

## 6. Ověřeno živě (18. 10. 2026 ~19:45, prohlížeč v Claude desktop, po FTP uživatelem)

- `/map/manifest.json` 200 `application/json`; `/map/sw.js` 200 `application/javascript`; `/.well-known/assetlinks.json` 200
  `application/json` (zástupné otisky, viz §3); `/map/offline.html`, `/map/soukromi.html` 200; 6 ikon 200 `image/png`.
- `/map/`: `<link rel="manifest" href="manifest.json?v=3.5">`, `style.css?v=3.5`, registrace `sw.js` přítomna,
  `navigator.serviceWorker.getRegistrations()` → scope `https://senzorvzduchu.cz/map/`, stav `activated`, stránka pod
  kontrolou SW. Konzola: jediná chyba = 404 `icons/screenshot-phone-1.png` (položka `screenshots` v manifestu → v 3.5.1 odstraněna).
- `/map/embed.html`: `style.css?v=3.5`, bez manifestu.
- `sw.js` se servíruje s `Cache-Control: max-age=31536000` (blok z §1 v `.htaccess` není aktivní nebo Active24 hlavičku přepisuje).
  Neškodí: prohlížeče při kontrole aktualizace SW HTTP cache pro samotný skript obcházejí (`updateViaCache: 'imports'`).
- Zjištěno: `app.js` nečetl `?pollutant=`, zástupci z manifestu by jen otevřeli mapu → 3.5.1.

## 4. Rollback

Nahrát zpět `backup-3.4/map/index.html`, `embed.html`, `style.css`. Nové soubory mohou zůstat (nic je nevolá).
Service worker už nainstalovaný u návštěvníků: po návratu na starý `index.html` zůstane registrovaný, ale
chová se neutrálně (síť-first); úplné odhlášení = nahrát `sw.js` s obsahem
`self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',e=>e.waitUntil(self.registration.unregister()))`.

## 5. Známé limity a co sledovat

- `sw.js` cachuje dlaždice CARTO včetně `?key=` v URL - jen v prohlížeči uživatele (Cache Storage), klíč je
  stejně v `app.js`. Limit 400 dlaždic ≈ 10-20 MB.
- `navigate` síť-first s timeoutem 8 s: na pomalé síti se po 8 s ukáže poslední cachovaná verze mapy
  (ne offline stránka); data se pak dotáhnou normálně.
- Google jednou ročně zvedá cílové API (termín obvykle srpen) → `build.sh update`, bump `appVersionCode`,
  nový AAB. Web se tím nemění.
- Geolokace: beze změny (nikdy při načtení; `alerts.js` jen při výstraze). V Data safety v Play Console
  tedy „Location - collected, not shared, optional, app functionality“ pouze pokud zůstane dotaz v alerts.js.
- `embed.html` úmyslně bez manifestu a SW: partner, který mapu vkládá, nemá dostat výzvu k instalaci cizí aplikace.

## 7. 3.5.3 (19. 10. 2026) - chování uvnitř aplikace / PWA

Po prvním spuštění z Play (interní test, 19. 10. 2026, foto v 15:42) a z PWA v iOS Safari:

| Soubor | Změna |
|---|---|
| `map/index.html` | třída `is-app` se nastavuje už ve skriptu v `<head>` (z `?utm_source=twa\|pwa`, referreru `android-app://` nebo `display-mode: standalone`), ne až za `</body>` - app.js a interp.js ji potřebují při načtení; cache-bustery `style.css`, `app.js`, `interp.js` → `?v=3.5.3` |
| `map/embed.html` | jen cache-bustery `?v=3.5.3` |
| `map/interp.js` | v aplikaci interpolace ve výchozím stavu VYPNUTÁ, vlastní klíč `sv_interp_app` (jako embed); web v prohlížeči beze změny |
| `map/app.js` | po návratu aplikace do popředí (`visibilitychange`, `pageshow`) se data načtou hned, jsou-li starší než 5 min - Android na pozadí intervaly zastaví a ukazatel vlevo dole držel 15:27 v 15:42 |
| `map/style.css` | mobil: +/− pod lištou i s výřezem (`margin-top: calc(52px + env(safe-area-inset-top))` - na iPhonu se +/− kreslilo pod lištu); v aplikaci (`html.is-app`) se +/− nezobrazuje vůbec (pinch); pozadí `body` v aplikaci = barva stavové lišty (světlý `#21275F`, tmavý `#1f2937`) kvůli spodnímu pruhu Androidu 15+ |

Spodní pruh na Androidu: `targetSdkVersion 36` → Android 15+ vynucuje edge-to-edge a barvu navigační lišty z `twa-manifest.json`
(`navigationColor`) ignoruje; pod tlačítky ◁ ○ □ kreslí poloprůhledný pruh nad pozadím okna. Pozadí `body` je teď tmavě modré -
ověřeno 19. 10. 2026 večer: pruh zůstal bílý (kreslí ho Chrome, z webu to ovlivnit nejde) a na iPhonu se modř ukázala
pod mapou v oblasti home indikátoru → **3.6.1 pozadí vráceno na --bg-light** (`style.css?v=3.6.1`).

Výchozí stav v aplikaci: PM2.5 (poslední volba látky se dál pamatuje v `sv_pollutant`), bez interpolace, tmavý/světlý režim
podle systému (přepínač 🌙 v liště dál ukládá ruční volbu do `sv_theme`; smazat = vrátit se k systému).

Nahrát FTP: `map/index.html`, `map/embed.html`, `map/app.js`, `map/interp.js`, `map/style.css`. Ověřeno lokálně 19. 10. 2026
(sandbox 8135, `?utm_source=twa`: `is-app` ano, interpolace vypnutá, +/− skryté, pozadí `#21275F` / `#1f2937`; `/map/` bez
parametru beze změny: interpolace zapnutá, +/− viditelné).

