<?php
/** Application settings: key/value rows in `settings`, cached per request. */

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
}

/** Like setting(), but an empty stored value stays empty (needed for "None"-style choices). */
function setting_raw(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting($key, null);
    return $v === null ? $default : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, null);
    return $v === null ? $default : (int) $v;
}

function setting_set(string $key, $value, ?string $group = null): void
{
    db_query(
        'INSERT INTO settings (setting_key, setting_value, setting_group, updated_by)
         VALUES (:k, :v, COALESCE(:g, \'general\'), :u)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 setting_group = COALESCE(:g2, setting_group),
                                 updated_by    = VALUES(updated_by)',
        [':k' => $key, ':v' => (string) $value, ':g' => $group, ':g2' => $group, ':u' => current_user_id()]
    );
    settings_all(true);
}
