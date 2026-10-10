<?php
/**
 * Session authentication and the centralized permission gate.
 *
 * Two roles only: 'admin' and 'user'. Every admin-only page or action calls
 * require_admin() as its first statement -- one place to audit, one place to
 * extend if a third tier is ever added.
 */

const LOGIN_MAX_FAILS   = 6;
const LOGIN_LOCK_MINUTES = 15;

function auth_attempt(string $username, string $password): array
{
    $user = db_one(
        'SELECT * FROM users WHERE (username = :u OR email = :e) LIMIT 1',
        [':u' => $username, ':e' => $username]
    );

    if (!$user) {
        audit('login.fail', 'user', null, ['username' => $username, 'reason' => 'no_such_user']);
        return [false, 'Incorrect username or password.'];
    }
    if (!$user['is_active']) {
        audit('login.fail', 'user', (int) $user['id'], ['reason' => 'inactive']);
        return [false, 'That account is deactivated. Contact an administrator.'];
    }
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        return [false, 'Too many failed attempts. Try again in a few minutes.'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        $fails = (int) $user['failed_logins'] + 1;
        $lock  = $fails >= LOGIN_MAX_FAILS
            ? date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60)
            : null;
        db_query(
            'UPDATE users SET failed_logins = :f, locked_until = :l WHERE id = :id',
            [':f' => $fails, ':l' => $lock, ':id' => $user['id']]
        );
        audit('login.fail', 'user', (int) $user['id'], ['reason' => 'bad_password', 'count' => $fails]);
        return [false, 'Incorrect username or password.'];
    }

    // Rehash if PHP's default cost has moved on.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = :h WHERE id = :id', [
            ':h'  => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $user['id'],
        ]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_seen']  = time();

    db_query(
        'UPDATE users SET last_login_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = :id',
        [':id' => $user['id']]
    );
    audit('login.success', 'user', (int) $user['id']);

    return [true, null];
}

function auth_logout(): void
{
    if (current_user_id()) {
        audit('logout', 'user', current_user_id());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Currently logged-in user row, or null. */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $id = $_SESSION['user_id'] ?? null;
    if ($id) {
        $user = db_one('SELECT * FROM users WHERE id = :id AND is_active = 1', [':id' => $id]);
        if (!$user) {          // deactivated mid-session
            $_SESSION = [];
            $user = null;
        }
    }
    return $user;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int) $u['id'] : null;
}

function current_user_name(): string
{
    $u = current_user();
    return $u ? $u['full_name'] : 'System';
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Gate: any authenticated user. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
    $idle = setting_int('session_idle_minutes', 480) * 60;
    if ($idle > 0 && isset($_SESSION['last_seen']) && (time() - $_SESSION['last_seen']) > $idle) {
        auth_logout();
        session_start();
        flash('Your session timed out. Please sign in again.', 'warning');
        redirect('login.php');
    }
    $_SESSION['last_seen'] = time();

    if (!empty(current_user()['must_change_pw'])) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($self, ['password.php', 'logout.php'], true)) {
            redirect('password.php');
        }
    }
}

/** Gate: administrators only. THE admin check -- do not inline role tests. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        audit('access.denied', 'page', null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
        http_response_code(403);
        include APP_ROOT . '/templates/403.php';
        exit;
    }
}

/** Non-fatal check for conditionally showing admin UI. */
function can(string $ability): bool
{
    switch ($ability) {
        case 'manage_assets':
        case 'manage_requirements':
        case 'manage_users':
        case 'manage_settings':
        case 'close_issues':
        case 'edit_completions':
            return is_admin();
        case 'log_completion':
        case 'view_reports':
            return is_logged_in();
        case 'log_issue':
            return is_logged_in() && (setting('who_can_log_issues', 'all') === 'all' || is_admin());
        default:
            return false;
    }
}

/** Users eligible to own an asset / be assigned work. */
function active_users(): array
{
    return db_all('SELECT id, full_name, role, initials FROM users WHERE is_active = 1 ORDER BY full_name');
}
