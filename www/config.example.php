<?php
// ==========================================
// CONFIGURATION TEMPLATE — senzorvzduchu.cz
// ==========================================
// Copy this file to config.php and fill in real values.
// Generate a secure token: openssl rand -base64 32
// ==========================================

define('CRON_TOKEN', 'REPLACE_WITH_HEX_TOKEN_FROM_openssl_rand_or_powershell_equivalent');
define('ALLOWED_DOMAIN', 'https://senzorvzduchu.cz');
define('USER_AGENT', 'AirQualityMonitor/1.0 (senzorvzduchu.cz)');

// PurpleAir Read API key (get yours at https://develop.purpleair.com)
define('PURPLEAIR_API_KEY', 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX');

// CRON jobs:
// */5  * * * * curl -s "https://senzorvzduchu.cz/historygraph/collect.php?token=TOKEN"
// */15 * * * * curl -s "https://senzorvzduchu.cz/historygraph/monitor.php?token=TOKEN"
