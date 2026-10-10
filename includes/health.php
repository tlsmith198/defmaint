<?php
/**
 * Environment checks shared by the installer and Admin > Settings > System Check.
 *
 * This file must stay dependency-free enough to run BEFORE config.php exists,
 * so nothing here may assume a database connection unless it is guarded.
 *
 * Each check returns:
 *   ['label' => string, 'status' => 'ok'|'warn'|'fail', 'detail' => string, 'fix' => string]
 */

if (!defined('HEALTH_APP_ROOT')) {
    define('HEALTH_APP_ROOT', defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__));
}

const HEALTH_REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'fileinfo'];
const HEALTH_OPTIONAL_EXTENSIONS = ['gd', 'openssl', 'zip', 'curl'];
const HEALTH_UPLOAD_DIRS = ['assets', 'documents', 'completions', 'issues', 'branding'];

function health_check(string $label, string $status, string $detail = '', string $fix = ''): array
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
}

/** Create the uploads tree and drop the protective .htaccess in place. */
function health_prepare_uploads(): array
{
    $base    = defined('UPLOAD_PATH') ? UPLOAD_PATH : HEALTH_APP_ROOT . '/uploads';
    $created = [];
    $failed  = [];

    foreach (array_merge([''], HEALTH_UPLOAD_DIRS) as $sub) {
        $dir = rtrim($base, '/\\') . ($sub === '' ? '' : '/' . $sub);
        if (is_dir($dir)) {
            continue;
        }
        if (@mkdir($dir, 0755, true) || is_dir($dir)) {
            $created[] = $sub === '' ? 'uploads' : 'uploads/' . $sub;
        } else {
            $failed[] = $sub === '' ? 'uploads' : 'uploads/' . $sub;
        }
    }

    $ht = rtrim($base, '/\\') . '/.htaccess';
    if (!file_exists($ht) && is_writable(dirname($ht))) {
        @file_put_contents($ht, "Options -Indexes -ExecCGI\n"
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
            . "<FilesMatch \"\\.(php|php3|php4|php5|php7|phtml|phar|pl|py|cgi|sh)$\">\n"
            . "  Require all denied\n</FilesMatch>\n"
            . "RemoveHandler .php .php3 .php4 .php5 .php7 .phtml .phar .cgi .pl .py\n"
            . "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar .pl .py .cgi .sh\n");
        $created[] = 'uploads/.htaccess';
    }

    return ['created' => $created, 'failed' => $failed];
}

/** Actually write and read back a file, rather than trusting is_writable(). */
function health_write_test(string $dir): array
{
    if (!is_dir($dir)) {
        return [false, 'folder does not exist'];
    }
    $probe = rtrim($dir, '/\\') . '/.write_test_' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'ok') === false) {
        return [false, 'not writable by the web server'];
    }
    $ok = @file_get_contents($probe) === 'ok';
    @unlink($probe);
    return [$ok, $ok ? 'writable' : 'written but not readable back'];
}

/** Checks that need no database. */
function health_environment_checks(): array
{
    $out = [];

    // --- PHP version --------------------------------------------------
    if (PHP_VERSION_ID >= 80000) {
        $out[] = health_check('PHP version', 'ok', PHP_VERSION);
    } elseif (PHP_VERSION_ID >= 70400) {
        $out[] = health_check('PHP version', 'warn', PHP_VERSION . ' — supported, but 8.1+ is recommended',
            'cPanel → Software → Select PHP Version → choose 8.1 or newer.');
    } else {
        $out[] = health_check('PHP version', 'fail', PHP_VERSION . ' — too old',
            'cPanel → Software → Select PHP Version → choose 8.1 or newer, then reload this page.');
    }

    // --- extensions ---------------------------------------------------
    foreach (HEALTH_REQUIRED_EXTENSIONS as $ext) {
        $out[] = extension_loaded($ext)
            ? health_check('Extension: ' . $ext, 'ok', 'loaded')
            : health_check('Extension: ' . $ext, 'fail', 'missing',
                'cPanel → Select PHP Version → Extensions → tick "' . $ext . '".');
    }
    foreach (HEALTH_OPTIONAL_EXTENSIONS as $ext) {
        $note = ['gd' => 'image handling for logos and photos',
                 'openssl' => 'SMTP over TLS',
                 'zip' => 'optional - Admin → Updates has a built-in fallback',
                 'curl' => 'not required'][$ext] ?? '';
        $out[] = extension_loaded($ext)
            ? health_check('Extension: ' . $ext, 'ok', 'loaded')
            : health_check('Extension: ' . $ext, 'warn', 'missing — ' . $note,
                'cPanel → Select PHP Version → Extensions → tick "' . $ext . '".');
    }

    // --- writable folders ---------------------------------------------
    $uploadBase = defined('UPLOAD_PATH') ? UPLOAD_PATH : HEALTH_APP_ROOT . '/uploads';
    [$ok, $detail] = health_write_test($uploadBase);
    $out[] = health_check('Uploads folder writable', $ok ? 'ok' : 'fail', $detail,
        'In cPanel → File Manager, right-click /uploads → Change Permissions → 755 '
        . '(or 775 if 755 is not enough on this account).');

    $missing = [];
    foreach (HEALTH_UPLOAD_DIRS as $sub) {
        if (!is_dir($uploadBase . '/' . $sub)) {
            $missing[] = $sub;
        }
    }
    $out[] = $missing
        ? health_check('Upload subfolders', 'warn', 'missing: ' . implode(', ', $missing),
            'The installer creates these automatically — click Re-check.')
        : health_check('Upload subfolders', 'ok', count(HEALTH_UPLOAD_DIRS) . ' present');

    // --- scratch folder (mPDF needs this from phase 7 onward) -----------
    $tmpDir = HEALTH_APP_ROOT . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    [$tmpOk, $tmpDetail] = health_write_test($tmpDir);
    $out[] = health_check('Scratch folder (/tmp) writable', $tmpOk ? 'ok' : 'warn', $tmpDetail,
        $tmpOk ? '' : 'PDF reports need this from build phase 7. File Manager → /tmp → permissions 755.');

    // --- config file ---------------------------------------------------
    $configFile = HEALTH_APP_ROOT . '/config/config.php';
    if (file_exists($configFile)) {
        $out[] = health_check('Configuration file', 'ok', 'config/config.php present');
    } else {
        [$cw] = health_write_test(HEALTH_APP_ROOT . '/config');
        $out[] = health_check('Configuration file', $cw ? 'warn' : 'warn',
            $cw ? 'not created yet — the installer can write it' : 'not created yet — /config is not writable',
            $cw ? '' : 'The installer will show you the file to upload by hand instead.');
    }

    // --- bundled libraries (email + PDF) ---------------------------------
    $vend = is_file(HEALTH_APP_ROOT . '/vendor/autoload.php');
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $out[] = health_check('Email & PDF libraries', $vend ? ($phpOk ? 'ok' : 'warn') : 'warn',
        $vend ? 'vendor/ present (PHPMailer, mPDF)' . ($phpOk ? '' : ' — but PHP ' . PHP_VERSION . ' is too old for PDF')
              : 'vendor/ folder not found',
        $vend ? 'cPanel → Select PHP Version → choose 8.1 or newer.'
              : 'Upload the vendor folder from the full release package. Email and PDF reports need it.');
    $mpTmp = HEALTH_APP_ROOT . '/tmp/mpdf';
    if (!is_dir($mpTmp)) {
        @mkdir($mpTmp, 0755, true);
    }
    [$mpOk, $mpDetail] = health_write_test($mpTmp);
    $out[] = health_check('PDF scratch folder', $mpOk ? 'ok' : 'warn', $mpOk ? 'tmp/mpdf writable' : $mpDetail,
        $mpOk ? '' : 'File Manager → tmp → permissions 755.');

    // --- upload limits --------------------------------------------------
    $postMax = health_bytes(ini_get('post_max_size'));
    $upMax   = health_bytes(ini_get('upload_max_filesize'));
    $small   = min($postMax, $upMax) < 8 * 1024 * 1024;
    $out[] = health_check('Upload size limits', $small ? 'warn' : 'ok',
        'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'),
        $small ? 'cPanel → Select PHP Version → Options → raise upload_max_filesize and post_max_size to 16M.' : '');

    // --- sessions -------------------------------------------------------
    $sessionOk = session_status() === PHP_SESSION_ACTIVE || @session_start();
    $out[] = health_check('PHP sessions', $sessionOk ? 'ok' : 'fail',
        $sessionOk ? 'working' : 'could not start a session',
        $sessionOk ? '' : 'Check that the session save path is writable, or contact hosting support.');

    // --- HTTPS ----------------------------------------------------------
    if (PHP_SAPI !== 'cli') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $out[] = health_check('HTTPS', $https ? 'ok' : 'warn',
            $https ? 'secure connection' : 'this page is being served over plain HTTP',
            $https ? '' : 'Install the free AutoSSL certificate in cPanel → SSL/TLS Status, then force HTTPS.');
    }

    return $out;
}

/** Checks that need a working database connection. */
function health_database_checks(): array
{
    $out = [];
    if (!defined('DB_NAME')) {
        return [health_check('Database', 'fail', 'not configured yet')];
    }
    try {
        $pdo = db();
        $out[] = health_check('Database connection', 'ok', DB_NAME . ' @ ' . DB_HOST);
    } catch (Throwable $e) {
        return [health_check('Database connection', 'fail', health_db_error($e->getMessage()),
            'Re-check the database name, user and password in cPanel → MySQL Databases.')];
    }

    $tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetchColumn();
    $out[] = $tables >= 21
        ? health_check('Schema', 'ok', $tables . ' tables')
        : health_check('Schema', $tables === 0 ? 'fail' : 'warn', $tables . ' tables (21 expected)',
            'Run install.php to create the schema, or import sql/schema.sql through phpMyAdmin.');

    $assets = (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    $reqs   = (int) $pdo->query('SELECT COUNT(*) FROM maintenance_requirements')->fetchColumn();
    $out[]  = health_check('Asset register', $assets ? 'ok' : 'warn',
        $assets . ' assets, ' . $reqs . ' maintenance requirements',
        $assets ? '' : 'Import sql/seed_admi_machines.sql to load the 15-machine fleet.');

    $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    $out[]  = health_check('Administrator accounts', $admins ? 'ok' : 'fail', $admins . ' active',
        $admins ? '' : 'At least one active administrator is required.');

    // --- cron -----------------------------------------------------------
    $last = $pdo->query('SELECT started_at, status FROM job_runs ORDER BY started_at DESC LIMIT 1')->fetch();
    if (!$last) {
        $out[] = health_check('Scheduled job (cron)', 'warn', 'has never run',
            'Add the cron command shown under Settings → Scheduling to cPanel → Cron Jobs.');
    } else {
        $ageHours = (time() - strtotime($last['started_at'])) / 3600;
        $out[] = health_check('Scheduled job (cron)',
            $ageHours < 36 && $last['status'] !== 'error' ? 'ok' : 'warn',
            'last run ' . fmt_datetime($last['started_at']) . ' (' . $last['status'] . ')',
            $ageHours >= 36 ? 'The nightly cron has not run in over a day — check cPanel → Cron Jobs.' : '');
    }

    // --- email ----------------------------------------------------------
    $host = (string) setting('smtp_host', '');
    $on   = setting_bool('email_enabled');
    if (!$host) {
        $out[] = health_check('Email / SMTP', 'warn', 'no SMTP host configured',
            'Settings → Email, then use "Save & send test email".');
    } else {
        $out[] = health_check('Email / SMTP', $on ? 'ok' : 'warn',
            $host . ':' . setting('smtp_port', '587') . ($on ? ' — enabled' : ' — configured but switched off'));
    }

    // --- go-live date -----------------------------------------------------
    $goLive = due_schedule_start();
    if ($goLive === null) {
        $overdue = (int) db_value("SELECT COUNT(*) FROM maintenance_due_instances
                                    WHERE status = 'scheduled' AND due_date < CURDATE()", [], 0);
        $out[] = health_check('Go-live date', $overdue > 50 ? 'warn' : 'ok',
            $goLive === null ? 'not set — the schedule runs from the install date' : '',
            $overdue > 50
                ? $overdue . ' items are already overdue. If the floor has not started using the '
                  . 'system yet, set a go-live date in Settings → Scheduling to reset the dates.'
                : '');
    } else {
        $out[] = health_check('Go-live date',
            $goLive > date('Y-m-d') ? 'warn' : 'ok',
            fmt_date($goLive) . ($goLive > date('Y-m-d') ? ' — nothing is due until then' : ' — schedule is running'));
    }

    // --- record locking ---------------------------------------------------
    $out[] = setup_mode()
        ? health_check('Record locking', 'warn',
            'Setup mode is ON — tasks and sign-off records with history can be deleted',
            'Switch Setup mode off in Settings → General once the schedule is final. '
            . 'From then on history can only be retired, never deleted.')
        : health_check('Record locking', 'ok', 'Setup mode is off — sign-off history is locked');

    // --- installer left on the server -------------------------------------
    if (file_exists(HEALTH_APP_ROOT . '/install.php')) {
        $out[] = health_check('Installer removed', 'fail', 'install.php is still on the server',
            'Delete install.php in cPanel → File Manager. It is locked, but it should not stay there.');
    } else {
        $out[] = health_check('Installer removed', 'ok', 'install.php deleted');
    }

    return $out;
}

/** Turn a raw PDO/MySQL error into something a non-DBA can act on. */
function health_db_error(string $raw): string
{
    if (stripos($raw, 'Access denied') !== false) {
        return 'The database username or password was rejected. In cPanel → MySQL Databases, '
             . 'confirm the user is added to the database with ALL PRIVILEGES.';
    }
    if (stripos($raw, 'Unknown database') !== false) {
        return 'That database does not exist. Remember cPanel prefixes the name with your '
             . 'account, e.g. "cpuser_admimaint".';
    }
    if (stripos($raw, 'Connection refused') !== false || stripos($raw, 'getaddrinfo') !== false
        || stripos($raw, 'Unknown MySQL server host') !== false) {
        return 'Could not reach the database server. On GoDaddy shared hosting the host is '
             . 'almost always "localhost".';
    }
    if (stripos($raw, 'timed out') !== false) {
        return 'The database server did not respond in time. Try again, or check with hosting support.';
    }
    return $raw;
}

function health_bytes(string $val): int
{
    $val  = trim($val);
    if ($val === '') {
        return 0;
    }
    $unit = strtolower($val[strlen($val) - 1]);
    $num  = (int) $val;
    switch ($unit) {
        case 'g': $num *= 1024; // fall through
        case 'm': $num *= 1024; // fall through
        case 'k': $num *= 1024;
    }
    return $num;
}

/** Roll a list of checks up into ok / warn / fail. */
function health_worst(array $checks): string
{
    $worst = 'ok';
    foreach ($checks as $c) {
        if ($c['status'] === 'fail') {
            return 'fail';
        }
        if ($c['status'] === 'warn') {
            $worst = 'warn';
        }
    }
    return $worst;
}
