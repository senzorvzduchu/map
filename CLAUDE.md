# Project guidance for Claude

## 🔒 Secret files — DO NOT READ

These files contain live credentials. Never open them, never echo their contents, never include them in tool output. If you need to reason about their structure, use `config.example.php` (the template with placeholders) instead.

**Never read:**
- `www/config.php` — contains `CRON_TOKEN` and `PURPLEAIR_API_KEY`
- `www/crontoken.txt` — duplicate of `CRON_TOKEN`
- `www/map/config.js` — `CARTO_KEY` (frontend; template `www/map/config.example.js`)
- Any future `.env`, `*.secret`, `*.key`, `*credentials*` files

**Safe alternatives:**
- `www/config.example.php` — same structure, placeholder values only
- `documentation/DOCUMENTATION.md` Section 5 — documents the constants without values

**If you need to modify `config.php` on behalf of the user:**
Describe the edit in natural language and have the user apply it manually. Do not Read → Edit the file yourself.

**If a secret ever appears in context (system-reminder, tool output, pasted content):**
- Never quote it back to the user
- Never commit a file that contains it
- Run `git diff --cached | grep <pattern>` before any commit to verify no leak

## 🚨 Before every `git commit`

1. Run `git status` and confirm `www/config.php`, `www/crontoken.txt` and `www/map/config.js` are NOT listed
2. Run `git diff --cached --name-only | xargs grep -lE "(API_KEY|TOKEN|[a-f0-9]{40,})"` to scan staged content for hex strings that look like tokens
3. Only then commit

## 📤 Deployment

Git is source-of-truth only. Deploying to the Active24 shared hosting is a **manual FTP upload** — never assume pushing to GitHub deploys anything. Explicitly tell the user which files to upload via FTP.

## 📝 Frontend layout (2.8 — shared files, two thin shells)

Since 2.8 the JS and CSS live in shared files under `www/map/`:
`app.js` (networks, markers, legend, history chart), `interp.js` (IDW overlay),
`wind.js` (leaflet-velocity), `info.js` (methodology modal), `style.css`.
`index.html` and `embed.html` are HTML shells only — identical except `og:url`
and an inline `<style>` block of compact overrides in `embed.html`.

- Logic changes go into the shared `.js`/`.css` files **once**.
- Markup changes (new legend row, new chip, new container) must still be applied
  to **both** shells — verify both before declaring a frontend task done.
- Bump the `?v=2.8` cache-buster on the `<script>`/`<link>` tags in both shells
  whenever a shared file changes (Active24 serves static files with long caching).

## 🧪 Multi-pollutant model (2.8)

- `POLLUTANTS` in `app.js` is the single registry (bands, colors, radius, chart
  max, limit line, `interp` flag). Add a pollutant THERE, not ad-hoc.
- Fetchers normalize into `sensorStore`; `renderSensor()` draws per
  `activePollutant`. ČHMÚ stations without the active pollutant render hollow;
  community sensors without it are hidden. Noise is never interpolated.
- Stored `value_type`s in SQLite: `PM2.5`, `PM10`, `NO2`, `O3`, `noise`, and since 3.0
  `temperature`, `humidity`. `get_history.php?type=` is whitelisted to exactly these.
- Interpolation trust weights (`TRUST` in `interp.js`) are duplicated in the
  Czech text of `info.js` by design — change both.
- Wind grid: `www/wind-proxy.php` (Open-Meteo, self-refreshing 60-min cache in
  `www/cache/wind_latest.json`, no cron needed; `?token=CRON_TOKEN` forces a rebuild).
- Interpolation default: ON in `index.html`, OFF in `embed.html` (detected by
  `location.pathname`, own key `sv_interp_embed` so the two never share state).
- Persisted UI state (localStorage): `sv_pollutant`, `sv_interp` / `sv_interp_embed`, `sv_basemap`,
  `sv_chart_range`.

## 🌡 Temperature & humidity (3.0)

- `temperature` and `humidity` are ordinary `POLLUTANTS` entries with extra flags:
  `colors` (own palette, NOT the EAQI green→purple), `openLow` (legend starts with
  "< 0"), `valid: [lo, hi]` (values outside are treated as missing — `plausible()` in
  app.js, same limits in `collect.php`; dead DHT22s report −14x °C), `meteo: true`
  (no forecast, no gradient fill, y-axis not anchored at 0, ČHMÚ hidden instead of
  hollow), `decimals` (hero + tooltip), `chartMin`/`chartMax` nullable.
- `interp: false` on purpose: enclosure heating makes sun-exposed sensors read
  5–25 °C high; an interpolated field would be a field of sensor errors.
- History: hourly slots use AVG for the two meteo types (MAX for pollutants), and the
  stuck/plateau heuristics skip them. Libčich T/RH come from the proxy's default
  (2-min) mode, not `?mode=aqi`. Sensor.Community T/RH are attached to the PM sensor
  by `location.id` (separate BME280/DHT22 sensor id), both in app.js and collect.php.
- `collect.php` stores one record per SC sensor per run (`$seenSc`); the proxy returns
  two readings per sensor and before 3.0 both were written with the same `time()`.
- `pa-proxy.php` applies the US EPA PurpleAir sensor offsets (−8 °F, +4 % RH) AFTER
  the Barkjohn PM2.5 correction, which must keep the raw RH.
- The chip bar has 7 chips + ℹ: on phones it scrolls; `updatePollutantChips()` scrolls
  the active chip into view.
- 3.1: ČHMÚ **meteorological** stations are the T/RH reference. `chmi-meteo-proxy.php` +
  `lib/ChmiMeteo.php` read the ČHMÚ open-data 10-min feed (`opendata.chmi.cz/meteorology/
  climate/now/data/10m-<WSI>-<YYYYMMDD>.json`, one JSON per station per DAY, ~100 kB, file
  regenerated hourly at HH:01) for the fixed subset `ChmiMeteo::STATIONS` (60 WSIs, keep ≤ ~60;
  some meta1 stations have no 10m file — check `?debug=1` → `missing` after editing the list).
  Metadata `meta1-<day>.json` once a day. QUALITY {0, 5} = valid (5 = unchecked real-time).
  Records are network `chmi`, id `CHMI_M_<WSI>`, values ONLY temperature/humidity,
  `meta.meteoOnly: true` → `renderSensor` never draws them hollow on pollutant layers; ČHMÚ
  air-quality stations stay hidden on meteo layers. `stale` after 2 h (hourly publication is
  normal), dropped after 3 h. `collect.php` block 2b stores them with `measured_at` from the
  source and dedupes by timestamp; `get_history.php` whitelist includes `CHMI_M_[0-9-]+`.
  Still no interpolation. Offline test: `tests/test_chmi_meteo.php`.

## 🌙 Dark mode (3.2, index.html only)

- `<html data-theme="dark">` is stamped by an inline `<head>` script in `index.html`
  (`sv_theme` in localStorage, else `prefers-color-scheme`); `applyTheme()` in app.js keeps
  the 🌙/☀️ button, `meta theme-color` and OS-change tracking in sync. `embed.html` has
  neither the script nor the button, so it never gets the attribute: keep it that way.
- Styling is a variable swap in `style.css` under `html[data-theme="dark"]`, plus explicit
  overrides for brand-blue text (→ `#a5b4fc`) and brand-blue fills (→ `--brand-secondary`),
  the ČHMÚ ring, Leaflet chrome, `.popup-quality` and alert backgrounds. New UI must use the
  `--surface*` / `--text-*` / `--border-*` tokens, never hard-coded white or `#21275F` text.
- The basemap follows the theme (`BASEMAPS.dark`, CARTO dark tiles with the same `?key=`),
  a manual basemap click afterwards wins (`sv_basemap`, `sv_basemap_prev`). localStorage is
  shared with the embed, so the embed ignores a stored `dark` basemap at start-up.
- `body.basemap-dark` (set by `setBasemap`) switches the interpolation blend to `screen`;
  this is keyed on the BASEMAP, not the UI theme.
- Chart.js colours and the ČHMÚ marker ring read `isDarkTheme()` at render time; the toggle
  calls `renderAllSensors()` so an open popup redraws its chart.

## 🔁 Self-refreshing proxies (no CLI cron on Active24)

Every new data feed follows the same pattern instead of a cron script: the
endpoint serves its cache, and the first request after expiry rebuilds it under
a non-blocking `flock` (`Http::tryLock`), serving the stale copy on failure.
`?token=CRON_TOKEN` forces a rebuild if a URL cron is ever added.
- `wind-proxy.php` — 60 min, Open-Meteo.
- `alerts-proxy.php` — 10 min, ČHMÚ CAP bulletin (index every time, 1.7 MB
  bulletin only when its name changed). Cache holds ALL categories, output is
  filtered to `cat = smog` — this map shows air-quality warnings only.
- `forecast-proxy.php` — 30 min, ČHMÚ boxplot ZIPs (KRAJ level, 4 pollutants),
  `cache/forecast/kraj_<code>.json`; curve anchored on the sensor's 3 h mean.
- `chmi-meteo-proxy.php` (3.1) — 15 min, ČHMÚ open-data 10-min station files for
  `ChmiMeteo::STATIONS` via `Http::fetchMany()` (curl_multi, 8 parallel), `cache/chmi_meteo.json`
  + daily `cache/chmi_meteo_meta.json`; falls back to yesterday's file after midnight UTC.
- `chmi-other-proxy.php` (3.4) — 10 min, reference stations owned by OTHER operators than ČHMÚ
  (health institutes, regions, cities, industry — Ostrava-Radvanice ZÚ, Brno-Svatoplukova…). The
  ČHMÚ open data has only ČHMÚ-owned stations; this reads the undocumented JSON behind the chmi.cz
  maps (`data-provider.chmi.cz/api/data/ovzdusi/stanice/mereni/{PM10|PM2_5|NO2|O3}/1h/{YYYYMMDDHHMM}`,
  CC BY 4.0 per the chmi.cz footer). Slot = END of the averaging hour (UTC) → `measured_at` = slot − 1 h,
  same as the opendata `startTime`. `lib/ChmiOther.php` holds the 40-station whitelist (code → name,
  owner, WGS84 — layer coords are UTM 33N, unused), slot candidates (H may not exist for ~50 min → H−1…),
  parsing ("18,0" → 18.0, "N/A" → null, gases exactly 0.0 → null) and the merge with the previous slot
  (per-pollutant `value_times`). Records stay in network `chmi`, id `CHMI_<code>`, with `meta.owner` for
  the popup; `stale` after 3 h. `collect.php` block 2c stores per-pollutant hours with dedupe (nothing
  > 4 h); `monitor.php` alerts when the cached slot is > 3 h old. `?debug=1` = probe/layers/missing.
  Offline test: `tests/test_chmi_other.php` (fixtures = real layers of 2026-10-17 17:00Z). Adding a
  station = one line in `ChmiOther::STATIONS`; the full ISKO inventory is `../SCOPE_3.4-stations.json`.
- `ag-proxy.php` (2.9) — 3 min, AirGradient public WORLD feed (no key, ~1.5 MB gzip,
  no bbox param) → `lib/AirGradient.php` filters the CZ bbox, drops `EXCLUDE_IDS`
  (border DE/PL/SK), `offline`, readings > 2 h, PM-less devices; only the CZ subset
  is cached (`cache/airgradient.json`). Output `{sensors:[…]}` carries `online`
  (< 30 min) — app.js greys stale markers (`meta.stale`), interp.js skips them,
  collect.php stores only online ones (ids `AG_<locationId>`). Raw Plantower PM,
  no correction → trust 0.10. Licence CC BY-SA 4.0 — keep the attribution in
  `NETWORK_LABELS`, the legend row title and `info.js`. `?debug=1` = diagnostics.
  Offline test: `tests/test_airgradient.php`.
Pure logic lives in `www/lib/` (`Alerts.php`, `Forecast.php` copied verbatim
from nosicheck.cz — keep them in sync; `tests/test_alerts.php`,
`tests/test_forecast.php` run offline). Needs PHP `simplexml` + `zlib`.

## 🚫 Sensor bans & auto quality flags

- `sensor_bans` table in the history DB (created + seeded with SC_46875/SC_7213
  by `SensorQuality::ensureTable`). No hardcoded ban list remains.
- Admin UI: `www/admin/bans.php?token=<ADMIN_TOKEN|CRON_TOKEN>` (token-gated,
  no accounts). Recommend `define('ADMIN_TOKEN', …)` in config.php.
- Public `sensor-bans.php` → `{banned, auto}`; app.js filters in
  `upsertSensor` (markers AND interpolation), `collect.php` skips manual bans.
- Auto flags (`SensorQuality::autoFlags`, self-healing, not stored, never ČHMÚ):
  `stuck`/`plateau` = PM flatness over 2 h, ≥12 samples (`flatnessFlags`); since 3.3
  `indoor` = night (00–05 Europe/Prague, last 24 h) median T_sensor − T_ČHMÚ > 6 °C AND
  median RH_ČHMÚ − RH_sensor > 15 pts AND 24 h T range < 4 °C, ≥ 6 paired samples,
  nearest `CHMI_M_` station ≤ 30 km (`indoorFlags`; coords from the proxy caches via
  `communityCoords()`, stations from `cache/chmi_meteo.json` via `referenceStations()`;
  thresholds = `INDOOR_*` constants). Every flag carries `detail` for the admin table.
  `collect.php` deliberately keeps storing auto-flagged sensors (the indoor flag needs
  next night's samples to re-evaluate; skipping would make it flap daily). Offline test:
  `tests/test_indoor_flag.php`.
- `SensorQuality::normalizeId()` is the single gate for ban ids: `SC_`, `SCK_`, `PA_`,
  `LIBCICH_` and (since 3.2) `AG_`; a bare number means `SC_`. Add a new community
  network's prefix THERE or its sensors cannot be banned. ČHMÚ (`CHMI_`, `CHMI_M_`) never.
- Indoor units (e.g. AG_61860 "Location 2" next to 78554 "Předhradí", Poděbrady) are caught
  by the `indoor` flag since 3.3 — only where a ČHMÚ meteo station is within 30 km, so
  `ChmiMeteo::STATIONS` got Poděbrady (`0-203-0-11617`). A manual ban is still the safer
  permanent fix (admin "Zablokovat trvale" on the flag row).

## 📍 Geolocation policy

The map never asks for the position on load. `alerts.js` asks only when an
air-quality warning is actually in force (to narrow it to the visitor's ORP);
the answer is published as `window.ncUserPos`. Forecast area lookup uses the
SENSOR's coordinates (orp-areas.js rings → kraj), not the visitor's.

## 📱 PWA / Android app (3.5, index.html only)

- `www/map/manifest.json`, `www/map/sw.js`, `www/map/offline.html`, `www/map/icons/`, `www/map/soukromi.html`
  (privacy policy for Play) and `www/.well-known/assetlinks.json` make `/map/` an installable PWA and the
  web half of the Android app (Trusted Web Activity, package `cz.senzorvzduchu.mapa`). The Android project
  itself (bubblewrap, keystore, Play listing) lives OUTSIDE this repo in `CODE/Mobile App Map`; never copy
  the keystore here.
- `index.html` carries `<link rel="manifest">`, icon metas and the SW registration script before `</body>`;
  `embed.html` has none of it (partners embedding the map must not get an install prompt) - keep it that way.
- `sw.js` has no precache list on purpose: navigations and every `*.php` endpoint are network-first
  (stale data only when the network fails), static assets and CDN libs stale-while-revalidate, tiles
  cache-first capped at 400. Adding a data source needs no SW change. Bump `VERSION` in `sw.js` when you
  want clients to drop their caches; the registration script auto-activates a new worker and reloads once.
- `html.is-app` is set when running inside the TWA / installed PWA (`style.css` adds safe-area padding).
- Geolocation policy (above) is unchanged; the Play "Data safety" form was filled accordingly.
- `assetlinks.json` needs the SHA-256 of BOTH the upload key (`build.sh sha`) and Play's app-signing key
  (Play Console → Setup → App signing). Placeholders are harmless until then.
- 3.5.3: `is-app` is set by the `<head>` script (from `?utm_source=twa|pwa`, `android-app://` referrer or
  `display-mode: standalone`) BEFORE app.js/interp.js run; in app mode interpolation defaults OFF (own key
  `sv_interp_app`), the +/− control is hidden, data refetch on return to foreground. (The 3.5.3 navy body background was reverted in 3.6.1: Chrome paints
  the Android 15 bottom strip itself, and on iOS the canvas colour shows under the home indicator.)
- 3.6.2: on mobile the collapsed `.mine` card is a pill (bottom-left); tap the header/pill to toggle. Desktop unchanged.

## ⭐ My sensors, push alerts, ventilation advice (3.6, index.html only)

- `www/map/mine.js` owns all three; app.js only inserts `svMine.starHtml()` into the popup header, calls
  `svMine.wireStar()` after a popup opens, and dispatches `sv:data` at the end of `fetchAllData()`. It reads
  app.js globals (`map`, `sensorStore`, `markers`, `POLLUTANTS`, `activePollutant`, `setPollutant`, `loadForecast`,
  `popupOptions`, `ncOrpAt`). embed.html has no `#mine` container and no mine.js — keep it that way.
- Pinned sensors: localStorage `sv_my_sensors` (max 5, `{id,name,network,lat,lng}`), `sv_mine_collapsed`.
  First pinned sensor drives the "Větrat?" card. `?sensor=<id>` deep link opens that popup after the first data load.
- Push server: `push-api.php` (vapid/subscribe/unsubscribe/rules/test/tick) → `lib/Push.php` (SQLite
  `historygraph/push.db`, rule evaluation, messages) → `lib/WebPush.php` (RFC 8291 aes128gcm + RFC 8292 VAPID,
  pure PHP, no composer). VAPID keys: `config.php` `VAPID_*` if defined, else auto-generated `www/push-vapid.php`
  (gitignored; NEVER regenerate on the live server — every browser subscription is bound to the public key).
  Endpoints are restricted to `Push::ENDPOINT_HOSTS`. `www/historygraph/.htaccess` denies `*.db` / `*.lock` downloads there; the ROOT `.htaccess` on the server belongs to WordPress (Kadence Security, Hummingbird) and is never shipped from this repo.
- Evaluation: hourly AVG slots (incl. the running hour) from the history DB; `hours` consecutive slots ≥ threshold →
  one message per episode (`active`), "klesl" message when the latest slot drops below, ≥ 6 h between exceed messages;
  smog = `cat: smog` alerts from `cache/alerts_latest.json` whose `orp` list contains the rule's ORP, once per alert id.
  Triggered by the map (`?action=tick` after each data load, server-side throttle 5 min + flock) — no cron required.
- sw.js: `push` → showNotification (icon-192, badge-96, tag, data.url), `notificationclick` → focus/open the deep link,
  `pushsubscriptionchange` → re-subscribe with the same options.
- Offline tests: `tests/test_webpush.php` (RFC 8291 §5 vector byte-exact + VAPID verify), `tests/test_push.php`
  (temp DBs, injected sender). The built-in browser denies Notification permission, so the live chain is phone-only.
- TWA: `enableNotifications: true` in `Mobile App Map/twa/twa-manifest.json` (notification delegation) needs
  `build.sh update` + `build` + new AAB; the web works without it.
