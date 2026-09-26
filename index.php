<?php
declare(strict_types=1);

const VISIT_WINDOW = 28800;
const COUNTER_TOKEN_TTL = 900;
const COUNTER_RATE_WINDOW = 60;
const COUNTER_RATE_LIMIT = 30;
const DOMAIN = 'fedesvin.dk';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = getenv('DB_PATH') ?: '/data/fedesvin.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS stats (id INTEGER PRIMARY KEY CHECK (id = 1), visits INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('INSERT OR IGNORE INTO stats (id, visits) VALUES (1, 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS shortlinks (code TEXT PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS counter_visitors (visitor_hash TEXT PRIMARY KEY, last_seen INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS counter_rate_limits (ip_hash TEXT PRIMARY KEY, window_started INTEGER NOT NULL, requests INTEGER NOT NULL)');
    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeName(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return implode('', array_slice($characters ?: [], 0, 40));
}

function slugify(string $value): string
{
    $value = strtr($value, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa']);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
    $value = strtolower($value);
    $value = trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
    return substr($value ?: 'ven', 0, 40);
}

function requestUserAgent(): string
{
    return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function clientIp(): string
{
    $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remoteIp, FILTER_VALIDATE_IP) === false) {
        return 'unknown';
    }

    $trustedProxies = array_filter(array_map('trim', explode(',', getenv('TRUSTED_PROXY_IPS') ?: '')));
    if (!in_array($remoteIp, $trustedProxies, true)) {
        return $remoteIp;
    }

    $forwardedIps = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    for ($index = count($forwardedIps) - 1; $index >= 0; $index--) {
        $forwardedIp = trim($forwardedIps[$index]);
        if (filter_var($forwardedIp, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        if (!in_array($forwardedIp, $trustedProxies, true)) {
            return $forwardedIp;
        }
    }

    return $remoteIp;
}

function counterSecret(): string
{
    $configuredSecret = getenv('COUNTER_SECRET');
    if (is_string($configuredSecret) && strlen($configuredSecret) >= 32) {
        return $configuredSecret;
    }

    $databasePath = getenv('DB_PATH') ?: '/data/fedesvin.sqlite';
    $secretPath = dirname($databasePath) . '/.counter-secret';
    $handle = @fopen($secretPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to access the counter secret.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock the counter secret.');
    }

    rewind($handle);
    $secret = trim((string) stream_get_contents($handle));
    if (strlen($secret) < 64) {
        $secret = bin2hex(random_bytes(32));
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $secret);
        fflush($handle);
        chmod($secretPath, 0600);
    }

    flock($handle, LOCK_UN);
    fclose($handle);
    return $secret;
}

function createCounterToken(string $ip, string $userAgent): string
{
    $payload = time() . '.' . bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $payload . '|' . $ip . '|' . $userAgent, counterSecret());
    return $payload . '.' . $signature;
}

function validCounterToken(string $token, string $ip, string $userAgent, string $secret, int $now): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', $token, $parts)) {
        return false;
    }

    $issuedAt = (int) $parts[1];
    if ($issuedAt > $now || $now - $issuedAt > COUNTER_TOKEN_TTL) {
        return false;
    }

    $payload = $parts[1] . '.' . $parts[2];
    $expected = hash_hmac('sha256', $payload . '|' . $ip . '|' . $userAgent, $secret);
    return hash_equals($expected, $parts[3]);
}

function isCrawler(string $userAgent): bool
{
    return preg_match('/(?:bot|crawler|spider|slurp|bingpreview|facebookexternalhit|ahrefs|semrush|mj12bot|dotbot|petalbot|bytespider)/i', $userAgent) === 1;
}

function counterResponse(int $status, ?int $visits = null): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($visits !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['visits' => $visits], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleCounterPost(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        counterResponse(405);
    }

    $userAgent = requestUserAgent();
    if ($userAgent === '' || isCrawler($userAgent)) {
        counterResponse(204);
    }

    try {
        $ip = clientIp();
        $secret = counterSecret();
        $pdo = db();
        $now = time();
        $ipHash = hash_hmac('sha256', 'rate|' . $ip, $secret);
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

        try {
            $rateLimit = $pdo->prepare('INSERT INTO counter_rate_limits (ip_hash, window_started, requests)
                VALUES (:ip_hash, :request_time, 1)
                ON CONFLICT(ip_hash) DO UPDATE SET
                    window_started = CASE WHEN counter_rate_limits.window_started <= :cutoff THEN :reset_time ELSE counter_rate_limits.window_started END,
                    requests = CASE WHEN counter_rate_limits.window_started <= :request_cutoff THEN 1 ELSE counter_rate_limits.requests + 1 END');
            $rateLimit->execute([
                ':ip_hash' => $ipHash,
                ':request_time' => $now,
                ':cutoff' => $now - COUNTER_RATE_WINDOW,
                ':reset_time' => $now,
                ':request_cutoff' => $now - COUNTER_RATE_WINDOW,
            ]);

            $rateQuery = $pdo->prepare('SELECT window_started, requests FROM counter_rate_limits WHERE ip_hash = :ip_hash');
            $rateQuery->execute([':ip_hash' => $ipHash]);
            [$windowStarted, $requests] = $rateQuery->fetch(PDO::FETCH_NUM);
            if ((int) $requests > COUNTER_RATE_LIMIT) {
                $pdo->exec('COMMIT');
                header('Retry-After: ' . max(1, (int) $windowStarted + COUNTER_RATE_WINDOW - $now));
                counterResponse(429);
            }

            $token = $_POST['token'] ?? '';
            if (!is_string($token) || !validCounterToken($token, $ip, $userAgent, $secret, $now)) {
                $pdo->exec('COMMIT');
                counterResponse(403);
            }

            $visitorHash = hash_hmac('sha256', $ip . '|' . $userAgent, $secret);
            $visitor = $pdo->prepare('INSERT INTO counter_visitors (visitor_hash, last_seen)
                VALUES (:visitor_hash, :last_seen)
                ON CONFLICT(visitor_hash) DO UPDATE SET last_seen = excluded.last_seen
                WHERE counter_visitors.last_seen <= :cutoff');
            $visitor->execute([
                ':visitor_hash' => $visitorHash,
                ':last_seen' => $now,
                ':cutoff' => $now - VISIT_WINDOW,
            ]);

            if ($visitor->rowCount() > 0) {
                $pdo->exec('UPDATE stats SET visits = visits + 1 WHERE id = 1');
            }

            if (random_int(1, 100) === 1) {
                $cleanup = $pdo->prepare('DELETE FROM counter_visitors WHERE last_seen <= :visitor_cutoff');
                $cleanup->execute([':visitor_cutoff' => $now - VISIT_WINDOW]);
                $cleanup = $pdo->prepare('DELETE FROM counter_rate_limits WHERE window_started <= :rate_cutoff');
                $cleanup->execute([':rate_cutoff' => $now - 86400]);
            }

            $visits = visitorCount();
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    } catch (Throwable $exception) {
        error_log('Counter endpoint failed: ' . $exception->getMessage());
        counterResponse(503);
    }

    counterResponse(200, $visits);
}

function visitorCount(): int
{
    return (int) db()->query('SELECT visits FROM stats WHERE id = 1')->fetchColumn();
}

function page(string $name, ?string $shareUrl = null): never
{
    $count = visitorCount();
    $ip = clientIp();
    $userAgent = requestUserAgent();
    $counterToken = createCounterToken($ip, $userAgent);
    $greeting = $name !== '' ? 'Hej ' . ucfirst($name) . '!' : 'Hvem er dagens fedesvin?';
    $message = $name !== '' ? 'En eller anden synes åbenbart, at du er et fedt svin.' : 'Skriv et navn. Send linket.';
    $shareUrl ??= $name === '' ? '' : 'https://' . slugify($name) . '.' . DOMAIN;
    http_response_code(200);
    header('Cache-Control: no-store, private');
    ?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5c84c">
  <title><?= e($name === '' ? 'Fedesvin.dk' : 'Hej ' . $name . ' — Fedesvin.dk') ?></title>
  <meta name="description" content="<?= e($message) ?>">
  <meta name="counter-token" content="<?= e($counterToken) ?>">
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
</head>
<body>
  <main class="page-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><span class="top-note">Et kompliment. På en måde.</span></header>
    <section class="card <?= $name === '' ? 'card-home' : 'card-result' ?>">
      <div class="sticker" aria-hidden="true">✳</div>
      <h1><?= e($greeting) ?></h1>
      <p class="message"><?= e($message) ?></p>
      <div class="mascot" aria-hidden="true"><span class="ear ear-left"></span><span class="ear ear-right"></span><span class="eyes"><i></i><i></i></span><span class="snout"><i></i><i></i></span><span class="smile"></span><span class="cheek cheek-left"></span><span class="cheek cheek-right"></span></div>
      <?php if ($name === ''): ?>
      <form class="name-form" action="/shortlink" method="post">
        <label for="name">Hvem skal have æren?</label>
        <div class="input-row"><input id="name" name="name" maxlength="40" autocomplete="off" placeholder="Fx Morten" required><button type="submit">Lav et link <span aria-hidden="true">↗</span></button></div>
      </form>
      <?php else: ?>
      <div class="share-box"><span class="share-label">DEL JOKEN</span><div class="share-row"><a href="<?= e($shareUrl) ?>"><?= e(str_replace('https://', '', $shareUrl)) ?></a><button type="button" class="copy-button" data-copy="<?= e($shareUrl) ?>">Kopiér link</button></div><p class="copy-status" aria-live="polite"></p></div>
      <a class="again" href="https://<?= DOMAIN ?>">Lav et til <span aria-hidden="true">↗</span></a>
      <?php endif; ?>
    </section>
    <footer><span>Gammeldags internet. Nye svin.</span><span><span data-visit-count><?= number_format($count, 0, ',', '.') ?></span> kærlige besøg</span><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
  </main>
  <script src="/app.js" defer></script>
</body>
</html>
    <?php
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/counter') {
    handleCounterPost();
}

if ($path === '/health') {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        db()->query('SELECT 1');
        echo 'ok';
    } catch (Throwable) {
        http_response_code(503);
        echo 'unavailable';
    }
    exit;
}

if ($path === '/shortlink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = normalizeName((string) ($_POST['name'] ?? ''));
    if ($name === '' || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        http_response_code(400);
        page('');
    }
    $pdo = db();
    do {
        $code = bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO shortlinks (code, name) VALUES (:code, :name)');
        $stmt->execute([':code' => $code, ':name' => $name]);
    } while ($stmt->rowCount() === 0);
    header('Location: /s/' . rawurlencode($code), true, 303);
    exit;
}

if (preg_match('~^/s/([a-z0-9-]{1,50})$~', $path, $match)) {
    $stmt = db()->prepare('SELECT name FROM shortlinks WHERE code = :code');
    $stmt->execute([':code' => $match[1]]);
    $name = $stmt->fetchColumn();
    if ($name === false) {
        http_response_code(404);
        page('');
    }
    header('Location: https://' . slugify((string) $name) . '.' . DOMAIN . '/?s=' . rawurlencode($match[1]), true, 302);
    exit;
}

$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? DOMAIN)[0]);
$suffix = '.' . DOMAIN;
if ($host !== DOMAIN && str_ends_with($host, $suffix)) {
    $label = substr($host, 0, -strlen($suffix));
    if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
        $shareUrl = null;
        $code = (string) ($_GET['s'] ?? '');
        if ($code !== '') {
            $stmt = db()->prepare('SELECT name FROM shortlinks WHERE code = :code');
            $stmt->execute([':code' => $code]);
            $linkName = $stmt->fetchColumn();
            if ($linkName !== false && slugify((string) $linkName) === $label) {
                $shareUrl = 'https://' . DOMAIN . '/s/' . rawurlencode($code);
            }
        }
        page(normalizeName(str_replace('-', ' ', $label)), $shareUrl);
    }
}
page('');
