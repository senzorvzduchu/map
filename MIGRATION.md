# 2.6 — CHMÚ staleness fix

## Problem (2026-06-30)

ČHMÚ open-data feed `airquality_1h_avg_CZ.csv` froze at the source on **Mon 29 Jun
~08:29 GMT** (file `last-modified`), containing only the single hour
`2026-06-29T07:00:00Z`. ČHMÚ's own graphs also stopped at 29.6 10:00 local.

`collect.php` stamped every CHMÚ insert with `time()` (cron run time) instead of
the CSV `startTime`. So every 5-minute cron re-inserted the same frozen value
(e.g. Praha 2-Legerova = 23.7) under a fresh timestamp → a flat horizontal line
that crept rightward across the graph. Other networks (SC / PA / SCK / Libčich)
have live feeds and were unaffected.

## Fix

Mirrors the existing Libčich hourly-average pattern.

- **`www/historygraph/collect.php`** — CHMÚ rows now take `measured_at` from the
  CSV `startTime` (hour start, UTC) and are **deduplicated** per
  `(sensor_id, value_type, measured_at)`. A frozen upstream stops extending the
  line at the last real hour instead of drawing a fake tail.
- **`www/historygraph/get_history.php`** — `quality` now includes
  `latest_age_sec` and a `stale` flag (CHMÚ, newest point older than 3 h).
- **`www/map/index.html` + `www/map/embed.html`** — when `quality.stale` is set,
  the popup shows "ČHMÚ momentálně neaktualizuje data …" instead of the
  community-sensor fan/clog warning.

Verified against the live feed: dedup re-run inserts 0, all 54 CHMÚ PM2.5 rows
land on exact hour boundaries, ALEG = 23.7 @ 2026-06-29T07:00Z (single row).

## Deploy (manual FTP — git does not deploy)

Upload to the Active24 host, overwriting the 2.5 versions:

- `www/historygraph/collect.php`
- `www/historygraph/get_history.php`
- `www/map/index.html`
- `www/map/embed.html`

Do **not** upload `config.php`, `crontoken.txt`, or `historygraph/air_quality_history.db`.

## One-time cleanup of the live DB (optional but recommended)

The already-stored garbage rows (frozen value re-inserted with `time()` stamps)
will otherwise linger up to the 5-day retention window and keep drawing the flat
tail. Old rows are **not** hour-aligned; correctly-stored rows always are, so:

```sql
DELETE FROM measurements WHERE sensor_id LIKE 'CHMI_%' AND (measured_at % 3600) != 0;
```

Run once against `www/historygraph/air_quality_history.db` after deploying. If you
have no SQLite access on the host, ask and I'll add a token-guarded one-shot
`cleanup_chmi.php`.

> Note: until ČHMÚ resumes publishing, the graph will correctly show CHMÚ frozen
> at the 29.6 07:00Z hour with the "neaktualizuje data" notice — that is the
> intended behavior, not a remaining bug.
