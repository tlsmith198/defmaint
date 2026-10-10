<?php
/** Shared view + formatting helpers. */

/** HTML-escape. */
function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute app URL from a path relative to the app root. */
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** Public URL for a stored upload (path relative to /uploads). */
function upload_url(string $relative): string
{
    return url('uploads/' . ltrim(str_replace('\\', '/', $relative), '/'));
}

function redirect(string $path, int $code = 302): void
{
    header('Location: ' . (preg_match('~^https?://~i', $path) ? $path : url($path)), true, $code);
    exit;
}

/* ---------------------------------------------------------------- flash */

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ----------------------------------------------------------- formatting */

function fmt_date($value, string $empty = '—'): string
{
    if (empty($value) || $value === '0000-00-00') {
        return $empty;
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    return $ts ? date(setting('date_format', 'm/d/Y'), $ts) : $empty;
}

function fmt_datetime($value, string $empty = '—'): string
{
    if (empty($value) || $value === '0000-00-00 00:00:00') {
        return $empty;
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    return $ts ? date(setting('datetime_format', 'm/d/Y g:i A'), $ts) : $empty;
}

/** "3 days ago" / "in 2 weeks" style relative text. */
function fmt_age($value, string $empty = '—'): string
{
    if (empty($value)) {
        return $empty;
    }
    $ts   = is_numeric($value) ? (int) $value : strtotime((string) $value);
    $diff = time() - $ts;
    $past = $diff >= 0;
    $diff = abs($diff);
    $units = [31536000 => 'yr', 2592000 => 'mo', 604800 => 'wk', 86400 => 'day', 3600 => 'hr', 60 => 'min'];
    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $n = (int) floor($diff / $secs);
            $t = $n . ' ' . $label . ($n > 1 && $label !== 'min' ? 's' : ($n > 1 ? 's' : ''));
            return $past ? $t . ' ago' : 'in ' . $t;
        }
    }
    return $past ? 'just now' : 'now';
}

function fmt_number($v, int $dec = 0, string $empty = '—'): string
{
    return ($v === null || $v === '') ? $empty : number_format((float) $v, $dec);
}

/* --------------------------------------------------------- label tables */

function asset_statuses(): array
{
    return [
        'active'           => ['label' => 'Active',              'class' => 'success'],
        'down_maintenance' => ['label' => 'Down for Maintenance', 'class' => 'warning'],
        'down_repair'      => ['label' => 'Down for Repair',     'class' => 'danger'],
        'out_of_service'   => ['label' => 'Out of Service',      'class' => 'secondary'],
        'retired'          => ['label' => 'Retired',             'class' => 'dark'],
    ];
}

function frequencies(): array
{
    return [
        'daily'      => ['label' => 'Daily',       'days' => 1],
        'weekly'     => ['label' => 'Weekly',      'days' => 7],
        'monthly'    => ['label' => 'Monthly',     'days' => 30],
        'quarterly'  => ['label' => 'Quarterly',   'days' => 91],
        'semiannual' => ['label' => 'Semi-Annual', 'days' => 182],
        'annual'     => ['label' => 'Annual',      'days' => 365],
        'custom'     => ['label' => 'Custom',      'days' => 0],
    ];
}

function severities(): array
{
    return [
        'minor' => ['label' => 'Minor',        'class' => 'info'],
        'major' => ['label' => 'Major',        'class' => 'warning'],
        'down'  => ['label' => 'Machine Down', 'class' => 'danger'],
    ];
}

function issue_statuses(): array
{
    return [
        'open'         => ['label' => 'Open',        'class' => 'danger'],
        'acknowledged' => ['label' => 'Acknowledged','class' => 'warning'],
        'in_progress'  => ['label' => 'In Progress', 'class' => 'primary'],
        'resolved'     => ['label' => 'Resolved',    'class' => 'success'],
        'closed'       => ['label' => 'Closed',      'class' => 'secondary'],
    ];
}

/** Render a Bootstrap badge from one of the label tables above. */
function badge(?string $key, array $table, string $extra = ''): string
{
    $meta = $table[$key] ?? ['label' => $key ?: '—', 'class' => 'secondary'];
    return sprintf(
        '<span class="badge text-bg-%s %s">%s</span>',
        h($meta['class']),
        h($extra),
        h($meta['label'])
    );
}

function label_of(?string $key, array $table): string
{
    return $table[$key]['label'] ?? (string) $key;
}

/* -------------------------------------------------------------- utility */

/** Next sequential asset tag, e.g. EQ-0016. */
function next_asset_tag(): string
{
    $prefix = setting('asset_tag_prefix', 'EQ-');
    $pad    = setting_int('asset_tag_pad', 4);
    $offset = strlen($prefix) + 1;          // inlined: MySQL needs a literal here
    $max    = db_value(
        'SELECT MAX(CAST(SUBSTRING(asset_tag, ' . (int) $offset . ') AS UNSIGNED))
           FROM assets WHERE asset_tag LIKE :p',
        [':p' => $prefix . '%'],
        0
    );
    return $prefix . str_pad((string) ((int) $max + 1), $pad, '0', STR_PAD_LEFT);
}

/** Append a row to audit_log. Never throws. */
function audit(string $action, ?string $entityType = null, ?int $entityId = null, $detail = null): void
{
    try {
        db_insert('audit_log', [
            'user_id'     => current_user_id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'detail'      => $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail)),
            'ip_address'  => client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('audit failed: ' . $e->getMessage());
    }
}

function client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/** Difference between two associative arrays, for audit detail. */
function diff_fields(array $before, array $after): array
{
    $out = [];
    foreach ($after as $k => $v) {
        $old = $before[$k] ?? null;
        if ((string) $old !== (string) $v) {
            $out[$k] = ['from' => $old, 'to' => $v];
        }
    }
    return $out;
}

/** Read a request value with a default. */
function req(string $key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function req_int(string $key, ?int $default = null): ?int
{
    $v = req($key, null);
    return ($v === null || $v === '') ? $default : (int) $v;
}

/** Null out empty strings so they land in the DB as NULL. */
function nn($v)
{
    return ($v === '' || $v === null) ? null : $v;
}

/** Keep only whitelisted keys. */
function pick(array $src, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $src[$k] ?? null;
    }
    return $out;
}

/** Build a query string preserving current filters. */
function qs(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
}

/** Sortable table header link. */
function sort_link(string $column, string $label, string $current, string $dir): string
{
    $next = ($current === $column && $dir === 'asc') ? 'desc' : 'asc';
    $icon = '';
    if ($current === $column) {
        $icon = $dir === 'asc' ? ' &uarr;' : ' &darr;';
    }
    return '<a class="sort-link" href="' . h(qs(['sort' => $column, 'dir' => $next, 'page' => null])) . '">'
         . h($label) . $icon . '</a>';
}
