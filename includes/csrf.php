<?php
/** Per-session CSRF token. Every state-changing POST must carry it. */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Abort the request unless it is a POST carrying a valid token. */
function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_valid($_POST['_csrf'] ?? null)) {
        http_response_code(400);
        die('Invalid or expired form token. Go back, reload the page, and try again.');
    }
}
