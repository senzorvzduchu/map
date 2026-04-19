# senzorvzduchu.cz — Air Quality Map

Real-time air quality monitoring map for the Czech Republic. Aggregates data from four sources into a unified interactive Leaflet map with 5-day historical charts.

**Live:** https://senzorvzduchu.cz/map/

## Data Sources

| Source | Type | Sensors |
|--------|------|---------|
| **Sensor.Community** | Community air quality sensors (SDS011, etc.) | ~120 in CZ |
| **ČHMÚ** | Official government reference stations | ~30 in CZ |
| **Smart Citizen Network** | IoT research kits (PMS5003) | ~6 in CZ |
| **PurpleAir** | Laser particle counters with EPA Barkjohn correction | ~12 in CZ |

All PM2.5 values are displayed on a unified AQI color scale. PurpleAir data is EPA-corrected server-side using the Barkjohn 2021 formula.

## Architecture

No frameworks, no build tools, no database server. Standard shared PHP hosting + SQLite.

```
External APIs
  ├── Sensor.Community JSON  →  sc-proxy.php   →  sc_data_cache.json
  ├── ČHMÚ metadata + CSV    →  chmi-proxy.php →  cache/
  ├── Smart Citizen API      →  sck-proxy.php  →  sck_data_cache.json
  └── PurpleAir API          →  pa-proxy.php   →  pa_data_cache.json

Frontend (Leaflet, every 5 min)
  └── fetches all four proxies → renders markers with toggle support

CRON (every 5 min)
  └── collect.php → reads all four proxies → writes to SQLite

User clicks marker → popup with 5-day Chart.js graph
```

**Tech stack:** Vanilla JS / HTML5 / CSS3 · Leaflet v1.9.4 · Chart.js · PHP 8.x · SQLite · cURL

## Project Structure

```
www/
├── config.php                  ← Secrets (NOT in repo — see config.example.php)
├── config.example.php          ← Template for config.php
├── sc-proxy.php                ← Sensor.Community proxy (5 min cache)
├── chmi-proxy.php              ← ČHMÚ proxy (15 min cache)
├── sck-proxy.php               ← Smart Citizen proxy (5 min cache)
├── pa-proxy.php                ← PurpleAir proxy with EPA correction (15 min cache)
├── cache/                      ← ČHMÚ cached JSON + CSV (auto-generated)
├── map/
│   ├── index.html              ← Main map (full desktop/mobile)
│   └── embed.html              ← Compact iframe variant
└── historygraph/
    ├── collect.php             ← CRON data collector (every 5 min)
    ├── get_history.php         ← History API for chart popups
    ├── monitor.php             ← DB freshness monitor (ntfy.sh alerts)
    └── air_quality_history.db  ← SQLite (rolling 5-day window, auto-generated)

documentation/
├── DOCUMENTATION.md            ← Full technical documentation
└── TEST_GUIDE.md               ← Testing procedures
```

## Setup

1. **Copy `www/config.example.php` → `www/config.php`** and fill in real values:
   ```php
   define('CRON_TOKEN', '...');          // openssl rand -base64 32
   define('ALLOWED_DOMAIN', 'https://senzorvzduchu.cz');
   define('USER_AGENT', 'AirQualityMonitor/1.0 (senzorvzduchu.cz)');
   define('PURPLEAIR_API_KEY', '...');   // get at https://develop.purpleair.com
   ```

2. **Upload to shared hosting via FTP.**

3. **Configure cron jobs** (in hosting panel):
   ```
   */5  * * * * curl -s "https://senzorvzduchu.cz/historygraph/collect.php?token=TOKEN"
   */15 * * * * curl -s "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"
   ```

4. **Done.** The SQLite DB is created automatically on first `collect.php` run.

## Documentation

Full technical documentation in [`documentation/DOCUMENTATION.md`](documentation/DOCUMENTATION.md) — includes architecture details, EPA correction explanation, data flow, security notes, deployment checklist, and complete changelog.

## License

All rights reserved © Senzorvzduchu z.s.
