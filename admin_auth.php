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
    $validCredentials = hash_equals($expectedUsername, $username) && hash_equals($expectedPassword, $password);
    $now = time();
    $window = 900;
    $ipHash = hash_hmac('sha256', 'admin-login|' . clientIp(), counterSecret());
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $cleanup = $pdo->prepare('DELETE FROM admin_login_attempts WHERE window_started <= :cutoff');
        $cleanup->execute([':cutoff' => $now - 86400]);
        $lookup = $pdo->prepare('SELECT window_started, attempts FROM admin_login_attempts WHERE ip_hash = :ip_hash');
        $lookup->execute([':ip_hash' => $ipHash]);
        $attempt = $lookup->fetch(PDO::FETCH_ASSOC);
        $windowStarted = $attempt === false ? $now : (int) $attempt['window_started'];
        $attempts = $attempt === false ? 0 : (int) $attempt['attempts'];
        if ($windowStarted <= $now - $window) {
            $attempts = 0;
            $windowStarted = $now;
            $removeExpired = $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip_hash');
            $removeExpired->execute([':ip_hash' => $ipHash]);
        }

        if ($attempts >= 10) {
            $pdo->exec('COMMIT');
            header('Retry-After: ' . max(1, $windowStarted + $window - $now));
            http_response_code(429);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'For mange fejlede loginforsøg. Prøv igen om lidt.';
            exit;
        }

        if (!$validCredentials) {
            $increment = $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash, window_started, attempts) VALUES (:ip_hash, :window_started, 1)
                ON CONFLICT(ip_hash) DO UPDATE SET
                    window_started = CASE WHEN admin_login_attempts.window_started <= :cutoff THEN excluded.window_started ELSE admin_login_attempts.window_started END,
                    attempts = CASE WHEN admin_login_attempts.window_started <= :attempt_cutoff THEN 1 ELSE admin_login_attempts.attempts + 1 END');
            $increment->execute([
                ':ip_hash' => $ipHash,
                ':window_started' => $now,
                ':cutoff' => $now - $window,
                ':attempt_cutoff' => $now - $window,
            ]);
            $attemptCount = $pdo->prepare('SELECT attempts FROM admin_login_attempts WHERE ip_hash = :ip_hash');
            $attemptCount->execute([':ip_hash' => $ipHash]);
            $attempts = (int) $attemptCount->fetchColumn();
            $pdo->exec('COMMIT');
            if ($attempts >= 10) {
                header('Retry-After: ' . max(1, $windowStarted + $window - $now));
                http_response_code(429);
                header('Cache-Control: no-store');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'For mange fejlede loginforsøg. Prøv igen om lidt.';
                exit;
            }
            header('WWW-Authenticate: Basic realm="Fedesvin administration", charset="UTF-8"');
            header('Cache-Control: no-store');
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Administrator-login kræves.';
            exit;
        }

        $clearAttempts = $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip_hash');
        $clearAttempts->execute([':ip_hash' => $ipHash]);
        $pdo->exec('COMMIT');
    } catch (Throwable $exception) {
        $pdo->exec('ROLLBACK');
        throw $exception;
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
