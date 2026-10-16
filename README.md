# senzorvzduchu.cz — Air Quality Map (3.1 — ČHMÚ meteo stations as T/RH reference, SANDBOX on top of 3.0; 2.9 in production since 2026-10-16)

Real-time air quality monitoring map for the Czech Republic. Aggregates data from six sources into a unified interactive Leaflet map with 5-day historical charts.

> **Version 3.1 (sandbox, verified locally 2026-10-16):** 60 ČHMÚ meteorological stations from the ČHMÚ open-data 10-minute feed (`chmi-meteo-proxy.php` + `lib/ChmiMeteo.php`; one JSON per station per day → fixed city subset, 15-min self-refreshing cache, parallel download) shown as the temperature/humidity **reference** with the black ČHMÚ border; ids `CHMI_M_<WSI>`, history via `collect.php` (deduped by source timestamp). See `DEPLOY_3.1.md`, `MIGRATION.md`.
>
> **Version 3.0 (sandbox, verified locally 2026-10-16):** temperature and humidity as two more entries in the pollutant bar — ~160 community sensors (Sensor.Community, PurpleAir, Smart Citizen, Libčich, AirGradient; ČHMÚ hidden), own colour scales (blue→red, ochre→blue), 5-day history chart (hourly means), implausible readings (dead DHT22 at −14x °C) hidden, PurpleAir sensor offset (−8 °F / +4 % RH), no interpolation (enclosure heating). Also: one Sensor.Community record per sensor per collector run (halves SC rows). See `DEPLOY_3.0.md`, `MIGRATION.md`.
>
> **Version 2.9 (deployed 2026-10-16, verified live):** sixth network **AirGradient** (public world feed, no key; `ag-proxy.php` + `lib/AirGradient.php`, CZ bbox filter, 3-min self-refreshing cache, grey marker for readings 30 min–2 h old, CO₂ chip, CC BY-SA 4.0 attribution, history via `collect.php`). See `DEPLOY_2.9.md`, `MIGRATION.md`.
>
> **Version 2.8 (deployed 2026-09-04, 2.8.7 on 2026-09-09):** multi-pollutant map ported from nosicheck.cz — pollutant bar (PM2.5 / PM10 / NO₂ / O₃ / noise), IDW interpolation overlay with wind advection, animated wind layer (Open-Meteo), methodology modal, label tiles above the color field, ČHMÚ air-quality warnings (slide-in banner), ČHMÚ forecast in the popup chart (24 h + 24 h outlook / 5 days), sensor bans + auto quality flags. Frontend split into shared `map/*.js` + `style.css`. See `TESTING.md` and `MIGRATION.md`. Production is 2.7 (CARTO key).

**Live:** https://senzorvzduchu.cz/map/

## Data Sources

| Source | Type | Sensors |
|--------|------|---------|
| **Sensor.Community** | Community air quality sensors (SDS011, etc.) | ~120 in CZ |
| **ČHMÚ** | Official government reference stations | ~30 in CZ |
| **Smart Citizen Network** | IoT research kits (PMS5003) | ~6 in CZ |
| **PurpleAir** | Laser particle counters with EPA Barkjohn correction | ~12 in CZ |
| **Projekt Libčich** | Dense local LoRa network (libcich.cz), raw 2-min + hourly AQI | ~45 in Libčice n. Vlt. |
| **AirGradient** | Open-source monitors (PMS5003, raw PM + CO₂/T/RH), public world feed, CC BY-SA 4.0 | ~23 in CZ |

All PM2.5 values are displayed on a unified AQI color scale. PurpleAir data is EPA-corrected server-side using the Barkjohn 2021 formula.

## Architecture

No frameworks, no build tools, no database server. Standard shared PHP hosting + SQLite.

```
External APIs
  ├── Sensor.Community JSON  →  sc-proxy.php   →  sc_data_cache.json
  ├── ČHMÚ metadata + CSV    →  chmi-proxy.php →  cache/
  ├── Smart Citizen API      →  sck-proxy.php  →  sck_data_cache.json
  ├── PurpleAir API          →  pa-proxy.php   →  pa_data_cache.json
  ├── libcich.cz API         →  libcich-proxy.php (mode=aq / mode=aqi)
                                  →  libcich_data_cache.json / libcich_aqi_cache.json
  └── AirGradient world feed →  ag-proxy.php   →  cache/airgradient.json (CZ subset, 3 min)

Frontend (Leaflet, every 5 min)
  └── fetches all six proxies → renders markers with toggle support
      (libcich layer = markercluster + "zoom to Libčice" control)

CRON (every 5 min)
  └── collect.php → reads all six proxies → writes to SQLite
      (libcich: hourly AQI averages, deduped per hour)

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
├── libcich-proxy.php           ← Projekt Libčich proxy: ?mode=aq live (2 min cache) / ?mode=aqi hourly (10 min cache)
├── wind-proxy.php              ← 2.8: Open-Meteo wind grid for leaflet-velocity + interpolation (60 min cache, no cron)
├── alerts-proxy.php            ← 2.8: ČHMÚ SIVS warnings (CAP), air-quality category only (10 min cache, no cron)
├── forecast-proxy.php          ← 2.8: ČHMÚ model forecast per kraj, anchored to the sensor (30 min cache, no cron)
├── sensor-bans.php             ← 2.8: public ban list + auto quality flags (10 min cache)
├── admin/bans.php              ← 2.8: token-gated ban management UI
├── lib/                        ← 2.8: Http, Alerts, Forecast (from nosicheck.cz), SensorQuality
├── cache/                      ← ČHMÚ cached JSON + CSV (auto-generated)
├── map/
│   ├── index.html              ← Main map shell (full desktop/mobile)
│   ├── embed.html              ← Compact iframe shell (same JS, compact CSS overrides)
│   ├── style.css               ← 2.8: shared styles
│   ├── app.js                  ← 2.8: core — POLLUTANTS registry, sensorStore, 5 fetchers, markers, history chart
│   ├── interp.js               ← 2.8: IDW interpolation overlay (trust-weighted, wind-advected)
│   ├── wind.js                 ← 2.8: animated wind layer (leaflet-velocity)
│   ├── info.js                 ← 2.8: methodology modal
│   ├── alerts.js               ← 2.8: ČHMÚ air-quality warning banner + ORP lookup
│   └── orp-areas.js            ← 2.8: ORP polygons (generated at nosicheck.cz, copied)
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
