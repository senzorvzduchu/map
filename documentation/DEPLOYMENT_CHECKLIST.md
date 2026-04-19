# Safe Deployment Checklist

## ✅ Deployment Status: COMPLETE (2026-03-22)

All steps completed and verified. Map live at https://senzorvzduchu.cz/map/

**Iteration 9 update — Database write monitor (2026-03-22):**
- [x] `www/historygraph/monitor.php` created and uploaded — checks DB freshness, alerts via ntfy.sh
- [x] ntfy.sh notifications tested and working (topic: `senzorvzduchu-monitor`)
- [x] CRON job set up: `*/15 * * * * curl -s "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"`

**Iteration 8 update — Quality check false positive fix (2026-03-22):**
- [x] `www/historygraph/get_history.php` updated — `stuck` now requires `mean > 10` (prevents false warnings in clean air)
- [x] CHMI sensors skip quality check entirely (reference-grade, always trusted)
- [x] Validated: 75% of sensors (91/121) reading < 10 μg/m³ correctly stop showing false warnings

**Iteration 7 update — Automatic faulty SC sensor filtering:**
- [x] `www/historygraph/collect.php` updated (Layer 1: absolute ceiling — rejects PM2.5 ≤ 0 or > 500 μg/m³ before DB insert)
- [x] `www/map/index.html` updated (Layer 2: haversine + neighbor-ratio outlier filter; Layer 3: quality warning banner in popup; longitude null-check)
- [x] `www/map/embed.html` updated (same as index.html)
- [x] `www/historygraph/get_history.php` updated (Layer 3: plateau/stuck detection — returns `quality` object with mean/std dev of last 2h)
- [x] SC_77008 confirmed caught and suppressed by Layer 2 (90–107 μg/m³ vs neighbor median 7.1 μg/m³)

**How the filter works:**
- Layer 1 (back-end): values > 500 μg/m³ never written to DB
- Layer 2 (front-end, display-time): sensor suppressed from map if PM2.5 > max(60, median_neighbors × 5), requires ≥ 2 neighbors within 30 km, co-located chips (< 0.1 km) excluded from neighbor set; flagged sensors logged to browser console as `[SC Outlier]`
- Layer 3 (chart popup): amber warning shown if last 2h std dev < 3 AND mean > 60 (plateau_high) or std dev < 0.5 AND mean > 10 (stuck). CHMI sensors excluded.

**Iteration 6 update — SC sensor 7213 exclusion:**
- [x] `www/map/index.html` updated (sensor 7213 added to hardcoded exclusion)
- [x] `www/map/embed.html` updated (same as index.html)
- [x] `www/historygraph/collect.php` updated (sensor 7213 excluded from DB collection)

**Iteration 5 update — SC popup temperature & humidity:**
- [x] `www/map/index.html` updated (two-pass SC parsing — adds temp/humidity to SC popups)
- [x] `www/map/embed.html` updated (same as index.html)

**Iteration 4 update — Smart Citizen integration:**
- [x] `www/sck-proxy.php` created and uploaded
- [x] `www/map/index.html` updated (fetchSmartCitizenData, legend sources)
- [x] `www/map/embed.html` updated (same as index.html)
- [x] `www/historygraph/collect.php` updated (SCK collection section)
- [x] `www/historygraph/get_history.php` updated (regex extended for SCK_)
- [x] Old empty `sck_data_cache.json` deleted from server
- [x] 6 active CZ Smart Citizen sensors confirmed on map

---

## ✅ Pre-Deployment Verification (Completed)

- [x] All PHP files updated with security fixes
- [x] All files include `require_once config.php`
- [x] Config example file created
- [x] .gitignore created
- [x] Files structure verified

**Files Modified (Iteration 2 — security):**
- ✅ `www/sc-proxy.php` - CORS fixed, User-Agent fixed, uses config
- ✅ `www/chmi-proxy.php` - CORS fixed, uses config
- ✅ `www/historygraph/collect.php` - Token from config, database index added
- ✅ `www/historygraph/get_history.php` - Input validation added, uses config

**Files Created (Iteration 2 — security):**
- ✅ `www/config.php` - Main configuration (needs token update)
- ✅ `www/config.example.php` - Template for deployment
- ✅ `.gitignore` - Protects secrets

**Files Modified (Iteration 6 — SC sensor exclusion):**
- ✅ `www/map/index.html` - Added sensor 7213 to hardcoded exclusion
- ✅ `www/map/embed.html` - Same as index.html
- ✅ `www/historygraph/collect.php` - Added sensor 7213 to exclusion

**Files Modified (Iteration 5 — SC temp/humidity):**
- ✅ `www/map/index.html` - Two-pass fetchSensorCommunityData() adds temperature & humidity to SC popups
- ✅ `www/map/embed.html` - Same as index.html

**Files Modified (Iteration 4 — Smart Citizen):**
- ✅ `www/map/index.html` - Added fetchSmartCitizenData(), legend sources, SCK popup label
- ✅ `www/map/embed.html` - Same as index.html
- ✅ `www/historygraph/collect.php` - Added SCK data collection section
- ✅ `www/historygraph/get_history.php` - Regex extended to accept SCK_\d+

**Files Created (Iteration 4 — Smart Citizen):**
- ✅ `www/sck-proxy.php` - Smart Citizen caching proxy with bbox filter, recency filter, name-based PM2.5 matching

---

## 🚀 Deployment Steps

> **Hosting note:** Shared hosting, FTP only — no SSH access. All server-side actions use the hosting control panel.

### Step 1: Backup Production (CRITICAL)

In your FTP client, download the current live versions of any files you are about to overwrite and save them locally (e.g. `index.html.bak`). This is your rollback if anything goes wrong.

---

### Step 2: Upload Files via FTP

Connect with your FTP client and upload the changed files to the server web root:

| Local file | Server path |
|---|---|
| `www/sc-proxy.php` | `sc-proxy.php` |
| `www/chmi-proxy.php` | `chmi-proxy.php` |
| `www/historygraph/collect.php` | `historygraph/collect.php` |
| `www/historygraph/get_history.php` | `historygraph/get_history.php` |
| `www/map/index.html` | `map/index.html` |
| `www/map/embed.html` | `map/embed.html` |

Confirm overwrite when prompted. **Do not upload `www/config.php`** — it lives only on the server.

For new deployments: upload `www/config.example.php`, then rename it to `config.php` in the hosting file manager and edit the constants directly there.

---

### Step 3: Config (new deployments only)

In the hosting file manager, open `config.php` and set:
```php
define('CRON_TOKEN', 'YOUR_64_CHAR_HEX_TOKEN');
define('ALLOWED_DOMAIN', 'https://senzorvzduchu.cz');
define('USER_AGENT', 'AirQualityMonitor/1.0 (senzorvzduchu.cz)');
```

Use a 64-character hex string as the token (generate one at random, e.g. via the hosting panel's terminal if available, or any secure random generator).

---

### Step 4: Test Collector Endpoint

Open in browser (or use curl if available):
```
https://senzorvzduchu.cz/historygraph/collect.php
→ Expected: "Access Denied: Invalid Token"

https://senzorvzduchu.cz/historygraph/collect.php?token=YOUR_TOKEN
→ Expected: "Status: OK. Inserted X measurements..."
```

If you see a PHP error, check that `config.php` exists in the correct location (`www/`) via the hosting file manager.

---

### Step 5: Update Cron Job

In the hosting control panel, update the cron job URL to include your token:
```
*/5 * * * *  curl -s "https://senzorvzduchu.cz/historygraph/collect.php?token=YOUR_TOKEN"
```

---

### Step 6: Test Frontend

1. Open `https://senzorvzduchu.cz/map/` in a browser
2. Open DevTools (F12) → Console tab — no red errors expected
3. Check for `[SC Outlier]` warnings — these confirm the faulty sensor filter is active
4. Click a marker → popup opens → chart loads

---

### Step 7: Monitor

Check the map works correctly over the next few data refresh cycles (every 5 min). Verify:
- Markers appear and update
- No unexpected sensors disappearing (only faulty outliers should be suppressed)
- Historical charts load when clicking markers

---

## 🆘 Rollback Procedure (If Anything Goes Wrong)

Re-upload the `.bak` files you saved in Step 1 via FTP, overwriting the new versions. The site will be back to the previous state immediately — no server restart needed on shared hosting.

---

## ✅ Success Indicators — All Verified (2026-03-21)

- [x] Map loads without errors
- [x] Markers appear on map
- [x] Clicking markers shows popups with data
- [x] No CORS errors in browser console
- [x] Cron job runs every 5 minutes successfully
- [x] Database file grows over time
- [x] Invalid requests return proper error codes
- [x] Token guard working (no token = Access Denied)
- [x] Input validation working (invalid ID = HTTP 400)
- [x] CORS header confirmed: `Access-Control-Allow-Origin: https://senzorvzduchu.cz`

---

## 🔍 Troubleshooting Guide

### Issue: "Fatal error: require_once(): Failed opening required config.php"

**Solution:** In the hosting file manager, check that `config.php` exists in the `www/` root. If missing, copy `config.example.php`, rename it to `config.php`, and fill in the constants.

---

### Issue: "Access Denied: Invalid Token"

**Solution:** Check that `CRON_TOKEN` in `config.php` on the server exactly matches the token in the cron job URL. No extra spaces or quotes.

---

### Issue: CORS errors in browser

**Solution:** Open `config.php` in the hosting file manager and verify:
```php
define('ALLOWED_DOMAIN', 'https://senzorvzduchu.cz');
```

---

### Issue: Database not growing

**Solution:**
1. Open `https://senzorvzduchu.cz/historygraph/collect.php?token=YOUR_TOKEN` in browser — should say "Status: OK. Inserted X measurements..."
2. If it errors, check `config.php` exists and token matches
3. Verify the cron job is set to run every 5 minutes in the hosting control panel

---

### Issue: Historical charts don't load

**Solution:** Open in browser:
```
https://senzorvzduchu.cz/historygraph/get_history.php?id=SC_12345
```
Should return JSON. If it returns an error, check `config.php` is present and readable by the web server.

---

### Issue: Healthy sensor disappeared from map

**Solution:** The neighbor-ratio filter (Layer 2) may have incorrectly flagged it. Open browser DevTools → Console and look for `[SC Outlier]` entries. Note the sensor ID and its reported neighbor median. If it's a false positive, the threshold may need adjusting (`× 5 multiplier` or `60 μg/m³ floor` in `fetchSensorCommunityData()` in `index.html` and `embed.html`).

---

## 📝 Post-Deployment Tasks

Within 1 week:

1. **Change old exposed token** (if it was in Git history)
   - The old token `cGFvnIxjJG9xsok49ZSp` may be in your Git history
   - Consider it compromised
   - Your new token should be different

2. **Verify data collection**
   - Check database has been collecting for several days
   - Verify charts show historical data

3. **Update documentation**
   - Document your new CRON_TOKEN location (secure place, not in code)
   - Note the deployment date

4. **Remove old backup** (after 1 week of stable operation)
   ```bash
   rm senzorvzduchu-backup-*.tar.gz  # Keep at least one recent backup
   ```

---

## 🎯 Expected Timeline

- **Backup:** 2 minutes
- **Upload files:** 5 minutes
- **Create config:** 3 minutes
- **Update cron:** 2 minutes
- **Testing:** 10 minutes
- **Total:** ~25 minutes

---

## ⚠️ Important Notes

1. **Never commit `config.php` to Git** - it's in .gitignore
2. **Keep your CRON_TOKEN secret** - treat it like a password
3. **Test the backup** before deleting it
4. **Monitor logs** for at least 24 hours after deployment
5. **Keep the rollback steps handy** during deployment

---

## 📞 Support

If you encounter issues:

1. Check the Troubleshooting Guide above
2. Review PHP error logs
3. Check that config.php exists and has correct values
4. Verify file permissions (config.php should be 640)
5. Test each endpoint individually with curl

The changes are backward-compatible except for requiring `config.php` to exist.

---

Good luck with deployment! 🚀
