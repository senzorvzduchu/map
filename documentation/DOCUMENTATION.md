# Senzorvzduchu.cz — Technical Documentation

> **Single source of truth.** This file replaces and merges:
> `GEMINI documentation.txt`, `SECURITY_FIXES.md`, `README_DEPLOYMENT.md`, `DEPLOYMENT_CHECKLIST.md`
>
> For testing procedures see `TEST_GUIDE.md`.

---

## 1. Project Overview

Real-time air quality monitoring map for the Czech Republic. Aggregates data from four sources — Sensor.Community (community sensors), ČHMÚ (official government stations), Smart Citizen Network (IoT research kits), and PurpleAir (laser particle counters with EPA correction) — into a unified interactive map with 5-day historical charts.

**Live:** https://senzorvzduchu.cz/map/
**Repository:** https://github.com/senzorvzduchu/map
**Last deployed:** 2026-04-16

---

## 2. File Structure

Legend: ✅ = tracked in git · ⛔ = gitignored (not pushed to GitHub)

```
.gitignore                      ✅ Git exclusion rules
README.md                       ✅ GitHub landing page
www/
├── config.php                  ⛔ Secrets (CRON_TOKEN, PURPLEAIR_API_KEY)
├── config.example.php          ✅ Committed template (placeholder values only)
├── crontoken.txt               ⛔ Duplicate of token (local only)
├── sc-proxy.php                ✅ Caching proxy for Sensor.Community API
├── chmi-proxy.php              ✅ Caching proxy for ČHMÚ API
├── sck-proxy.php               ✅ Caching proxy for Smart Citizen Network API
├── pa-proxy.php                ✅ Caching proxy for PurpleAir API (EPA correction)
├── sc_data_cache.json          ⛔ SC cache (auto-generated, 5 min TTL)
├── sck_data_cache.json         ⛔ SCK cache (auto-generated, 5 min TTL)
├── pa_data_cache.json          ⛔ PA cache (auto-generated, 15 min TTL)
├── cache/
│   ├── chmi_metadata.json      ⛔ ČHMÚ metadata cache (15 min TTL)
│   └── chmi_data.csv           ⛔ ČHMÚ data cache (15 min TTL)
├── map/
│   ├── index.html              ✅ Main map page (full desktop/mobile version)
│   ├── embed.html              ✅ Compact iframe version (used on WordPress front page)
│   └── logo_barevne.svg        ✅ Brand logo
└── historygraph/
    ├── collect.php             ✅ CRON data collector (runs every 5 min)
    ├── get_history.php         ✅ API endpoint for historical chart data
    ├── monitor.php             ✅ DB write monitor — alerts via ntfy.sh if data collection stops
    ├── monitor_last_alert.txt  ⛔ Cooldown file (auto-generated, prevents alert spam)
    └── air_quality_history.db  ⛔ SQLite database (5-day rolling window)

documentation/
├── DOCUMENTATION.md            ✅ This file
└── TEST_GUIDE.md               ✅ Testing procedures
```

---

## 3. Architecture & Tech Stack

**Philosophy:** No frameworks, no build tools, no database server. Standard shared PHP hosting + SQLite file database for maximum portability.

| Layer | Technology |
|-------|-----------|
| Frontend | Vanilla JavaScript ES6+, HTML5, CSS3 |
| Map | Leaflet.js v1.9.4 |
| Charts | Chart.js (CDN) |
| Fonts | Google Fonts — Familjen Grotesk, Inter |
| Backend | PHP 8.x (native, no framework) |
| Database | SQLite (file: `air_quality_history.db`) |
| HTTP client | cURL (collect.php), file_get_contents (proxies) |

**Data flow:**

```
External APIs
  ├── Sensor.Community JSON  →  sc-proxy.php   →  sc_data_cache.json
  ├── ČHMÚ metadata + CSV   →  chmi-proxy.php  →  cache/
  ├── Smart Citizen API     →  sck-proxy.php   →  sck_data_cache.json
  └── PurpleAir API         →  pa-proxy.php    →  pa_data_cache.json

Frontend (every 5 min via setInterval)
  └── fetches all four proxies → renders Leaflet markers

CRON (every 5 min)
  └── collect.php → reads all four proxies → writes to SQLite

User clicks marker → popup opens
  └── fetch get_history.php?id=SENSOR_ID → Chart.js renders 5-day graph
```

---

## 4. Data Sources & Processing

### 4A. Sensor.Community (community sensors)

- **API:** `https://data.sensor.community/airrohr/v1/filter/country=CZ`
- **Proxy cache:** `sc_data_cache.json`, TTL 300s (5 min)
- **Frontend refresh:** every 300 000 ms (5 min)
- **Filtering:**
  - `location.indoor == 1` → excluded (indoor sensors)
  - Sensor IDs `46875`, `7213` → hardcoded exclusions (permanently faulty sensors)
  - Sensors without PM2.5 value or valid lat/lng coordinates → excluded
  - **Automatic outlier filter (Layer 2, frontend):** sensors whose PM2.5 exceeds `max(60, median_neighbors × 5)` are suppressed from the map. Requires ≥ 2 real neighbors within 30 km; co-located sensors (< 0.1 km, same node) are excluded from the neighbor set to prevent mutual reinforcement. Flagged sensors are logged to browser console as `[SC Outlier]`.
- **Value range guard (Layer 1, collect.php):** PM2.5 values ≤ 0 or > 500 μg/m³ are rejected before DB insert (physically impossible outdoors in CZ).
- **Value mapping:** `P2` → `PM2.5`, `P1` → `PM10`, `temperature` → Teplota °C, `humidity` → Vlhkost %
- **Sensor ID format:** `SC_12345`
- **Three-pass parsing (frontend):** `fetchSensorCommunityData()` runs three passes: (1) build a `locationEnv` map of `location.id → {temperature, humidity}`; (2) collect all valid dust sensors into `scSensors[]` array enriched with temp/humidity; (3) apply neighbor-ratio outlier filter and call `createOrUpdateMarker()` only for sensors that pass.

### 4C. Smart Citizen Network (IoT research kits)

- **API:** `https://api.smartcitizen.me/v0/devices?near=49.8175,15.473&per_page=100&page=N`
- **Proxy cache:** `sck_data_cache.json`, TTL 300s (5 min)
- **Filtering:**
  - Geographic bbox: lat 48.5–51.2, lng 12.0–18.9 (Czech Republic) — `country_code` field is unreliable in the API
  - `exposure !== 'outdoor'` → excluded
  - `last_reading_at` older than 24 hours → excluded (filters offline/abandoned sensors)
  - Sensors without a PM2.5 reading → excluded
  - **Blacklisted device IDs:** `19547` (Warm Spark Cow — Wroclaw, Poland, caught by bbox)
- **PM2.5 detection:** sensor name matching — any sensor whose name contains `"2.5"` (handles "PM 2.5", "PM2.5", etc. across kit versions). Other values matched by name: `"temperature"`, `"humidity"`, `"pm 10"`, `"pm 1"`.
- **Pagination:** fetches up to 5 pages (100 devices/page) sorted by distance from CZ centre; stops early when a full page yields no CZ bbox hits
- **Sensor ID format:** `SCK_12345`
- **Data object format:** normalised to numeric measurement IDs for frontend compatibility: `89`=PM2.5, `87`=PM1, `88`=PM10, `55`=temperature, `56`=humidity
- **Debug mode:** `?debug=1` bypasses cache and returns diagnostic JSON (total found, active count, per-device skip reasons with sensor name list)
- **API note:** `world_map` endpoint is broken (returns root API response); the `?near=` devices endpoint is used instead. `country_code_eq` Ransack filter also non-functional — bbox filter is the reliable approach.

### 4D. PurpleAir (laser particle counters)

- **API:** `https://api.purpleair.com/v1/sensors` with CZ bounding box (`nwlat=51.2&nwlng=12.0&selat=48.5&selng=18.9`)
- **Authentication:** `X-API-Key` header from `PURPLEAIR_API_KEY` constant in `config.php`
- **Proxy cache:** `pa_data_cache.json`, TTL 900s (15 min)
- **HTTP client:** cURL (not `file_get_contents` — Active24 blocks custom headers via stream context)
- **Filtering:**
  - `location_type=0` — outdoor sensors only
  - `max_age=3600` — seen within the last hour
  - Tightened CZ bbox: lat 48.55–51.06, lng 12.10–18.87 (excludes border cities)
  - **Blacklisted sensor IDs:** `4619` (Regensburg, DE), `32145` (Dresden, DE), `171945` (BBB39/Dresden, DE), `60443` (Neudorf im Weinviertel, AT), `202867` (Borský Mikuláš, SK)
- **EPA correction (Barkjohn 2021):** Applied server-side in `pa-proxy.php`:
  - Formula: `PM2.5_corrected = max(0, 0.524 * avg_cf1 - 0.0862 * RH + 5.75)`
  - `avg_cf1` = average of channels A (`pm2.5_cf_1_a`) and B (`pm2.5_cf_1_b`)
  - `RH` = relative humidity (mandatory field for the formula)
  - PurpleAir API does **not** pre-apply EPA correction to any field (`pm2.5_atm` and `pm2.5_alt` are both raw)
  - Correction makes PA data more comparable to ČHMÚ reference stations
  - Clamped to 0 minimum (formula can go negative at very low concentrations)
- **Channel confidence check:** If one channel is null or `|A - B| > max(5, A × 0.7)`, sensor is flagged as `confidence: "low"`
- **Temperature:** Converted from Fahrenheit to Celsius in proxy
- **Value range guard:** PM2.5 ≤ 0 or > 500 μg/m³ rejected in `collect.php`
- **Sensor ID format:** `PA_12345`
- **API cost:** ~4.7M points/year at 15-min refresh with 9 fields and ~15 CZ sensors (~$47/year)

### 4B. ČHMÚ — Czech Hydrometeorological Institute (official stations)

- **Two-step fetch (metadata + data):**
  1. `chmi-proxy.php?type=metadata` → JSON with station locations and `IdRegistration` mapping
  2. `chmi-proxy.php?type=data` → CSV with current measurements
- **Proxy cache:** `cache/chmi_metadata.json` + `cache/chmi_data.csv`, TTL 900s (15 min)
- **Processing:** Builds a Registry Map connecting `IdRegistration` → station code + measurement type. Only active stations (`Active === true`) and valid measurement types (valType 8 or 9) are processed.
- **Measured values:** `PM2.5`, `NO2`, `O3`
- **Component normalisation:** `PM2_5` → `PM2.5`
- **Station ID format:** `CHMI_AKALA` (prefix + LocalityCode)
- **Data cadence:** 1-hour averages

---

## 5. Backend Reference

### `config.php` — Central configuration (secrets)

```php
define('CRON_TOKEN', 'your_hex_token');          // protects collect.php endpoint
define('ALLOWED_DOMAIN', 'https://senzorvzduchu.cz'); // CORS restriction
define('USER_AGENT', 'AirQualityMonitor/1.0 (senzorvzduchu.cz)');
define('PURPLEAIR_API_KEY', 'your-read-api-key');  // PurpleAir Read API key
```

- **Never commit this file.** Template is `config.example.php`.
- Required by: `sc-proxy.php`, `chmi-proxy.php`, `pa-proxy.php`, `collect.php`
- To generate a new token: `openssl rand -base64 32` (SSH) or PowerShell:
  ```powershell
  $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
  $bytes = New-Object byte[] 32
  $rng.GetBytes($bytes)
  [System.BitConverter]::ToString($bytes).Replace("-","").ToLower()
  ```
  Use the hex output — it contains no URL-special characters.

### `sc-proxy.php` — Sensor.Community proxy

- Caches `sc_data_cache.json` for 5 min
- CORS restricted to `ALLOWED_DOMAIN`
- User-Agent from `USER_AGENT` constant
- Stale cache fallback if API unreachable

### `sck-proxy.php` — Smart Citizen Network proxy

- Caches `sck_data_cache.json` for 5 min
- Fetches `/v0/devices?near=49.8175,15.473&per_page=100` (up to 5 pages)
- Filters by CZ geographic bbox and `last_reading_at` within 24 hours
- Blacklists non-CZ devices caught by bbox: `19547` (Warm Spark Cow, Wroclaw, PL)
- Matches PM2.5 and other sensors by name pattern (not hardcoded IDs)
- Transforms full device response into a compact format keyed by measurement ID
- Does **not** write cache if result is empty (avoids caching API errors)
- `?debug=1` returns diagnostic JSON without writing cache

### `chmi-proxy.php` — ČHMÚ proxy

- Two endpoints: `?type=metadata` and `?type=data`
- Cache TTL 15 min in `cache/` folder (created automatically)
- CORS restricted to `ALLOWED_DOMAIN`
- Debug header `X-Source: CHMI-Live` or `X-Source: Local-Cache`

### `pa-proxy.php` — PurpleAir proxy

- Caches `pa_data_cache.json` for 15 min
- Uses cURL (not `file_get_contents` — Active24 blocks custom `X-API-Key` header via stream context)
- Fetches `/v1/sensors` with tightened CZ bounding box and `location_type=0` (outdoor)
- Blacklists non-CZ sensors caught by bbox (Regensburg, Dresden, Neudorf, Borský Mikuláš)
- Applies EPA Barkjohn 2021 correction to PM2.5 using channels A+B average and humidity
- Converts temperature from Fahrenheit to Celsius
- Channel confidence check: flags sensors with divergent A/B channels as `confidence: "low"`
- CORS restricted to `ALLOWED_DOMAIN`
- Stale cache fallback if API unreachable
- `?debug=1` returns HTTP code, cURL error, and raw API response for troubleshooting

### `collect.php` — CRON data collector

- Called every 5 min via cron: `curl "https://senzorvzduchu.cz/historygraph/collect.php?token=TOKEN"`
- Validates token against `CRON_TOKEN` constant
- Processes SC JSON, ČHMÚ CSV, Smart Citizen JSON, and PurpleAir JSON (4 sources)
- SC/PA PM2.5 values ≤ 0 or > 500 μg/m³ are skipped before insert (Layer 1 absolute ceiling)
- PA PM10 values ≤ 0 or > 1000 μg/m³ are skipped before insert
- Inserts PM2.5 (and PM10 for PA) values into SQLite `measurements` table
- Deletes data older than 5 days (`$retention_days = 5`)
- Output: plain text `Status: OK. Inserted X measurements.`

**Database schema:**
```sql
CREATE TABLE measurements (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sensor_id   TEXT,     -- "SC_12345", "CHMI_AKALA", "SCK_19470", or "PA_12345"
    measured_at INTEGER,  -- Unix timestamp
    value_type  TEXT,     -- "PM2.5", "PM10" (PA sensors store both)
    value       REAL
);
CREATE INDEX idx_sensor_time ON measurements (sensor_id, measured_at);
CREATE INDEX idx_sensor_type ON measurements (sensor_id, value_type, measured_at);
```

### `monitor.php` — Database write monitor

- Called every 15 min via cron: `curl "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"`
- Validates token against `CRON_TOKEN` constant
- Checks `MAX(measured_at)` in the `measurements` table
- If no new data for 20+ minutes (`$stale_threshold = 1200`): sends push notification via **ntfy.sh**
- Cooldown: max 1 alert per 2 hours (`$cooldown_seconds = 7200`), tracked in `monitor_last_alert.txt`
- ntfy topic: `senzorvzduchu-monitor` — subscribe at https://ntfy.sh/senzorvzduchu-monitor or via ntfy mobile app
- Output: `OK: Data is fresh (last write X min ago)` or `ALERT SENT: ...`

### `get_history.php` — History API endpoint

- Called by frontend on popup open: `GET /historygraph/get_history.php?id=SC_12345`
- Input validation: regex `^(SC_\d+|CHMI_[A-Z0-9_]+|SCK_\d+|PA_\d+)$` — returns HTTP 400 on fail
- Queries last 5 days of PM2.5 for the sensor, ordered by timestamp
- Label format: `date('H:i|D', $timestamp)` → `"14:30|Mon"` (pipe-separated for JS parsing)
- **Quality check (Layer 3):** computes mean and std deviation of the last 2 hours of readings (up to 24 points). Returns a `quality` object alongside chart data. **Skipped entirely for CHMI sensors** (reference-grade, always trusted).
  - `plateau_high: true` — std dev < 3.0 AND mean > 60 AND ≥ 10 samples (clogged sensor / stuck fan)
  - `stuck: true` — std dev < 0.5 AND mean > 10 (exact same value repeating; mean > 10 prevents false positives in clean air)
  - Also returns `mean_2h`, `std_dev_2h`, `samples_2h` for diagnostics
- Response: `{ sensor_id, labels: ["14:30|Mon", ...], data: [15.2, ...], quality: { plateau_high, stuck, mean_2h, std_dev_2h, samples_2h } }`

---

## 6. Frontend Reference

### Map (`map/index.html` — full version, `map/embed.html` — iframe version)

Both files share identical JS logic. `embed.html` has additional compact CSS overrides at the bottom of its `<style>` block (smaller legend, hidden "hodinové průměry" subtitle, tighter popup sizing).

**Map initialisation:**
- Leaflet v1.9.4, CartoDB light tile layer
- Initial view: Czech Republic centre `[49.8175, 15.473]`, zoom 8
- Double-tap zoom on mobile: custom `touchend` listener (350ms window) calling `map.setZoomAround()`

**Markers:**
- Radius: `8 + Math.min(pm25 / 10, 10)` — scales with pollution level
- SC sensors: thin white border (weight 1)
- ČHMÚ stations: black 2px border; if no PM2.5 (only NO2/O3): transparent fill, grey border
- SCK sensors: same style as SC (thin white border, weight 1)
- PurpleAir sensors: purple 2px border (`#7B2D8E`)
- PurpleAir ceiling: PM2.5 > 500 μg/m³ filtered out in frontend (same as collect.php Layer 1)
- Data refresh: `setInterval(fetchAllData, 300000)` — every 5 min

**Layer groups & network toggle:**
- Each data source has its own `L.layerGroup()` stored in `window.layers = { community, chmi, sck, purpleair }`
- New markers are added to `window.layers[meta.type]` instead of directly to `map`
- Clicking a source in the legend toggles its layer group on/off (`map.addLayer()` / `map.removeLayer()`)
- Toggled-off sources show strikethrough + reduced opacity (`.legend-source-item.disabled`)
- Toggle state persists across data refreshes (existing markers update in place within their layer group)

**AQI colour scale (PM2.5 μg/m³):**

| Range | Label | Colour |
|-------|-------|--------|
| 0 – 12.0 | Dobrá | `#87C159` |
| 12.1 – 35.4 | Střední | `#F4D35E` |
| 35.5 – 55.4 | Zhoršená | `#F48C42` |
| 55.5 – 150.4 | Nezdravá | `#E64A45` |
| 150.5 – 250.4 | Velmi nezdravá | `#8C6AB6` |
| 250.5+ | Nebezpečná | `#7D5963` |
| No data | — | `#9ca3af` |

**Popup structure:**
1. Header — sensor name / station name
2. Hero — large PM2.5 value coloured by AQI scale
3. History chart (Chart.js, 180px height)
4. Quality warning banner (amber, hidden by default) — shown by `loadHistory()` if `quality.plateau_high` or `quality.stuck` is true: "⚠ Senzor vykazuje dlouhodobě konstantní/nízkou hodnotu – možné ucpání, nebo porucha ventilátoru"
5. Other values — PM10, temperature, humidity, pressure, NO2, O3
   - Hidden: raw particle counts N05, N1, N25, N4, N10, TS
6. Footer — data source (ČHMÚ / Sensor.Community / Smart Citizen / PurpleAir)

**Legend sources section** (below AQI colour rows): small labelled dots distinguishing the four sources — SC (thin border), ČHMÚ (black border), SCK (dashed border), PurpleAir (purple border). Each source item has a `data-layer` attribute and acts as a toggle button — click to hide/show that network's markers on the map.

**History chart details:**
- Line chart with gradient fill (green → yellow → red)
- Y axis: `suggestedMax: 60`, labelled `μg/m³`
- Dashed reference line at 25 μg/m³ labelled `"24/h limit 2030"` (hidden in tooltip)
- Legend position: **top**, right-aligned, showing only the 25 μg/m³ reference line
- X axis: hidden (day labels drawn by custom plugin)
- **`midnightPlugin`** (custom Chart.js `afterDraw` plugin):
  - Draws a faint vertical line at the first data point of each midnight (hour === 0)
  - Draws Czech day abbreviation below the chart: Po, Út, St, Čt, Pá, So, Ne
  - Uses `layout.padding.bottom: 16` to reserve space for the labels
- **Tooltip title:** formatted as `"Po 14:30"` — Czech day abbreviation + `H:i` time
  - Parsed from raw label `"14:30|Mon"` via `item.chart.data.labels[item.dataIndex]`

**Hardcoded exclusions:**
- `sensor.sensor.id === 46875` — faulty SC sensor, excluded in both frontend and `collect.php`
- `sensor.sensor.id === 7213` — faulty SC sensor, excluded in both frontend and `collect.php`
- `sensor.location.indoor == 1` — indoor SC sensors excluded
- `!sensor.location.latitude || !sensor.location.longitude` — sensors with missing/invalid coordinates excluded
- SCK device `19547` (Warm Spark Cow) — Wroclaw, Poland; excluded in `sck-proxy.php` blacklist
- PA sensors `4619`, `32145`, `171945`, `60443`, `202867` — non-CZ sensors (DE/AT/SK); excluded in `pa-proxy.php` blacklist

**Automatic faulty sensor detection (SC only):**
- **Layer 1** (`collect.php`): PM2.5 ≤ 0 or > 500 μg/m³ skipped before DB insert
- **Layer 2** (frontend, `fetchSensorCommunityData`): neighbor-ratio filter — `haversineKm()` + `medianOf()` helpers; sensor suppressed if PM2.5 > `max(60, neighbor_median × 5)` with ≥ 2 neighbors in 0.1–30 km range
- **Layer 3** (`get_history.php` + frontend `loadHistory`): plateau/stuck detection on last 2h of DB readings; amber warning banner in popup if flagged. CHMI sensors excluded (always trusted). `stuck` requires mean > 10 μg/m³ to avoid false positives in clean air.

---

## 7. Security & Configuration

| Item | Status | Detail |
|------|--------|--------|
| CRON token | ✅ Secured | In `config.php`, never in code |
| Input validation | ✅ Active | Regex in `get_history.php` |
| CORS | ✅ Restricted | `ALLOWED_DOMAIN` constant, not `*` |
| User-Agent | ✅ Fixed | `USER_AGENT` constant, no email exposure |
| SSL verification | ✅ On | `CURLOPT_SSL_VERIFYPEER = true` |
| DB index | ✅ Optimised | Composite `(sensor_id, value_type, measured_at)` |

**If token needs to be regenerated:**
1. Generate new hex token (see Section 5 → `config.php`)
2. Update `config.php` on server
3. Update cron job URL with new token
4. Test: `https://senzorvzduchu.cz/historygraph/collect.php` → must return `Access Denied`
5. Test: `https://senzorvzduchu.cz/historygraph/collect.php?token=NEW_TOKEN` → must return `Status: OK`

---

## 8. Deployment

**Method:** FTP upload to shared hosting. No SSH, no cPanel.

**Files that require upload when changed:**

| Change type | Files to upload |
|-------------|----------------|
| Security / config | `config.php` + affected PHP file |
| Data collection logic | `collect.php` |
| History API | `get_history.php` |
| Map frontend | `map/index.html`, `map/embed.html` |
| SC proxy | `sc-proxy.php` |
| ČHMÚ proxy | `chmi-proxy.php` |
| Smart Citizen proxy | `sck-proxy.php` |
| PurpleAir proxy | `pa-proxy.php` |

**Cron job** (in hosting panel → Scheduled Tasks):
```
curl -s "https://senzorvzduchu.cz/historygraph/collect.php?token=TOKEN"
```
Interval: every 5 minutes.

**Post-upload verification:**
1. `https://senzorvzduchu.cz/historygraph/collect.php` → `Access Denied: Invalid Token` ✓
2. `https://senzorvzduchu.cz/historygraph/collect.php?token=TOKEN` → `Status: OK. Inserted X measurements.` ✓
3. `https://senzorvzduchu.cz/historygraph/get_history.php?id=../etc/passwd` → HTTP 400 ✓
4. `https://senzorvzduchu.cz/sck-proxy.php?debug=1` → JSON with `cz_bbox_found` and `active_cz_count` ✓
5. `https://senzorvzduchu.cz/pa-proxy.php` → JSON array of CZ PurpleAir sensors (no Dresden/Regensburg/Wroclaw) ✓
6. `https://senzorvzduchu.cz/pa-proxy.php?debug=1` → `http_code: 200` (if 403: check API key host restrictions) ✓
7. Open map → F12 Console → no errors ✓
8. F12 Network → `sc-proxy.php` response header → `Access-Control-Allow-Origin: https://senzorvzduchu.cz` ✓
9. Click legend source items → markers toggle on/off, text goes strikethrough ✓
10. No "Warm Spark Cow" (Wroclaw) visible on map ✓

---

## 9. Version Control

**Repository:** https://github.com/senzorvzduchu/map (public)
**Branch:** `main`

### What's tracked

Source code + documentation + deployment config template. See [`.gitignore`](../.gitignore) for the complete exclusion list.

### What's NOT tracked (by design)

| File / Path | Reason |
|---|---|
| `www/config.php` | Contains `CRON_TOKEN` and `PURPLEAIR_API_KEY` — secrets |
| `www/crontoken.txt` | Duplicate of the token |
| `www/sc_data_cache.json`, `www/sck_data_cache.json`, `www/pa_data_cache.json` | API response caches (regenerated) |
| `www/cache/*` | ČHMÚ cache (regenerated) |
| `www/historygraph/*.db` + journal files | SQLite DB (rolling 5-day window, regenerated by cron) |
| `www/historygraph/monitor_last_alert.txt` | Runtime cooldown state |
| `www/cz_sensors.json` | Legacy/generated sensor dump |
| `www/backup/`, `www/historygraph/backup/` | Old versions (superseded by git history) |
| `.claude/` | Local Claude Code session state |

### Workflow

**Clone fresh on a new machine:**
```bash
git clone https://github.com/senzorvzduchu/map.git
cd map
cp www/config.example.php www/config.php
# Edit www/config.php — fill in CRON_TOKEN and PURPLEAIR_API_KEY
```

**Making changes:**
```bash
git status                    # always verify config.php is NOT listed
git add <files>
git commit -m "message"
git push
```

### Security rules (critical)

1. **Always run `git status` before `git commit`.** If you see `www/config.php` or `www/crontoken.txt` in the output — STOP. Something is wrong with `.gitignore`.
2. **Never commit a real token in `config.example.php`** — only placeholder strings like `REPLACE_WITH_HEX_TOKEN_...`.
3. If a secret ever lands in git: rotate the secret first (on the server + cron), then clean the history (`git filter-repo` or BFG) and force-push.

### Deployment is separate from git

Git is for source-of-truth and history only. **Deploying to the shared hosting is still a manual FTP upload** — there is no CI/CD or automatic deploy hook. See Section 8 (Deployment) for the upload checklist.

---

## 10. Changelog

### Iteration 1 — Core architecture
- Designed and built SQLite + CRON history layer ("Time Machine")
- Wrote `collect.php` and `get_history.php`
- Integrated Chart.js into Leaflet popups via `popupopen` event listener
- Gradient fill, Y axis, dashed 25 μg/m³ reference line with custom legend
- Created `embed.html` for iframe embedding
- Enabled `doubleClickZoom`, filtered indoor SC sensors

### Iteration 2 — Security hardening & production deployment (2026-03-21)
- Created `config.php` with `CRON_TOKEN`, `ALLOWED_DOMAIN`, `USER_AGENT`
- Moved hardcoded token out of `collect.php`
- Added input validation regex to `get_history.php`
- Restricted CORS from `*` to `ALLOWED_DOMAIN` in both proxies
- Fixed malformed User-Agent in `sc-proxy.php` (removed exposed email)
- Enabled `CURLOPT_SSL_VERIFYPEER = true` in `collect.php`
- Added composite DB index `(sensor_id, value_type, measured_at)`
- Deployed to production via FTP, cron updated with new hex token

### Iteration 4 — Smart Citizen Network integration (2026-03-21)

- Added `sck-proxy.php`: fetches Smart Citizen `/v0/devices?near=` API, filters by CZ geographic bbox (lat 48.5–51.2, lng 12.0–18.9), filters inactive sensors (`last_reading_at` > 24h), normalises sensor readings to numeric measurement ID keys, caches 5 min
- Added `fetchSmartCitizenData()` to both `map/index.html` and `map/embed.html`, called in `Promise.all` alongside SC and ČHMÚ fetchers
- Extended `collect.php` with SCK collection section (same filtering logic as proxy)
- Extended `get_history.php` input validation regex to accept `SCK_\d+` sensor IDs
- Added sources legend section to both map versions (SC / ČHMÚ / Smart Citizen distinguishing dots)
- Popup footer now labels Smart Citizen sensors as "Smart Citizen"
- **API gotchas discovered during development:**
  - `world_map` endpoint broken — returns root API response, not device array
  - `country_code_eq` Ransack filter returns 0 results for CZ devices (field not indexed/queryable)
  - `country_code` field is null for all devices in the `world_map` response
  - Reliable approach: `?near=CZ_CENTRE&per_page=100` + client-side bbox filter
  - PM2.5 sensor ID varies by kit version — name-pattern matching (`"2.5"`) is more robust than hardcoded IDs
- 6 active outdoor CZ Smart Citizen devices as of deployment

### Iteration 9 — Database write monitor (2026-03-22)

- **Problem:** On 2026-03-21 (Friday), hosting silently stopped writing to the SQLite database for ~24 hours. No alerting existed — the data gap was only discovered manually.
- **Solution:** New `monitor.php` health check script. Checks `MAX(measured_at)` in the DB; if no new data for 20+ minutes, sends a push notification via ntfy.sh. Cooldown prevents spam (1 alert per 2 hours).
- **Why ntfy.sh:** PHP `mail()` is disabled on the shared hosting. ntfy.sh requires no signup, no SMTP config — just a cURL POST from PHP.
- **CRON:** `*/15 * * * * curl -s "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"`
- **Files added:** `historygraph/monitor.php`

### Iteration 12 — Git + GitHub setup (2026-04-16)

- **Repository created:** https://github.com/senzorvzduchu/map (public)
- Added `README.md` with project overview and setup instructions
- Added `.gitignore` covering secrets, caches, SQLite DB, backups, and local tooling state
- Caught and fixed a token leak in `config.example.php` before first commit (replaced real hex with `REPLACE_WITH_HEX_TOKEN_...` placeholder)
- Verified no secrets in git history: `git log --all -p | grep <token>` returns 0
- Deleted old `www/backup/` and `www/historygraph/backup/` folders — history is now preserved in git itself
- Deleted legacy `www/cz_sensors.json` (no longer used)
- Rotated `CRON_TOKEN` as precaution (old token had appeared in dev transcripts)
- **Files added:** `.gitignore`, `README.md`
- **Files changed:** `www/config.example.php` (token placeholder), `www/config.php` on server (new token)
- **Deployment note:** Git is source-of-truth only. FTP upload to Active24 shared hosting remains manual.

### Iteration 11 — Network toggle & non-CZ sensor cleanup (2026-04-16)

- **Network toggle:** Legend source items (SC, ČHMÚ, SCK, PurpleAir) are now clickable — click to hide/show all markers from that network
  - Each data source has its own `L.layerGroup()` in `window.layers`
  - Markers are added to their network's layer group instead of directly to `map`
  - Toggled-off sources show strikethrough text + reduced opacity (CSS `.legend-source-item.disabled`)
  - Toggle state persists across 5-min data refreshes
- **SCK blacklist:** Added device `19547` (Warm Spark Cow, Wroclaw, Poland) to `sck-proxy.php` blacklist
- **PA non-CZ sensor cleanup:**
  - Tightened CZ bounding box: lat 48.55–51.06, lng 12.10–18.87 (was 48.5–51.2, 12.0–18.9)
  - Added blacklist in `pa-proxy.php`: sensors `4619` (Regensburg), `32145` (Dresden), `171945` (BBB39/Dresden), `60443` (Neudorf/AT), `202867` (Borský Mikuláš/SK)
- **PA proxy fix:** Switched from `file_get_contents` to cURL — Active24 shared hosting blocks custom `X-API-Key` header via PHP stream context
- **PA frontend ceiling:** Added `pm25 > 500` filter in `fetchPurpleAirData()` (matches collect.php Layer 1)
- **PA debug mode:** `?debug=1` returns HTTP code, cURL error, and raw API response
- **Files changed:** `sck-proxy.php`, `pa-proxy.php`, `map/index.html`, `map/embed.html`

### Iteration 10 — PurpleAir integration (2026-04-16)

- Added `pa-proxy.php`: fetches PurpleAir `/v1/sensors` API with CZ bounding box, outdoor-only filter, 15-min cache
- **EPA Barkjohn 2021 correction** applied server-side: `PM2.5_corrected = max(0, 0.524 * avg_cf1 - 0.0862 * RH + 5.75)` using averaged channels A+B and humidity. PurpleAir API does NOT pre-apply EPA correction to any field.
- Channel confidence check: flags sensors with divergent A/B channels as `confidence: "low"`
- Temperature converted from Fahrenheit to Celsius in proxy
- Added `fetchPurpleAirData()` to both `map/index.html` and `map/embed.html`, called in `Promise.all` alongside other fetchers
- PurpleAir markers: purple 2px border (`#7B2D8E`), distinguishable from AQI purple color (`#8C6AB6`)
- Extended `collect.php` with PA collection section (PM2.5 + PM10)
- Extended `get_history.php` input validation regex to accept `PA_\d+` sensor IDs
- Quality checks (plateau/stuck) apply to PA sensors (same class of laser particle counters as SC)
- Added `PURPLEAIR_API_KEY` to `config.php` and `config.example.php`
- Legend updated with PurpleAir source indicator in both map versions
- **Cost:** ~4.7M points/year at 15-min refresh, 9 fields, ~15 CZ sensors (~$47/year)
- **Infrastructure impact:** Negligible — 1 API call per 15 min, ~5KB response, ~17K extra SQLite rows over 5 days
- **Files added:** `pa-proxy.php`
- **Files changed:** `config.php`, `config.example.php`, `map/index.html`, `map/embed.html`, `historygraph/collect.php`, `historygraph/get_history.php`

### Iteration 8 — Quality check false positive fix (2026-03-22)

- **Problem:** During clean air (PM2.5 < 10 μg/m³, 75% of sensors), the `stuck` detection (`std_dev < 0.5`) triggered false warnings on healthy sensors. Also, ČHMÚ reference stations incorrectly showed quality warnings.
- **Fix 1:** Added `mean > 10` requirement to `stuck` condition. Below 10 μg/m³, low variance is expected and harmless.
- **Fix 2:** CHMI sensors skip quality check entirely (`str_starts_with($sensor_id, 'CHMI_')`) — reference-grade instruments, always trusted.
- **Validated:** 91 of 121 sensors (75%) reading < 10 μg/m³ on deployment day — all correctly stop showing false warnings.
- **Files changed:** `historygraph/get_history.php`

### Iteration 7 — Automatic faulty SC sensor filtering (2026-03-21)

- **Problem:** Faulty SC sensors (clogged, broken fan) required manual discovery and hardcoding of sensor IDs. No mechanism existed to catch new ones automatically.
- **Solution:** Three-layer automatic filter targeting SC sensors only.
- **Layer 1 — Absolute ceiling (`collect.php`):** PM2.5 ≤ 0 or > 500 μg/m³ rejected before DB insert. Prevents corrupt history from accumulating.
- **Layer 2 — Neighbor-ratio filter (frontend):** `fetchSensorCommunityData()` refactored from two passes to three. Third pass applies `haversineKm()` + `medianOf()` helpers: if sensor reads > `max(60, neighbor_median × 5)` with ≥ 2 real neighbors in 0.1–30 km range, it is suppressed from the map and logged to console as `[SC Outlier]`. Co-located chips (< 0.1 km) excluded from neighbor set to prevent mutual reinforcement.
- **Layer 3 — Plateau/stuck detection (`get_history.php` + frontend):** `get_history.php` now computes mean and std dev of last 2 hours of readings and returns a `quality` object (`plateau_high`, `stuck`, `mean_2h`, `std_dev_2h`, `samples_2h`). Frontend `loadHistory()` shows an amber warning banner in the popup if `plateau_high` or `stuck` is true.
- **Bug fix:** added `!sensor.location.longitude` guard to the SC coordinate check (previously only latitude was checked), fixing Leaflet SVG NaN errors from sensors with missing longitude.
- **Validated:** SC_77008 immediately caught on first deployment (90–107 μg/m³ vs neighbor median 7.1 μg/m³, threshold 60.0). The two previously hardcoded sensors (7213, 46875) would also be caught by all three layers.
- **Files changed:** `map/index.html`, `map/embed.html`, `historygraph/collect.php`, `historygraph/get_history.php`

### Iteration 6 — SC sensor 7213 exclusion (2026-03-21)

- Added sensor ID `7213` to hardcoded exclusion list alongside `46875` (faulty sensor with unreliable readings)
- **Files changed:** `map/index.html`, `map/embed.html`, `historygraph/collect.php`

### Iteration 5 — SC popup temperature & humidity (2026-03-21)

- **Problem:** Sensor.Community popups showed PM2.5 and PM10 but not temperature or humidity, despite most outdoor nodes having a co-located DHT22/BME280 sensor.
- **Root cause:** The SC API returns one entry per sensor chip. The environmental chip entry (temp/humidity) has no `P2` field and was being silently skipped by the existing `pm25 === null` guard.
- **Fix:** `fetchSensorCommunityData()` in both `map/index.html` and `map/embed.html` now runs a two-pass approach: first pass collects `{temperature, humidity}` keyed by `location.id`; second pass (dust sensors only) looks up that map and appends the values to `otherValues`. The popup renderer already handled `temperature` → "Teplota °C" and `humidity` → "Vlhkost %" — no popup template changes required.
- **Files changed:** `map/index.html`, `map/embed.html`

### Iteration 3 — UX improvements (2026-03-21)
- `embed.html` differentiated from `index.html` with compact CSS (smaller legend, tighter popups, hidden subtitle) — optimised for mobile iframe use
- **Double-tap zoom on mobile fixed:** custom `touchend` listener (350ms window) calls `map.setZoomAround()` directly — CSS `touch-action: manipulation` alone was insufficient
- **Chart day timeline:** custom `midnightPlugin` draws faint vertical separator lines and Czech day abbreviations (Po/Út/St/Čt/Pá/So/Ne) at midnight boundaries
- **Chart legend** moved from bottom to top to avoid overlap with day labels
- **Tooltip** now shows `"Po 14:30"` format (Czech day + time); `get_history.php` label format changed to `H:i|D`; tooltip reads via `dataIndex` for reliability

---

## 10. AI Context Prompt (copy-paste for new sessions)

*When starting a new conversation with any AI assistant about this project, paste the block below as the first message.*

> **[CONTEXT START]**
>
> I am a developer working on an air quality monitoring map — Senzorvzduchu.cz.
>
> **Tech stack:** Vanilla JS + Leaflet.js + Chart.js frontend, PHP 8 backend, SQLite database (`air_quality_history.db`). Hosted on shared hosting, deployed via FTP. No SSH access.
>
> **Architecture:**
> 1. Frontend fetches live data every 5 min from three PHP caching proxies: `sc-proxy.php` (Sensor.Community JSON), `chmi-proxy.php` (ČHMÚ metadata + CSV), and `sck-proxy.php` (Smart Citizen Network).
> 2. ČHMÚ data is joined via `IdRegistration` from metadata to CSV values.
> 3. Smart Citizen proxy uses `/v0/devices?near=49.8175,15.473&per_page=100` (up to 5 pages), filters by CZ bbox (lat 48.5–51.2, lng 12.0–18.9) and `last_reading_at` within 24h, matches PM2.5 by sensor name containing "2.5". `world_map` endpoint and `country_code_eq` Ransack filter are both broken for this use case.
> 4. A CRON job runs `collect.php` every 5 min, writing PM2.5 values to SQLite table `measurements` (columns: `sensor_id`, `measured_at`, `value_type`, `value`). 5-day rolling retention.
> 5. Frontend has two versions: `map/index.html` (full) and `map/embed.html` (compact iframe, used in WordPress). Both use Leaflet circle markers — ČHMÚ stations have black 2px border, SC and SCK sensors have thin white border. Clicking a marker opens a popup which calls `get_history.php?id=SENSOR_ID` and renders a Chart.js line chart.
>
> **Sensor ID prefixes:** `SC_12345` (Sensor.Community), `CHMI_AKALA` (ČHMÚ), `SCK_19470` (Smart Citizen).
>
> **Chart details:** Gradient fill (green→yellow→red), dashed 25 μg/m³ reference line ("24/h limit 2030"), legend at top. Custom `midnightPlugin` draws faint vertical day separator lines and Czech day labels (Po/Út/St/Čt/Pá/So/Ne) at midnight. Tooltip title shows "Po 14:30" format. Label format from PHP: `H:i|D` → "14:30|Mon".
>
> **Filtering rules:** SC indoor sensors excluded. SC sensor IDs 46875 and 7213 hardcoded excluded (faulty). SCK sensors inactive >24h excluded. Raw particle counts (N05–N10) hidden in popup. Only PM2.5 stored in DB from all three sources.
>
> **Automatic faulty SC sensor detection (3 layers):** Layer 1 — `collect.php` rejects PM2.5 ≤ 0 or > 500 μg/m³ before DB insert. Layer 2 — frontend neighbor-ratio filter suppresses sensors reading > max(60, neighbor_median × 5) with ≥ 2 real neighbors in 0.1–30 km range (logged as `[SC Outlier]`). Layer 3 — `get_history.php` returns a `quality` object (plateau_high, stuck, mean_2h, std_dev_2h) computed from last 2h of readings; frontend shows amber warning banner in popup if flagged. CHMI sensors skip quality check (always trusted). `stuck` requires mean > 10 to avoid false positives in clean air.
>
> **Monitoring:** `monitor.php` runs via CRON every 15 min, checks DB freshness. If no new data for 20+ min, sends push notification via ntfy.sh (topic: `senzorvzduchu-monitor`). Cooldown: 1 alert per 2 hours.
>
> **SC three-pass parsing:** The SC API returns one entry per sensor chip (dust chip + separate environmental chip per node, same `location.id`). `fetchSensorCommunityData()` does a first pass to collect `{temperature, humidity}` by `location.id`, a second pass to build the `scSensors[]` array enriched with temp/humidity, and a third pass to apply the neighbor-ratio outlier filter before calling `createOrUpdateMarker()`.
>
> **Security:** All secrets in `www/config.php` (not in version control) — constants `CRON_TOKEN`, `ALLOWED_DOMAIN`, `USER_AGENT`. CORS restricted to domain. Input validated by regex `^(SC_\d+|CHMI_[A-Z0-9_]+|SCK_\d+)$`. SSL verification on. Token is a 64-char hex string (no URL-special chars).
>
> **Mobile:** Double-tap zoom implemented via custom `touchend` listener (not Leaflet's built-in). `touch-action: manipulation` on `#map`.
>
> **[CONTEXT END]**
