<?php
/**
 * Single entry point for every page: config, session, DB, helpers.
 * Every script starts with:  require_once __DIR__ . '/path/to/includes/bootstrap.php';
 */

define('APP_ROOT', dirname(__DIR__));

// Bumped with every released package. Shown in the footer and on the
// System Check page so you can confirm what is actually deployed.
define('APP_BUILD', '2026.10.09 · all phases: email, AS9100 reports, Run Checks, in-app updater');

// ---- configuration ---------------------------------------------------
if (!file_exists(APP_ROOT . '/config/config.php')) {
    // The installer runs without a config file on the very first request.
    if (!defined('INSTALLER') && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        $installerPresent = file_exists(APP_ROOT . '/install.php');
        http_response_code(503);
        echo '<!DOCTYPE html><meta charset="utf-8">'
           . '<title>Setup required</title>'
           . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:38rem;margin:12vh auto;padding:0 1.5rem;'
           . 'color:#12263f}a.btn{display:inline-block;background:#0d6efd;color:#fff;padding:.6rem 1.1rem;'
           . 'border-radius:.375rem;text-decoration:none;margin-top:1rem}code{background:#f1f3f5;padding:.1rem .3rem;'
           . 'border-radius:3px}</style>'
           . '<h1>Setup required</h1>'
           . '<p>The ADMI Maintenance System has not been set up on this server yet.</p>';
        echo $installerPresent
            ? '<p>The installer will walk you through it — it takes about five minutes '
              . 'and you only need your database name, user and password from cPanel.</p>'
              . '<a class="btn" href="install.php">Start the installer</a>'
            : '<p>Copy <code>config/config.sample.php</code> to <code>config/config.php</code>, '
              . 'fill in the database settings, and import <code>sql/schema.sql</code>.</p>';
        exit;
    }
} else {
    require_once APP_ROOT . '/config/config.php';
}

foreach ([['APP_ENV', 'production'], ['APP_NAME', 'ADMI Maintenance'], ['APP_URL', ''],
          ['UPLOAD_PATH', APP_ROOT . '/uploads'], ['CRON_TOKEN', '']] as $d) {
    if (!defined($d[0])) {
        define($d[0], $d[1]);
    }
}

// ---- error handling --------------------------------------------------
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');

// ---- base URL --------------------------------------------------------
if (!defined('BASE_URL')) {
    if (APP_URL !== '') {
        define('BASE_URL', rtrim(APP_URL, '/'));
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
        $appPath = str_replace('\\', '/', APP_ROOT);
        $sub = ($docRoot && str_starts_with($appPath, $docRoot)) ? substr($appPath, strlen($docRoot)) : '';
        define('BASE_URL', $scheme . '://' . $host . rtrim($sub, '/'));
    }
}

// ---- PHP 7.4 compatibility shims -------------------------------------
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// ---- includes --------------------------------------------------------
require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/settings.php';
require_once APP_ROOT . '/includes/upload.php';
require_once APP_ROOT . '/includes/assets_lib.php';
require_once APP_ROOT . '/includes/due_engine.php';
require_once APP_ROOT . '/includes/issues_lib.php';
require_once APP_ROOT . '/includes/purge.php';
require_once APP_ROOT . '/includes/health.php';
require_once APP_ROOT . '/includes/notify.php';
// includes/updater.php is loaded on demand by modules/settings/update.php

// ---- session ---------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('ADMIMAINT');
    session_start();
}

// ---- timezone (needs the DB, so it comes last) -----------------------
if (defined('DB_NAME')) {
    try {
        date_default_timezone_set(setting('timezone', 'America/New_York'));
    } catch (Throwable $e) {
        date_default_timezone_set('America/New_York');
    }
}
