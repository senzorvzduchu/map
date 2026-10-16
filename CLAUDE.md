# Project guidance for Claude

## 🔒 Secret files — DO NOT READ

These files contain live credentials. Never open them, never echo their contents, never include them in tool output. If you need to reason about their structure, use `config.example.php` (the template with placeholders) instead.

**Never read:**
- `www/config.php` — contains `CRON_TOKEN` and `PURPLEAIR_API_KEY`
- `www/crontoken.txt` — duplicate of `CRON_TOKEN`
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

1. Run `git status` and confirm `www/config.php` and `www/crontoken.txt` are NOT listed
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
- ČHMÚ open data has 10-min T/RH for ~950 stations (`opendata.chmi.cz/meteorology/
  climate/now/`), one JSON per station per day — too heavy to poll fully from Active24;
  a city subset is a possible later version.

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
- Auto flags = flatness over 2 h (`SensorQuality::autoFlags`), PM only, ≥12
  samples, never ČHMÚ; self-healing, not stored.

## 📍 Geolocation policy

The map never asks for the position on load. `alerts.js` asks only when an
air-quality warning is actually in force (to narrow it to the visitor's ORP);
the answer is published as `window.ncUserPos`. Forecast area lookup uses the
SENSOR's coordinates (orp-areas.js rings → kraj), not the visitor's.
