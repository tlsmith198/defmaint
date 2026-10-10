<?php
/**
 * ADMI Maintenance System — guided installer.
 *
 * Six steps: welcome → requirements → database → schema → administrator → finish.
 * Designed for GoDaddy shared cPanel hosting, where /config may not be writable and
 * the person running it is not necessarily a developer. Every step is resumable and
 * every failure offers a manual path forward instead of a dead end.
 *
 * The installer locks itself once the system is installed. Delete this file afterwards.
 */
define('INSTALLER', true);

$appRoot    = __DIR__;
$configFile = $appRoot . '/config/config.php';
$sampleFile = $appRoot . '/config/config.sample.php';
$hasConfig  = file_exists($configFile);

if ($hasConfig) {
    require_once $appRoot . '/includes/bootstrap.php';
} else {
    require_once $appRoot . '/includes/health.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

/* ==================================================================== */
/* Helpers                                                              */
/* ==================================================================== */

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Split a SQL file into statements, respecting quoted strings and comments. */
function split_sql(string $sql): array
{
    $stmts   = [];
    $buf     = '';
    $len     = strlen($sql);
    $inS     = false;   // '...'
    $inD     = false;   // "..."
    $inLine  = false;   // -- or #
    $inBlock = false;   // /* */

    for ($i = 0; $i < $len; $i++) {
        $c  = $sql[$i];
        $c2 = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLine) {
            $buf .= $c;
            if ($c === "\n") $inLine = false;
            continue;
        }
        if ($inBlock) {
            $buf .= $c;
            if ($c === '*' && $c2 === '/') { $buf .= $c2; $i++; $inBlock = false; }
            continue;
        }
        if (!$inS && !$inD) {
            if ($c === '-' && $c2 === '-') { $inLine  = true; $buf .= $c; continue; }
            if ($c === '#')                { $inLine  = true; $buf .= $c; continue; }
            if ($c === '/' && $c2 === '*') { $inBlock = true; $buf .= $c; continue; }
        }
        if ($c === "'" && !$inD) {
            if ($inS && $c2 === "'")            { $buf .= "''"; $i++; continue; }
            if ($inS && $sql[$i - 1] === '\\')  { $buf .= $c; continue; }
            $inS = !$inS;
            $buf .= $c;
            continue;
        }
        if ($c === '"' && !$inS) { $inD = !$inD; $buf .= $c; continue; }

        if ($c === ';' && !$inS && !$inD) {
            $t = trim($buf);
            if ($t !== '') $stmts[] = $t;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $t = trim($buf);
    if ($t !== '') $stmts[] = $t;

    return array_values(array_filter($stmts, function ($s) {
        $stripped = preg_replace('~(--[^\n]*\n)|(#[^\n]*\n)|(/\*.*?\*/)~s', '', $s . "\n");
        return trim((string) $stripped) !== '';
    }));
}

function run_sql_file(PDO $pdo, string $path, array &$errors): int
{
    if (!is_readable($path)) {
        $errors[] = 'Cannot read ' . basename($path) . ' — re-upload the /sql folder.';
        return 0;
    }
    $n = 0;
    foreach (split_sql((string) file_get_contents($path)) as $stmt) {
        try {
            $pdo->exec($stmt);
            $n++;
        } catch (PDOException $ex) {
            $errors[] = basename($path) . ': ' . $ex->getMessage()
                      . ' — near: ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 110);
        }
    }
    return $n;
}

/** Build the contents of config/config.php from the sample plus the entered values. */
function build_config(array $db, string $cronToken): string
{
    global $sampleFile;
    $tpl = is_readable($sampleFile) ? (string) file_get_contents($sampleFile) : '';
    if ($tpl === '') {
        $tpl = "<?php\ndefine('DB_HOST', 'localhost');\ndefine('DB_NAME', '');\n"
             . "define('DB_USER', '');\ndefine('DB_PASS', '');\ndefine('DB_PORT', 3306);\n"
             . "define('APP_URL', '');\ndefine('APP_NAME', 'ADMI Maintenance');\n"
             . "define('UPLOAD_PATH', __DIR__ . '/../uploads');\ndefine('CRON_TOKEN', '');\n"
             . "define('APP_ENV', 'production');\n";
    }
    $map = [
        'DB_HOST'    => var_export($db['host'], true),
        'DB_NAME'    => var_export($db['name'], true),
        'DB_USER'    => var_export($db['user'], true),
        'DB_PASS'    => var_export($db['pass'], true),
        'DB_PORT'    => (string) (int) $db['port'],
        'CRON_TOKEN' => var_export($cronToken, true),
    ];
    foreach ($map as $const => $value) {
        $tpl = preg_replace("/define\('" . $const . "',.*?\);/", "define('$const', $value);", $tpl, 1);
    }
    return $tpl;
}

/** Try a connection; return [PDO|null, friendlyError|null]. */
function try_connect(array $db): array
{
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );
        return [$pdo, null];
    } catch (PDOException $ex) {
        return [null, health_db_error($ex->getMessage())];
    }
}

function table_count(PDO $pdo): int
{
    try {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

function admin_count(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

/** Guess the cPanel account name so we can show a realistic database-name hint. */
function cpanel_hint(): ?string
{
    if (preg_match('~/home\d*/([a-z0-9_]+)/~i', str_replace('\\', '/', __DIR__), $m)) {
        return $m[1];
    }
    return null;
}

/* ==================================================================== */
/* Where are we?                                                        */
/* ==================================================================== */

$STEPS = [
    'welcome'      => 'Before you start',
    'requirements' => 'Server check',
    'database'     => 'Database',
    'schema'       => 'Create tables',
    'account'      => 'Administrator',
    'finish'       => 'Finish',
];

$pdo         = null;
$dbError     = null;
$tables      = 0;
$admins      = 0;
$installedAt = '';

if ($hasConfig) {
    [$pdo, $dbError] = try_connect([
        'host' => DB_HOST, 'port' => DB_PORT, 'name' => DB_NAME,
        'user' => DB_USER, 'pass' => DB_PASS,
    ]);
    if ($pdo) {
        $tables = table_count($pdo);
        $admins = $tables ? admin_count($pdo) : 0;
        if ($tables) {
            try {
                $installedAt = (string) $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'installed_at'"
                )->fetchColumn();
            } catch (Throwable $ex) {
                $installedAt = '';
            }
        }
    }
}

/** The furthest step the current state allows. */
function furthest_step(bool $hasConfig, ?PDO $pdo, int $tables, int $admins, string $installedAt): string
{
    if (!$hasConfig || !$pdo) return 'database';
    if ($tables < 21)         return 'schema';
    if ($admins < 1)          return 'account';
    if ($installedAt === '')  return 'finish';
    return 'locked';
}

$furthest = furthest_step($hasConfig, $pdo, $tables, $admins, $installedAt);
$locked   = ($furthest === 'locked');

$step = $_GET['step'] ?? $_POST['step'] ?? null;
if (!$step || !isset($STEPS[$step])) {
    // No explicit step: resume where the install left off. A finished install
    // lands on the "already installed" card rather than the finish page.
    $step = $hasConfig ? $furthest : 'welcome';
    if ($step === 'locked') {
        $step = 'welcome';
    }
}

// Nobody can jump ahead of where the install actually is -- otherwise a
// bookmarked or hand-typed ?step= lands on a page whose prerequisites
// (config file, database connection, tables) do not exist yet.
$stepOrder  = array_keys($STEPS);
$allowedMax = array_search($furthest === 'locked' ? 'finish' : $furthest, $stepOrder, true);
$requested  = array_search($step, $stepOrder, true);
if ($requested !== false && $allowedMax !== false && $requested > $allowedMax) {
    $step = $stepOrder[$allowedMax];
}

$errors  = [];
$notices = [];
$done    = [];

/* ==================================================================== */
/* Actions                                                              */
/* ==================================================================== */

$action = $_POST['action'] ?? '';

// ---- create the uploads tree, then re-run the checks -----------------
if ($action === 'prepare') {
    $prep = health_prepare_uploads();
    if ($prep['created']) {
        $notices[] = 'Created: ' . implode(', ', $prep['created']);
    }
    if ($prep['failed']) {
        $errors[] = 'Could not create: ' . implode(', ', $prep['failed'])
                  . '. In cPanel → File Manager, set /uploads to permission 755 and click Re-check.';
    }
    $step = 'requirements';
}

// ---- download the generated config file ------------------------------
if ($action === 'download_config') {
    $cfg = build_config([
        'host' => trim($_POST['db_host'] ?? 'localhost'),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'name' => trim($_POST['db_name'] ?? ''),
        'user' => trim($_POST['db_user'] ?? ''),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ], (string) ($_POST['cron_token'] ?? bin2hex(random_bytes(16))));

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="config.php"');
    header('Content-Length: ' . strlen($cfg));
    echo $cfg;
    exit;
}

// ---- test the connection and write config.php -------------------------
$manualConfig = null;
$dbForm = [
    'host' => trim($_POST['db_host'] ?? (defined('DB_HOST') ? DB_HOST : 'localhost')),
    'port' => (int) ($_POST['db_port'] ?? (defined('DB_PORT') ? DB_PORT : 3306)),
    'name' => trim($_POST['db_name'] ?? (defined('DB_NAME') ? DB_NAME : '')),
    'user' => trim($_POST['db_user'] ?? (defined('DB_USER') ? DB_USER : '')),
    'pass' => (string) ($_POST['db_pass'] ?? ''),
];

if ($action === 'database') {
    $step = 'database';
    if ($dbForm['name'] === '' || $dbForm['user'] === '') {
        $errors[] = 'Enter the database name and database user.';
    } else {
        [$testPdo, $testErr] = try_connect($dbForm);
        if (!$testPdo) {
            $errors[] = $testErr;
        } else {
            $token = (string) ($_POST['cron_token'] ?? '');
            if ($token === '') {
                $token = bin2hex(random_bytes(16));
            }
            $cfg = build_config($dbForm, $token);

            if (!is_dir($appRoot . '/config')) {
                @mkdir($appRoot . '/config', 0755, true);
            }
            if (@file_put_contents($configFile, $cfg) !== false) {
                @chmod($configFile, 0640);
                header('Location: install.php?step=schema');
                exit;
            }
            // /config is not writable — hand the file to the user instead.
            $manualConfig = ['content' => $cfg, 'token' => $token];
            $errors[] = 'Connected to the database successfully, but this account does not allow '
                      . 'PHP to write into the /config folder. Use the manual option below — '
                      . 'it takes about a minute.';
        }
    }
}

if ($action === 'recheck_config') {
    if (file_exists($configFile)) {
        header('Location: install.php?step=schema');
        exit;
    }
    $errors[] = 'config.php still is not in the /config folder. Make sure the file is named exactly '
              . '"config.php" (not "config.php.txt") and sits inside /config.';
    $step = 'database';
}

// ---- create the schema -------------------------------------------------
if ($action === 'schema' && $pdo) {
    $step = 'schema';
    $counts = [];
    $counts['schema']    = run_sql_file($pdo, $appRoot . '/sql/schema.sql', $errors);
    $counts['reference'] = run_sql_file($pdo, $appRoot . '/sql/seed_reference.sql', $errors);
    if (!empty($_POST['seed_machines'])) {
        $counts['fleet'] = run_sql_file($pdo, $appRoot . '/sql/seed_admi_machines.sql', $errors);
    }
    $tables = table_count($pdo);
    if (!$errors && $tables >= 21) {
        header('Location: install.php?step=account');
        exit;
    }
    if ($errors) {
        $errors[] = 'If these errors mention "command denied", the database user needs ALL PRIVILEGES: '
                  . 'cPanel → MySQL Databases → Add User To Database.';
    }
    $done[] = $counts;
}

// ---- create the first administrator ------------------------------------
$acct = [
    'full_name' => trim($_POST['full_name'] ?? ''),
    'username'  => trim($_POST['username'] ?? ''),
    'email'     => trim($_POST['email'] ?? ''),
];
if ($action === 'account' && $pdo) {
    $step = 'account';
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');

    if ($acct['full_name'] === '' || $acct['username'] === '')  $errors[] = 'Enter a name and a username.';
    if (!filter_var($acct['email'], FILTER_VALIDATE_EMAIL))     $errors[] = 'Enter a valid email address.';
    if (strlen($pw) < 10)                                       $errors[] = 'The password must be at least 10 characters.';
    if ($pw !== $pw2)                                           $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        try {
            $st = $pdo->prepare(
                'INSERT INTO users (username, email, full_name, password_hash, role, is_active)
                 VALUES (:u, :e, :f, :h, "admin", 1)'
            );
            $st->execute([
                ':u' => $acct['username'], ':e' => $acct['email'], ':f' => $acct['full_name'],
                ':h' => password_hash($pw, PASSWORD_DEFAULT),
            ]);

            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group)
                           VALUES ('installed_at', :v, 'general')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([':v' => date('Y-m-d H:i:s')]);

            if (defined('CRON_TOKEN') && CRON_TOKEN !== '' && CRON_TOKEN !== 'CHANGE_ME_LONG_RANDOM_STRING') {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group, is_secret)
                               VALUES ('cron_token', :v, 'schedule', 1)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                    ->execute([':v' => CRON_TOKEN]);
            }
            header('Location: install.php?step=finish');
            exit;
        } catch (PDOException $ex) {
            $errors[] = stripos($ex->getMessage(), 'Duplicate') !== false
                ? 'That username or email address is already registered. Pick another, or sign in instead.'
                : 'Could not create the account: ' . $ex->getMessage();
        }
    }
}

// ---- delete the installer ----------------------------------------------
if ($action === 'self_delete') {
    if (@unlink(__FILE__)) {
        header('Location: login.php');
        exit;
    }
    $errors[] = 'PHP could not delete install.php on this account. Delete it by hand in '
              . 'cPanel → File Manager (select install.php → Delete).';
    $step = 'finish';
}

/* ==================================================================== */
/* View data                                                            */
/* ==================================================================== */

$checks = ($step === 'requirements') ? health_environment_checks() : [];
$worst  = $checks ? health_worst($checks) : 'ok';
$hint   = cpanel_hint();
$order  = array_keys($STEPS);
$index  = array_search($step, $order, true);
$appUrl = '';
if (PHP_SAPI !== 'cli') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}
$assetCount = null;
if ($pdo && $tables >= 21) {
    try {
        $assetCount = (int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    } catch (Throwable $ex) {
        $assetCount = null;
    }
}

$cronToken = '';
if ($pdo && $tables) {
    try {
        $cronToken = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key='cron_token'")->fetchColumn();
    } catch (Throwable $ex) {
        $cronToken = '';
    }
}
if ($cronToken === '' && defined('CRON_TOKEN')) {
    $cronToken = CRON_TOKEN;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install &middot; ADMI Maintenance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background:#12263f; color:#12263f; padding:2rem 0 4rem; }
  .wrap { max-width: 820px; }
  .steps { list-style:none; padding:0; margin:0 0 1.25rem; display:flex; flex-wrap:wrap; gap:.4rem; }
  .steps li { flex:1 1 auto; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em;
              padding:.45rem .5rem; text-align:center; border-radius:.3rem;
              background:rgba(255,255,255,.12); color:rgba(255,255,255,.65); white-space:nowrap; }
  .steps li.active { background:#0d6efd; color:#fff; font-weight:600; }
  .steps li.done   { background:#198754; color:#fff; }
  .check-ok   { color:#198754; font-weight:700; }
  .check-warn { color:#b8860b; font-weight:700; }
  .check-fail { color:#dc3545; font-weight:700; }
  code, pre { font-size:.85em; }
  pre.cmd { background:#f6f8fa; border:1px solid #dde3ea; border-radius:.3rem; padding:.6rem;
            white-space:pre-wrap; word-break:break-all; margin-bottom:.35rem; }
  .lead-sm { font-size:.95rem; color:#4b5b6b; }
</style>
</head>
<body>
<div class="container wrap">

  <h1 class="h5 text-white mb-1">Aerospace &amp; Defense Manufacturing Inc.</h1>
  <p class="text-white-50 mb-3">Equipment, Asset &amp; Maintenance Management — installer</p>

  <ul class="steps">
    <?php foreach ($order as $i => $key): ?>
      <li class="<?= $key === $step ? 'active' : ($i < $index ? 'done' : '') ?>">
        <?= $i + 1 ?>. <?= e($STEPS[$key]) ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php foreach ($errors as $msg): ?>
    <div class="alert alert-danger"><?= e($msg) ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $msg): ?>
    <div class="alert alert-success py-2"><?= e($msg) ?></div>
  <?php endforeach; ?>

  <?php if ($locked && !in_array($action, ['self_delete'], true) && $step !== 'finish'): ?>
    <div class="card"><div class="card-body">
      <h2 class="h5">Already installed</h2>
      <p class="lead-sm">This system was installed on <strong><?= e($installedAt) ?></strong>.
         Re-running the installer is blocked so nothing can be overwritten.</p>
      <div class="alert alert-danger py-2 mb-3">
        For security, <strong>install.php should not stay on the server</strong>.
      </div>
      <form method="post" class="d-inline">
        <input type="hidden" name="action" value="self_delete">
        <button class="btn btn-danger">Delete install.php now</button>
      </form>
      <a class="btn btn-outline-secondary" href="login.php">Go to sign-in</a>
    </div></div>

  <?php elseif ($step === 'welcome'): ?>
    <div class="card"><div class="card-body">
      <h2 class="h5">Before you start</h2>
      <p class="lead-sm">This takes about five minutes. Have these ready:</p>
      <ol class="lead-sm">
        <li><strong>A MySQL database and user.</strong> In cPanel, open
            <em>Databases → MySQL Database Wizard</em>. Create the database, create a user with a
            strong password, and on the final screen tick <strong>ALL PRIVILEGES</strong>.
            <?php if ($hint): ?>
              <br><span class="text-muted">On this account the names will look like
              <code><?= e($hint) ?>_admimaint</code> and <code><?= e($hint) ?>_admiuser</code>.</span>
            <?php endif; ?></li>
        <li><strong>Write down the database name, user and password</strong> exactly as cPanel shows
            them, including the account prefix.</li>
        <li><strong>Check the PHP version.</strong> cPanel → <em>Select PHP Version</em> → 8.1 or newer.</li>
      </ol>
      <p class="lead-sm mb-0">You do not need to touch any files by hand — the installer writes the
         configuration and creates the tables for you.</p>
    </div>
    <div class="card-footer bg-white">
      <a class="btn btn-primary" href="install.php?step=requirements">Start &rarr;</a>
    </div></div>

  <?php elseif ($step === 'requirements'): ?>
    <div class="card"><div class="card-body">
      <h2 class="h5">Server check</h2>
      <p class="lead-sm">Everything below must be green or amber before continuing.
         Amber items are safe to leave for now.</p>
      <table class="table table-sm align-middle">
        <tbody>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td style="width:2rem" class="check-<?= e($c['status']) ?>">
              <?= $c['status'] === 'ok' ? '&check;' : ($c['status'] === 'warn' ? '!' : '&times;') ?>
            </td>
            <td><?= e($c['label']) ?></td>
            <td class="small text-muted"><?= e($c['detail']) ?>
              <?php if ($c['status'] !== 'ok' && $c['fix']): ?>
                <div class="small"><em><?= e($c['fix']) ?></em></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="d-inline">
        <input type="hidden" name="action" value="prepare">
        <button class="btn btn-outline-secondary">Create folders &amp; re-check</button>
      </form>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between">
      <a class="btn btn-link" href="install.php?step=welcome">&larr; Back</a>
      <?php if ($worst === 'fail'): ?>
        <span class="text-danger small align-self-center">Fix the red items, then re-check.</span>
      <?php else: ?>
        <a class="btn btn-primary" href="install.php?step=database">Continue &rarr;</a>
      <?php endif; ?>
    </div></div>

  <?php elseif ($step === 'database'): ?>
    <?php if ($manualConfig): ?>
      <div class="card mb-3"><div class="card-body">
        <h2 class="h5">One manual step</h2>
        <p class="lead-sm">The database details are correct — PHP just is not allowed to create
           <code>config/config.php</code> on this hosting account. Do one of these:</p>
        <p class="fw-semibold mb-1">Option A — download and upload (easiest)</p>
        <ol class="lead-sm">
          <li>Click <strong>Download config.php</strong> below.</li>
          <li>In cPanel → <em>File Manager</em>, open this app's <code>config</code> folder.</li>
          <li>Click <em>Upload</em> and drop the file in. Keep the name <code>config.php</code>.</li>
          <li>Come back here and click <strong>I have uploaded it</strong>.</li>
        </ol>
        <form method="post" class="mb-3">
          <input type="hidden" name="action" value="download_config">
          <input type="hidden" name="db_host" value="<?= e($dbForm['host']) ?>">
          <input type="hidden" name="db_port" value="<?= e($dbForm['port']) ?>">
          <input type="hidden" name="db_name" value="<?= e($dbForm['name']) ?>">
          <input type="hidden" name="db_user" value="<?= e($dbForm['user']) ?>">
          <input type="hidden" name="db_pass" value="<?= e($dbForm['pass']) ?>">
          <input type="hidden" name="cron_token" value="<?= e($manualConfig['token']) ?>">
          <button class="btn btn-primary">Download config.php</button>
        </form>
        <p class="fw-semibold mb-1">Option B — create the file yourself</p>
        <p class="lead-sm">In File Manager, create <code>config/config.php</code> and paste this in:</p>
        <textarea class="form-control font-monospace" rows="10" readonly
                  onclick="this.select()"><?= e($manualConfig['content']) ?></textarea>
        <form method="post" class="mt-3">
          <input type="hidden" name="action" value="recheck_config">
          <button class="btn btn-success">I have uploaded it — continue</button>
        </form>
      </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
      <h2 class="h5">Database connection</h2>
      <?php if ($hasConfig && !$pdo && !$errors): ?>
        <div class="alert alert-warning">
          <strong>The saved database details are not working.</strong><br>
          <?= e($dbError ?: 'The database could not be reached.') ?><br>
          <span class="small">Re-enter them below — saving will overwrite
          <code>config/config.php</code>. The password is never shown back, so type it again.</span>
        </div>
      <?php endif; ?>
      <p class="lead-sm">Copy these exactly as cPanel shows them, including the account prefix.
        <?php if ($hint): ?>
          On this server they usually start with <code><?= e($hint) ?>_</code>.
        <?php endif; ?>
      </p>
      <form method="post">
        <input type="hidden" name="action" value="database">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="db_host">Database host</label>
            <input class="form-control" id="db_host" name="db_host" value="<?= e($dbForm['host'] ?: 'localhost') ?>" required>
            <div class="form-text">On GoDaddy shared hosting this is almost always <code>localhost</code>.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="db_port">Port</label>
            <input class="form-control" id="db_port" name="db_port" value="<?= e($dbForm['port'] ?: 3306) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="db_name">Database name</label>
            <input class="form-control" id="db_name" name="db_name" value="<?= e($dbForm['name']) ?>"
                   placeholder="<?= e(($hint ?: 'cpuser') . '_admimaint') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="db_user">Database user</label>
            <input class="form-control" id="db_user" name="db_user" value="<?= e($dbForm['user']) ?>"
                   placeholder="<?= e(($hint ?: 'cpuser') . '_admiuser') ?>" required autocomplete="off">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="db_pass">Database password</label>
            <input class="form-control" id="db_pass" type="password" name="db_pass"
                   value="<?= e($dbForm['pass']) ?>" autocomplete="off">
          </div>
        </div>
        <div class="mt-3 d-flex justify-content-between">
          <a class="btn btn-link" href="install.php?step=requirements">&larr; Back</a>
          <button class="btn btn-primary">Test connection &amp; save &rarr;</button>
        </div>
      </form>
    </div></div>

  <?php elseif ($step === 'schema'): ?>
    <div class="card"><div class="card-body">
      <h2 class="h5">Create the tables</h2>
      <?php if (!$hasConfig): ?>
        <div class="alert alert-warning mb-0">
          The database details have not been saved yet.
          <a href="install.php?step=database">Go back and enter them</a>.
        </div>
      <?php elseif (!$pdo): ?>
        <div class="alert alert-danger"><?= e($dbError ?: 'Could not connect to the database.') ?></div>
        <p class="lead-sm mb-0">Fix <code>config/config.php</code>, or delete that file and
          <a href="install.php?step=database">re-enter the database details</a>.</p>
      <?php else: ?>
        <p class="lead-sm">Connected to <strong><?= e(DB_NAME) ?></strong>.
          <?= $tables ? 'It currently holds ' . (int) $tables . ' tables.' : 'It is empty and ready.' ?>
        </p>
        <?php if ($done): ?>
          <div class="alert alert-info small">
            <?php foreach ($done as $set): foreach ($set as $k => $n): ?>
              <?= e($k) ?>: <?= (int) $n ?> statements &middot;
            <?php endforeach; endforeach; ?>
            now <?= (int) $tables ?> tables.
          </div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="schema">
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="sm" name="seed_machines" value="1" checked>
            <label class="form-check-label" for="sm">
              <strong>Load the ADMI machine fleet</strong> — 15 CNC machines with 403 preventive-maintenance
              requirements and their specifications, imported from the existing maintenance plan workbook.
              <span class="text-muted d-block small">Recommended. Without it you start with an empty register.</span>
            </label>
          </div>
          <div class="d-flex justify-content-between">
            <span></span>
            <button class="btn btn-primary">Create tables &rarr;</button>
          </div>
        </form>
      <?php endif; ?>
    </div></div>

  <?php elseif ($step === 'account'): ?>
    <div class="card"><div class="card-body">
      <h2 class="h5">Create your administrator account</h2>
      <?php if (!$pdo || $tables < 21): ?>
        <div class="alert alert-warning mb-0">
          The tables have not been created yet.
          <a href="install.php?step=schema">Go back and create them</a>.
        </div>
      <?php else: ?>
      <p class="lead-sm">This account can manage the asset register, build maintenance requirements,
         add users and change settings.</p>
      <form method="post">
        <input type="hidden" name="action" value="account">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="full_name">Full name</label>
            <input class="form-control" id="full_name" name="full_name" value="<?= e($acct['full_name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" value="<?= e($acct['email']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" value="<?= e($acct['username']) ?>"
                   required autocomplete="off">
          </div>
          <div class="col-md-6"></div>
          <div class="col-md-6">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" minlength="10" required>
            <div class="form-text">At least 10 characters.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password2">Confirm password</label>
            <input class="form-control" type="password" id="password2" name="password2" minlength="10" required>
          </div>
        </div>
        <div class="mt-3 text-end"><button class="btn btn-primary">Create account &rarr;</button></div>
      </form>
      <?php endif; ?>
    </div></div>

  <?php else: /* finish */ ?>
    <div class="card"><div class="card-body">
      <h2 class="h5 text-success">Installation complete</h2>
      <?php if (!$pdo): ?>
        <div class="alert alert-warning">
          The installer cannot reach the database from here, so it could not confirm the final state.
          Sign in to check, or <a href="install.php?step=database">re-enter the database details</a>.
        </div>
      <?php endif; ?>
      <p class="lead-sm">
        <?= (int) $tables ?> tables created<?= $assetCount !== null ? ', ' . $assetCount . ' assets registered' : '' ?>.
        Three short jobs remain — none of them blocks you from signing in.
      </p>

      <ol class="lead-sm">
        <li class="mb-3">
          <strong>Delete the installer.</strong> Use the button below, or remove
          <code>install.php</code> in cPanel → File Manager.
        </li>
        <li class="mb-3">
          <strong>Add the nightly cron job.</strong> cPanel → <em>Advanced</em> → <em>Cron Jobs</em>,
          set it to run once a day (4:00 a.m. is a good choice) with this command:
          <pre class="cmd" id="cronCmd">/usr/local/bin/php <?= e($appRoot) ?>/cron/run.php <?= e($cronToken ?: '<cron_token>') ?></pre>
          <button type="button" class="btn btn-sm btn-outline-secondary"
                  onclick="navigator.clipboard.writeText(document.getElementById('cronCmd').textContent.trim());this.textContent='Copied';">
            Copy command</button>
          <div class="small text-muted mt-1">
            If your plan offers only URL-based cron, use:<br>
            <code>curl -s "<?= e($appUrl) ?>/cron/run.php?token=<?= e($cronToken ?: '<cron_token>') ?>"</code>
          </div>
        </li>
        <li class="mb-3">
          <strong>Upload the ADMI logo and enter the SMTP details.</strong>
          Sign in, then go to <em>Admin → Settings</em>. The logo appears in the app header,
          the sign-in page and on PDF reports.
        </li>
      </ol>

      <p class="lead-sm mb-0">
        You can confirm everything at any time from <em>Admin → Settings → System Check</em>.
      </p>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
      <form method="post" class="d-inline">
        <input type="hidden" name="action" value="self_delete">
        <button class="btn btn-danger">Delete install.php &amp; go to sign-in</button>
      </form>
      <a class="btn btn-outline-secondary" href="login.php">Skip — go to sign-in</a>
    </div></div>
  <?php endif; ?>

  <p class="text-white-50 small mt-3 mb-0">
    Stuck? <code>docs/INSTALL.md</code> in this folder walks through the same steps with screenshots
    of where each cPanel setting lives.
  </p>
</div>
</body>
</html>
