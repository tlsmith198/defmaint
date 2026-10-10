<?php
/**
 * In-app updater: upload the latest release zip, review it, install it.
 *
 * Flow
 *   1. updater_stage()   validates the zip and stores it in /tmp/updates/pending.zip
 *   2. updater_plan()    compares it with the files on disk (new / changed / unchanged)
 *   3. updater_apply()   backs up every file it will replace, copies the new files,
 *                        runs any new sql/migrations/*.sql, and rolls back on failure
 *   4. updater_rollback() restores a backup (files only -- see the warning on the page)
 *
 * Never touched, no matter what the zip contains: config/config.php, everything under
 * /uploads and /tmp, install.php, .git, and any .write_test_* debris.
 */

const UPDATER_KEEP_BACKUPS = 8;

/**
 * Zip reader that works on any shared host. Uses the PHP zip extension when it is loaded;
 * otherwise falls back to a small pure-PHP reader (needs only zlib, which every host has),
 * so the updater never depends on a php.ini or Select-PHP-Version change.
 */
class UpdZip
{
    private $za = null;
    private $fh = null;
    private $map = [];      // normalised name => original name / entry info

    public static function available(): bool
    {
        return class_exists('ZipArchive') || function_exists('gzinflate');
    }

    public static function open(string $path): ?UpdZip
    {
        $z = new self();
        if (class_exists('ZipArchive') && !defined('UPDATER_FORCE_PURE')) {
            $za = new ZipArchive();
            if ($za->open($path) !== true) {
                return null;
            }
            $z->za = $za;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $orig = $za->getNameIndex($i);
                $z->map[str_replace('\\', '/', $orig)] = $orig;
            }
            return $z;
        }
        return $z->openPure($path) ? $z : null;
    }

    private function openPure(string $path): bool
    {
        if (!function_exists('gzinflate') || !($fh = @fopen($path, 'rb'))) {
            return false;
        }
        $this->fh = $fh;
        $size = filesize($path);
        $tailLen = (int) min($size, 65557);
        fseek($fh, $size - $tailLen);
        $tail = (string) fread($fh, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false || strlen($tail) < $pos + 22) {
            return false;
        }
        $e = unpack('vdisk/vcddisk/vn/vtotal/Vsize/Voffset', substr($tail, $pos + 4, 18));
        if ($e['total'] === 0xFFFF || $e['offset'] === 0xFFFFFFFF) {
            return false;                       // zip64 - not needed for a ~200 KB release
        }
        fseek($fh, $e['offset']);
        $cd = (string) fread($fh, $e['size']);
        $p = 0;
        for ($i = 0; $i < $e['total']; $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02") {
                return false;
            }
            $h = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($cd, $p + 8, 28));
            $off = unpack('Voff', substr($cd, $p + 42, 4))['off'];
            $name = substr($cd, $p + 46, $h['nlen']);
            $this->map[str_replace('\\', '/', $name)] = ['m' => $h['method'], 'c' => $h['csize'], 'u' => $h['usize'],
                                                          'crc' => $h['crc'], 'off' => $off, 'enc' => $h['flags'] & 1];
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        }
        return true;
    }

    /** @return string[] normalised entry names */
    public function names(): array
    {
        return array_keys($this->map);
    }

    public function has(string $name): bool
    {
        return isset($this->map[$name]);
    }

    /** @return string|false */
    public function read(string $name)
    {
        if (!isset($this->map[$name])) {
            return false;
        }
        if ($this->za) {
            return $this->za->getFromName($this->map[$name]);
        }
        $m = $this->map[$name];
        if ($m['enc'] || !in_array($m['m'], [0, 8], true)) {
            return false;
        }
        fseek($this->fh, $m['off']);
        $lh = (string) fread($this->fh, 30);
        if (substr($lh, 0, 4) !== "PK\x03\x04") {
            return false;
        }
        $l = unpack('vnlen/velen', substr($lh, 26, 4));
        fseek($this->fh, $m['off'] + 30 + $l['nlen'] + $l['elen']);
        $raw = $m['c'] > 0 ? (string) fread($this->fh, $m['c']) : '';
        $data = $m['m'] === 8 ? @gzinflate($raw) : $raw;
        if ($data === false || strlen($data) !== $m['u'] || (crc32($data) & 0xFFFFFFFF) !== $m['crc']) {
            return false;                       // truncated or corrupt entry
        }
        return $data;
    }

    public function close(): void
    {
        if ($this->za) {
            $this->za->close();
        }
        if ($this->fh) {
            fclose($this->fh);
        }
    }
}

function updater_dir(string $sub = ''): string
{
    $d = APP_ROOT . '/tmp/updates' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
        if ($sub === '' && is_dir($d) && !is_file($d . '/.htaccess')) {
            // belt and braces: /tmp/.htaccess already blocks web access to this folder
            @file_put_contents($d . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
    }
    return $d;
}

/** Paths (relative to the app root) that an update must never write. */
function updater_is_protected(string $rel): bool
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === 'config/config.php' || $rel === 'install.php') {
        return true;
    }
    foreach (['uploads/', 'tmp/', '.git/'] as $prefix) {
        if (strpos($rel, $prefix) === 0) {
            return true;
        }
    }
    if (strpos(basename($rel), '.write_test_') === 0 || basename($rel) === '.DS_Store') {
        return true;
    }
    return false;
}

/** Reject traversal, absolute paths and odd names inside the archive. */
function updater_safe_path(string $rel): bool
{
    if ($rel === '' || strpos($rel, "\0") !== false || $rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
        return false;
    }
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '..') {
            return false;
        }
    }
    return true;
}

/** Only these extensions are installed -- a stray .exe/.sh in a zip is ignored. */
function updater_allowed_ext(string $rel): bool
{
    $base = basename($rel);
    if ($base === '.htaccess' || $base === '.gitignore' || $base === '.gitkeep') {
        return true;
    }
    if (in_array($base, ['LICENSE', 'VERSION', 'COMMITMENT', 'NOTICE'], true)) {
        return true;                                    // licence/version files shipped inside vendor/
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    return in_array($ext, ['dat', 'icc', 'otf', 'lang', 'php', 'css', 'js', 'sql', 'md', 'json', 'txt', 'png', 'jpg', 'jpeg', 'gif',
                           'svg', 'ico', 'woff', 'woff2', 'ttf', 'map', 'html', 'htm', 'xml', 'csv'], true);
}

/**
 * Find the folder inside the zip that holds the app (the one containing
 * includes/bootstrap.php). Handles a zip made from the folder, or from its contents.
 * @return array [error, prefix]
 */
function updater_find_root(UpdZip $zip): array
{
    $candidates = [];
    foreach ($zip->names() as $name) {
        if (substr($name, -22) === 'includes/bootstrap.php' && strpos($name, '__MACOSX/') === false) {
            $prefix = substr($name, 0, -22);
            if (substr_count($prefix, '/') <= 1) {
                $candidates[] = $prefix;
            }
        }
    }
    if (!$candidates) {
        return ['This zip does not look like the ADMI Maintenance System (no includes/bootstrap.php found).', ''];
    }
    usort($candidates, fn($a, $b) => strlen($a) <=> strlen($b));
    $prefix = $candidates[0];
    if (!$zip->has($prefix . 'dashboard.php') || !$zip->has($prefix . 'login.php')) {
        return ['This zip is missing core files (dashboard.php / login.php). It may be an incomplete package.', ''];
    }
    return ['', $prefix];
}

/** Read the APP_BUILD stamp out of a bootstrap.php string. */
function updater_parse_build(string $src): string
{
    return preg_match("/define\\('APP_BUILD',\\s*'([^']*)'\\)/", $src, $m) ? $m[1] : '';
}

/** Leading date of a build stamp (2026.10.06 · ...) as YYYYMMDD, or 0. */
function updater_build_date(string $build): int
{
    return preg_match('/^(\d{4})\.(\d{2})\.(\d{2})/', $build, $m) ? (int) ($m[1] . $m[2] . $m[3]) : 0;
}

/** "128M" / "2G" / "512K" -> bytes. */
function updater_ini_bytes($v): int
{
    $v = trim((string) $v);
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

function updater_pending_path(): string
{
    return updater_dir() . '/pending.zip';
}

function updater_clear_pending(): void
{
    @unlink(updater_pending_path());
    @unlink(updater_dir() . '/pending.json');
}

/** Zips the user dropped into /tmp/updates/inbox with cPanel File Manager (no web upload needed). */
function updater_inbox(): array
{
    $dir = updater_dir('inbox');
    $out = [];
    foreach (glob($dir . '/*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'at' => date('c', filemtime($f))];
    }
    usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $out;
}

/**
 * Validate a zip on disk and register it as the pending update.
 * @return array [error|null]
 */
function updater_register(string $path, string $displayName): array
{
    if (!UpdZip::available()) {
        return ['This server has neither the PHP zip extension nor zlib, so zips cannot be read. '
              . 'Use the manual update steps in docs/UPDATE.md.'];
    }
    $zip = UpdZip::open($path);
    if (!$zip) {
        return ['The file could not be read as a zip. It may be corrupt or incomplete - download it again.'];
    }
    [$err, $prefix] = updater_find_root($zip);
    if ($err) {
        $zip->close();
        return [$err];
    }
    $bootstrap = $zip->read($prefix . 'includes/bootstrap.php');
    $zip->close();
    $build = $bootstrap === false ? '' : updater_parse_build($bootstrap);
    if ($build === '') {
        return ['The zip has no readable APP_BUILD stamp in includes/bootstrap.php, so it cannot be identified.'];
    }
    updater_clear_pending();
    if ($path !== updater_pending_path() && !@copy($path, updater_pending_path())) {
        return ['Could not save the zip into /tmp/updates. Make sure the /tmp folder is writable '
              . '(File Manager → /tmp → permissions 755) and there is free disk space.'];
    }
    file_put_contents(updater_dir() . '/pending.json', json_encode([
        'name' => $displayName, 'prefix' => $prefix, 'build' => $build,
        'size' => (int) filesize(updater_pending_path()), 'at' => date('c'), 'by' => current_user_name(),
    ]));
    return [null];
}

/** Web upload path. @return array [error|null] */
function updater_stage(array $file): array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        $msg = [UPLOAD_ERR_INI_SIZE => 'The zip is larger than this server allows for uploads. Put the zip in the '
                    . 'inbox folder with cPanel File Manager instead (see below) - no php.ini change needed.',
                UPLOAD_ERR_FORM_SIZE => 'The zip is larger than the form limit.',
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload folder. Use the inbox method below.',
                UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload. Use the inbox method below.',
                UPLOAD_ERR_NO_FILE => 'Choose the update zip first.'][$code] ?? 'Upload failed (code ' . (int) $code . ').';
        return [$msg];
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        return ['That is not a .zip file.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['The upload was not accepted by the server.'];
    }
    return updater_register($file['tmp_name'], (string) $file['name']);
}

/** Use a zip from the inbox folder. @return array [error|null] */
function updater_stage_inbox(string $name): array
{
    if (!preg_match('/^[A-Za-z0-9._\- ()]+\.zip$/', $name) || !is_file(updater_dir('inbox') . '/' . $name)) {
        return ['That file is no longer in the inbox folder.'];
    }
    return updater_register(updater_dir('inbox') . '/' . $name, $name);
}

function updater_pending(): ?array
{
    $f = updater_dir() . '/pending.json';
    if (!is_file($f) || !is_file(updater_pending_path())) {
        return null;
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/**
 * Compare the pending zip with what is on disk.
 * @return array{error:?string,new:array,changed:array,same:int,skipped:array,migrations:array}
 */
function updater_plan(): array
{
    $plan = ['error' => null, 'new' => [], 'changed' => [], 'same' => 0, 'skipped' => [], 'migrations' => []];
    $meta = updater_pending();
    if (!$meta) {
        $plan['error'] = 'There is no update waiting. Upload a zip first.';
        return $plan;
    }
    $zip = UpdZip::open(updater_pending_path());
    if (!$zip) {
        $plan['error'] = 'The staged zip can no longer be opened. Upload it again.';
        return $plan;
    }
    $prefix = $meta['prefix'];
    foreach ($zip->names() as $raw) {
        if (substr($raw, -1) === '/' || strpos($raw, $prefix) !== 0 || strpos($raw, '__MACOSX/') !== false) {
            continue;
        }
        $rel = substr($raw, strlen($prefix));
        if ($rel === '') {
            continue;
        }
        if (!updater_safe_path($rel)) {
            $plan['skipped'][] = $rel . ' (unsafe path)';
            continue;
        }
        if (updater_is_protected($rel)) {
            $plan['skipped'][] = $rel;
            continue;
        }
        if (!updater_allowed_ext($rel)) {
            $plan['skipped'][] = $rel . ' (file type not allowed)';
            continue;
        }
        $data = $zip->read($raw);
        if ($data === false) {
            $plan['error'] = 'The zip is damaged: ' . $rel . ' could not be read. Download the zip again and retry.';
            break;
        }
        $dest = APP_ROOT . '/' . $rel;
        if (!file_exists($dest)) {
            $plan['new'][] = $rel;
        } elseif (md5($data) === md5_file($dest)) {
            $plan['same']++;
        } else {
            $plan['changed'][] = $rel;
        }
    }
    $zip->close();
    sort($plan['new']);
    sort($plan['changed']);

    $done = updater_applied_migrations();
    foreach (array_merge($plan['new'], $plan['changed']) as $rel) {
        if (preg_match('#^sql/migrations/[A-Za-z0-9_.\-]+\.sql$#', $rel) && !isset($done[basename($rel)])) {
            $plan['migrations'][] = $rel;
        }
    }
    sort($plan['migrations']);
    return $plan;
}

function updater_applied_migrations(): array
{
    try {
        db_query("CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = db_all('SELECT filename FROM schema_migrations');
        return array_fill_keys(array_column($rows, 'filename'), true);
    } catch (Throwable $e) {
        return [];
    }
}

/** Split a .sql file into statements (handles -- and # comments, quoted semicolons). */
function updater_split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $q = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($q === '') {
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' || $c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
            } elseif ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
        } else {
            if ($c === '\\' && $q !== '`') {
                $buf .= $c . ($sql[++$i] ?? '');
                continue;
            }
            if ($c === $q) {
                $q = '';
            }
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

function updater_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? updater_rmtree($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Write a file atomically: temp file in the same folder, then rename over the target.
 * A request dying half way can never leave a half-written PHP file behind.
 */
function updater_write(string $dest, string $data): bool
{
    $tmp = $dest . '.upd' . getmypid();
    if (@file_put_contents($tmp, $data) !== strlen($data)) {
        @unlink($tmp);
        return false;
    }
    if (is_file($dest)) {
        @chmod($tmp, @fileperms($dest) & 0777);     // keep the mode cPanel/suPHP already accepted
    } else {
        @chmod($tmp, 0644);                         // GoDaddy suPHP/suEXEC rejects world-writable files
    }
    if (!@rename($tmp, $dest)) {                    // some hosts refuse rename-over; fall back to a direct write
        $ok = @file_put_contents($dest, $data) === strlen($data);
        @unlink($tmp);
        return $ok;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($dest, true);
    }
    return true;
}

function updater_copy_tree_file(string $src, string $dst): bool
{
    $dir = dirname($dst);
    return (is_dir($dir) || @mkdir($dir, 0755, true)) && @copy($src, $dst);
}

/**
 * Install the pending update.
 * @return array [ok(bool), message(string), details(array)]
 */
function updater_apply(): array
{
    $meta = updater_pending();
    $plan = updater_plan();
    if ($plan['error']) {
        return [false, $plan['error'], []];
    }
    $todo = array_merge($plan['changed'], $plan['new']);
    if (!$todo) {
        updater_clear_pending();
        return [true, 'This zip is identical to what is already installed - nothing to do.', []];
    }

    // Shared hosts cap run time and may kill the script if the browser disconnects.
    // Keep going regardless so an install is never left half done.
    @ignore_user_abort(true);
    @set_time_limit(300);

    $before = defined('APP_BUILD') ? APP_BUILD : '';
    $backupName = 'backup_' . date('Ymd_His');
    $backupDir = updater_dir($backupName);

    // ---- 1. backup (plain folder copy - needs no zip support) ----------
    if (!is_dir($backupDir . '/files') && !@mkdir($backupDir . '/files', 0755, true)) {
        return [false, 'Could not create the backup folder in /tmp/updates. Nothing was changed. '
                     . 'Check that /tmp is writable (File Manager → permissions 755).', []];
    }
    foreach ($plan['changed'] as $rel) {
        if (!updater_copy_tree_file(APP_ROOT . '/' . $rel, $backupDir . '/files/' . $rel)) {
            updater_rmtree($backupDir);
            return [false, 'The backup could not be written (disk quota full?). Nothing was changed.', []];
        }
    }
    file_put_contents($backupDir . '/manifest.json', json_encode([
        'from_build' => $before, 'to_build' => $meta['build'], 'at' => date('c'),
        'by' => current_user_name(), 'added' => $plan['new'], 'changed' => $plan['changed'],
    ], JSON_PRETTY_PRINT));

    // ---- 2. copy files -------------------------------------------------
    $zip = UpdZip::open(updater_pending_path());
    $prefix = $meta['prefix'];
    $failed = null;
    if (!$zip) {
        $failed = '(zip)';
    } else {
        foreach ($todo as $rel) {
            $data = $zip->read($prefix . $rel);
            $dest = APP_ROOT . '/' . $rel;
            $dir = dirname($dest);
            if ($data === false || (!is_dir($dir) && !@mkdir($dir, 0755, true)) || !updater_write($dest, $data)) {
                $failed = $rel;
                break;
            }
        }
        $zip->close();
    }
    if ($failed !== null) {
        updater_restore($backupDir, $plan['new']);
        audit('update.failed', 'system', null, ['file' => $failed, 'rolled_back' => true]);
        return [false, 'Could not write ' . $failed . '. Every file was put back the way it was - '
            . 'check folder permissions (755 for folders, 644 for files) in cPanel File Manager and try again.', []];
    }

    // ---- 3. database migrations ----------------------------------------
    $migrated = [];
    foreach ($plan['migrations'] as $rel) {
        $sqlText = (string) @file_get_contents(APP_ROOT . '/' . $rel);
        try {
            foreach (updater_split_sql($sqlText) as $stmt) {
                db_query($stmt);
            }
            db_query('INSERT INTO schema_migrations (filename) VALUES (?)', [basename($rel)]);
            $migrated[] = basename($rel);
        } catch (Throwable $e) {
            updater_restore($backupDir, $plan['new']);
            audit('update.failed', 'system', null, ['migration' => $rel, 'error' => $e->getMessage(), 'rolled_back' => true]);
            return [false, 'Database change ' . basename($rel) . ' failed: ' . $e->getMessage()
                . ' - the files were rolled back. Statements that ran before the error stay applied.', $migrated];
        }
    }

    // ---- 4. finish -------------------------------------------------------
    updater_clear_pending();
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    updater_prune_backups();
    audit('update.applied', 'system', null, [
        'from' => $before, 'to' => $meta['build'], 'changed' => count($plan['changed']),
        'added' => count($plan['new']), 'migrations' => $migrated, 'backup' => $backupName,
    ]);
    return [true, 'Updated to build "' . $meta['build'] . '" - ' . count($plan['changed']) . ' file(s) replaced, '
        . count($plan['new']) . ' added' . ($migrated ? ', ' . count($migrated) . ' database change(s) applied' : '')
        . '. Hard-refresh your browser (Ctrl+F5) so the new styles load.', $migrated];
}

/** Put files back from a backup folder and remove files the update added. */
function updater_restore(string $backupDir, array $added): bool
{
    $ok = true;
    $root = $backupDir . '/files';
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $root)) + 1);
            if (!updater_safe_path($rel) || updater_is_protected($rel)) {
                continue;
            }
            if (!updater_write(APP_ROOT . '/' . $rel, (string) file_get_contents($f->getPathname()))) {
                $ok = false;
            }
        }
    }
    foreach ($added as $rel) {
        if (updater_safe_path($rel) && !updater_is_protected($rel) && is_file(APP_ROOT . '/' . $rel)) {
            @unlink(APP_ROOT . '/' . $rel);
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $ok;
}

/** @return array[] newest first: file, at, from, to, files */
function updater_backups(): array
{
    $out = [];
    foreach (glob(updater_dir() . '/backup_*', GLOB_ONLYDIR) ?: [] as $d) {
        $m = json_decode((string) @file_get_contents($d . '/manifest.json'), true) ?: [];
        $out[] = ['file' => basename($d), 'at' => $m['at'] ?? date('c', filemtime($d)),
                  'from' => $m['from_build'] ?? '', 'to' => $m['to_build'] ?? '',
                  'files' => count($m['changed'] ?? []) + count($m['added'] ?? [])];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function updater_valid_backup(string $name): bool
{
    return (bool) preg_match('/^backup_\d{8}_\d{6}$/', $name) && is_dir(updater_dir() . '/' . $name);
}

function updater_delete_backup(string $name): void
{
    if (updater_valid_backup($name)) {
        updater_rmtree(updater_dir() . '/' . $name);
    }
}

function updater_prune_backups(): void
{
    foreach (array_slice(updater_backups(), UPDATER_KEEP_BACKUPS) as $old) {
        updater_delete_backup($old['file']);
    }
}

/** Roll back to a named backup. @return array [ok, message] */
function updater_rollback(string $name): array
{
    if (!updater_valid_backup($name)) {
        return [false, 'That backup no longer exists.'];
    }
    $dir = updater_dir() . '/' . $name;
    $m = json_decode((string) @file_get_contents($dir . '/manifest.json'), true) ?: [];
    @ignore_user_abort(true);
    $ok = updater_restore($dir, $m['added'] ?? []);
    audit('update.rollback', 'system', null, ['backup' => $name, 'to_build' => $m['from_build'] ?? '', 'ok' => $ok]);
    return $ok
        ? [true, 'Rolled back to build "' . ($m['from_build'] ?? 'previous') . '". Database changes (if any) were not undone.']
        : [false, 'Some files could not be restored - check folder permissions and try again.'];
}
