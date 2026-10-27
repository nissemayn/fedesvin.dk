<?php
declare(strict_types=1);

if (!defined('DOMAIN')) {
    http_response_code(404);
    exit;
}

function adminCredentials(): array
{
    $username = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($username !== '') {
        return [$username, $password];
    }
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+(.+)$/i', $authorization, $match)) {
        $decoded = base64_decode($match[1], true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            return explode(':', $decoded, 2);
        }
    }
    return ['', ''];
}

function requireSiteAdmin(): void
{
    $expectedPassword = getenv('GUESTBOOK_ADMIN_PASSWORD');
    if (!is_string($expectedPassword) || strlen($expectedPassword) < 8) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Administration kræver GUESTBOOK_ADMIN_PASSWORD på mindst 8 tegn.';
        exit;
    }

    [$username, $password] = adminCredentials();
    $expectedUsername = getenv('GUESTBOOK_ADMIN_USER') ?: 'admin';
    if (!hash_equals($expectedUsername, $username) || !hash_equals($expectedPassword, $password)) {
        header('WWW-Authenticate: Basic realm="Fedesvin administration", charset="UTF-8"');
        header('Cache-Control: no-store');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Administrator-login kræves.';
        exit;
    }

    session_name('fedesvin_guestbook_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (empty($_SESSION['guestbook_admin'])) {
        session_regenerate_id(true);
        $_SESSION['guestbook_admin'] = true;
    }
    $_SESSION['guestbook_csrf'] ??= bin2hex(random_bytes(32));
}
