<?php
/**
 * ADMI Maintenance System - configuration
 *
 * Copy this file to config.php and fill in the values from cPanel.
 * config.php is intentionally NOT tracked in source control.
 */

// ---- Database (cPanel > MySQL Databases) ----------------------------
define('DB_HOST', 'localhost');          // GoDaddy shared hosting: almost always localhost
define('DB_NAME', 'admi_maint');         // cPanel prefixes this, e.g. "cpuser_admi_maint"
define('DB_USER', 'admi_maint_user');
define('DB_PASS', 'CHANGE_ME');
define('DB_PORT', 3306);

// ---- Application ----------------------------------------------------
// Public URL of the app root, no trailing slash. Leave '' to auto-detect.
define('APP_URL', '');
define('APP_NAME', 'ADMI Maintenance');

// Absolute filesystem path for uploads. Default is /uploads inside the app.
// On GoDaddy shared hosting this must be writable (755 dirs / 644 files).
define('UPLOAD_PATH', __DIR__ . '/../uploads');

// Shared secret appended to cron URLs (cron/run.php?token=...). Generate a
// long random string; the same value is stored in Settings > Scheduling.
define('CRON_TOKEN', 'CHANGE_ME_LONG_RANDOM_STRING');

// ---- Environment ----------------------------------------------------
// 'production' hides error detail from users; 'development' shows it.
define('APP_ENV', 'production');
