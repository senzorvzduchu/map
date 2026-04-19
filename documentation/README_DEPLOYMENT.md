# Air Quality Monitoring App - Deployment Guide

## 📦 What Was Fixed

All **5 high-priority security issues** have been resolved:

1. ✅ **Security token exposure** - Moved to config file
2. ✅ **Input validation** - Added strict format checking
3. ✅ **CORS restriction** - Limited to your domain
4. ✅ **User-Agent typo** - Fixed and moved to config
5. ✅ **Database index** - Added for query optimization

## 📋 Files You Have

### Documentation
- `SECURITY_FIXES.md` - Detailed explanation of all fixes
- `DEPLOYMENT_CHECKLIST.md` - **START HERE** for deployment
- `TEST_GUIDE.md` - Testing strategies (requires PHP locally)
- `README_DEPLOYMENT.md` - This file

### Code Files Ready to Deploy
- `www/config.example.php` - Template (upload this)
- `www/config.php` - Your local config (DO NOT upload - create fresh on server)
- `www/sc-proxy.php` - Fixed
- `www/chmi-proxy.php` - Fixed
- `www/historygraph/collect.php` - Fixed
- `www/historygraph/get_history.php` - Fixed
- `.gitignore` - Protects secrets

## 🚀 Quick Start Deployment

Since you don't have PHP installed locally, **go straight to server deployment**:

### 1. Backup Your Server First!
```bash
ssh your-server
cd /var/www
tar -czf backup-$(date +%Y%m%d).tar.gz senzorvzduchu.cz/
```

### 2. Upload These Files to Server:
- `www/sc-proxy.php`
- `www/chmi-proxy.php`
- `www/historygraph/collect.php`
- `www/historygraph/get_history.php`
- `www/config.example.php`

### 3. Create Config on Server:
```bash
ssh your-server
cd /path/to/senzorvzduchu.cz/www
cp config.example.php config.php
nano config.php  # edit and add your token
```

### 4. Generate Token:
```bash
openssl rand -base64 32
```
Copy this into `config.php` as your `CRON_TOKEN`

### 5. Update Cron Job:
```bash
crontab -e
# Update with new token URL
```

### 6. Test:
```bash
curl "https://senzorvzduchu.cz/historygraph/collect.php?token=YOUR_NEW_TOKEN"
```

## 📖 Detailed Instructions

**Follow `DEPLOYMENT_CHECKLIST.md`** - it has:
- Step-by-step deployment process
- Testing procedures
- Rollback instructions if needed
- Troubleshooting guide
- Expected timeline (~25 minutes)

## ⚠️ Critical Requirements

Before the app will work, you **MUST**:

1. Create `www/config.php` on the server (from config.example.php)
2. Set a new `CRON_TOKEN` (not the default)
3. Update your cron job with the new token
4. Set file permissions: `chmod 640 www/config.php`

The old hardcoded token `cGFvnIxjJG9xsok49ZSp` will **not work anymore**.

## 🔐 Security Improvements

**Before:**
- Token visible in code
- Email address exposed
- No input validation
- CORS wide open
- Inefficient database queries

**After:**
- Token in secure config file (not in Git)
- Generic User-Agent
- Strict input validation with regex
- CORS restricted to your domain
- Optimized database indexes

## 🆘 If Something Goes Wrong

**Rollback instantly:**
```bash
cd /var/www
rm -rf senzorvzduchu.cz/
tar -xzf backup-YYYYMMDD.tar.gz
sudo systemctl reload apache2
```

See `DEPLOYMENT_CHECKLIST.md` section "Rollback Procedure" for details.

## ✅ Testing Without Local PHP

Since PHP isn't on your Mac, you'll test directly on the server:

1. Upload files
2. Create config
3. Test with `curl` commands
4. Check in browser
5. Monitor logs

See `DEPLOYMENT_CHECKLIST.md` Step 4-7 for all test procedures.

## 📊 Success Criteria

After deployment, everything should work exactly as before, but more securely:

- ✅ Map loads and displays sensors
- ✅ Clicking markers shows data
- ✅ Historical charts appear (after data collection)
- ✅ No browser console errors
- ✅ Cron job collects data every 5 minutes
- ✅ Invalid requests properly rejected

## 🎯 What to Do Now

1. **Read `DEPLOYMENT_CHECKLIST.md`** completely
2. **Schedule deployment time** (low-traffic period recommended)
3. **Have rollback ready** (backup created)
4. **Follow checklist step-by-step**
5. **Monitor for 24 hours** after deployment

## 💡 Pro Tips

- Deploy during low-traffic hours
- Keep SSH session open during deployment
- Test each step before proceeding
- Don't delete backup for at least 1 week
- Monitor error logs actively for first day

## 📞 Files Reference

| File | Purpose |
|------|---------|
| `DEPLOYMENT_CHECKLIST.md` | **Main deployment guide** - follow this |
| `SECURITY_FIXES.md` | What was fixed and why |
| `TEST_GUIDE.md` | Testing options (needs local PHP) |
| `config.example.php` | Template for production config |

## ✅ Deployment Status — COMPLETE (2026-03-22)

- [x] Code fixes completed
- [x] Documentation created
- [x] Files verified
- [x] Files uploaded to server via FTP
- [x] Config created on server (`config.php` with new hex token)
- [x] Cron job updated with new token
- [x] All tests passed (token guard, input validation, CORS, map, charts)
- [x] DB write monitor active (`monitor.php` — ntfy.sh alerts every 15 min)
- [x] Quality check false positives fixed (CHMI excluded, stuck requires mean > 10)
