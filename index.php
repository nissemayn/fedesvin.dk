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
    $columns = $pdo->query('PRAGMA table_info(shortlinks)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('message', $columns, true) || !in_array('pig_variant', $columns, true)) {
        // Another request may migrate the same existing volume at the same time.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $columns = $pdo->query('PRAGMA table_info(shortlinks)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('message', $columns, true)) {
                $pdo->exec("ALTER TABLE shortlinks ADD COLUMN message TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('pig_variant', $columns, true)) {
                $pdo->exec("ALTER TABLE shortlinks ADD COLUMN pig_variant TEXT NOT NULL DEFAULT 'classic'");
                $variantCases = [];
                foreach (pigVariants() as $index => $variant) {
                    $variantCases[] = "WHEN {$index} THEN '{$variant}'";
                }
                $pdo->exec('UPDATE shortlinks SET pig_variant = CASE abs(random() % ' . count($variantCases) . ') ' . implode(' ', $variantCases) . ' END');
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS counter_visitors (visitor_hash TEXT PRIMARY KEY, last_seen INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS counter_rate_limits (ip_hash TEXT PRIMARY KEY, window_started INTEGER NOT NULL, requests INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subdomain_stats (subdomain TEXT PRIMARY KEY, visits INTEGER NOT NULL DEFAULT 0, first_counted_at INTEGER NOT NULL, last_counted_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS counter_subdomain_visitors (subdomain TEXT NOT NULL, visitor_hash TEXT NOT NULL, last_seen INTEGER NOT NULL, PRIMARY KEY (subdomain, visitor_hash))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS guestbook_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, author TEXT NOT NULL, message TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS guestbook_rate_limits (ip_hash TEXT PRIMARY KEY, window_started INTEGER NOT NULL, requests INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS guestbook_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    if (!$pdo->query("SELECT 1 FROM guestbook_meta WHERE key = 'welcome_seeded'")->fetchColumn()) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $seedMarker = $pdo->prepare("INSERT OR IGNORE INTO guestbook_meta (key, value) VALUES ('welcome_seeded', '1')");
            $seedMarker->execute();
            if ($seedMarker->rowCount() === 1) {
                $welcome = $pdo->prepare('INSERT OR IGNORE INTO guestbook_entries (id, author, message, created_at) VALUES (1, :author, :message, :created_at)');
                $welcome->execute([':author' => 'Codex', ':message' => 'Første øf i gæstebogen! Den er åben, Netscape er installeret, og ingen har spildt sovs på tastaturet endnu.', ':created_at' => time()]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }
    return $pdo;
}

function requestSubdomain(): ?string
{
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? DOMAIN)[0]);
    $suffix = '.' . DOMAIN;
    if (!str_ends_with($host, $suffix)) {
        return null;
    }
    $label = substr($host, 0, -strlen($suffix));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) ? $label : null;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeName(string $value): string
{
    return normalizeText($value, 40);
}

function normalizeText(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return implode('', array_slice($characters ?: [], 0, $limit));
}

function displayName(string $name): string
{
    return ucfirst(preg_replace_callback('/^[æøå]/u', static fn (array $match): string => strtr($match[0], ['æ' => 'Æ', 'ø' => 'Ø', 'å' => 'Å']), $name) ?? $name);
}

function pigVariants(): array
{
    return ['classic', 'rose', 'mint', 'business', 'party', 'royal', 'goth', 'pirate', 'space'];
}

function shortlink(string $code): ?array
{
    if (!preg_match('/^[a-z0-9-]{1,50}$/', $code)) {
        return null;
    }
    $stmt = db()->prepare('SELECT name, message, pig_variant FROM shortlinks WHERE code = :code');
    $stmt->execute([':code' => $code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function slugify(string $value): string
{
    $value = strtr($value, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa']);
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
    $signature = hash_hmac('sha256', $payload . '|' . $ip . '|' . $userAgent . '|' . (requestSubdomain() ?? ''), counterSecret());
    return $payload . '.' . $signature;
}

function createGuestbookToken(): string
{
    $payload = time() . '.' . bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', 'guestbook|' . $payload . '|' . clientIp() . '|' . requestUserAgent(), counterSecret());
    return $payload . '.' . $signature;
}

function validGuestbookToken(string $token): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', $token, $parts)) {
        return false;
    }
    $issuedAt = (int) $parts[1];
    $now = time();
    if ($issuedAt > $now || $now - $issuedAt > 1800) {
        return false;
    }
    $payload = $parts[1] . '.' . $parts[2];
    $expected = hash_hmac('sha256', 'guestbook|' . $payload . '|' . clientIp() . '|' . requestUserAgent(), counterSecret());
    return hash_equals($expected, $parts[3]);
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
    $expected = hash_hmac('sha256', $payload . '|' . $ip . '|' . $userAgent . '|' . (requestSubdomain() ?? ''), $secret);
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

            $subdomain = requestSubdomain();
            if ($subdomain !== null) {
                $visitor = $pdo->prepare('INSERT INTO counter_subdomain_visitors (subdomain, visitor_hash, last_seen)
                    VALUES (:subdomain, :visitor_hash, :last_seen)
                    ON CONFLICT(subdomain, visitor_hash) DO UPDATE SET last_seen = excluded.last_seen
                    WHERE counter_subdomain_visitors.last_seen <= :cutoff');
                $visitor->execute([
                    ':subdomain' => $subdomain,
                    ':visitor_hash' => $visitorHash,
                    ':last_seen' => $now,
                    ':cutoff' => $now - VISIT_WINDOW,
                ]);
                if ($visitor->rowCount() > 0) {
                    $subdomainStats = $pdo->prepare('INSERT INTO subdomain_stats (subdomain, visits, first_counted_at, last_counted_at)
                        VALUES (:subdomain, 1, :first_counted_at, :last_counted_at)
                        ON CONFLICT(subdomain) DO UPDATE SET visits = subdomain_stats.visits + 1, last_counted_at = excluded.last_counted_at');
                    $subdomainStats->execute([':subdomain' => $subdomain, ':first_counted_at' => $now, ':last_counted_at' => $now]);
                }
            }

            if (random_int(1, 100) === 1) {
                $cleanup = $pdo->prepare('DELETE FROM counter_visitors WHERE last_seen <= :visitor_cutoff');
                $cleanup->execute([':visitor_cutoff' => $now - VISIT_WINDOW]);
                $cleanup = $pdo->prepare('DELETE FROM counter_subdomain_visitors WHERE last_seen <= :visitor_cutoff');
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

function page(string $name, ?string $shareUrl = null, string $personalMessage = '', ?string $code = null, ?string $pigVariant = null): never
{
    $count = visitorCount();
    $ip = clientIp();
    $userAgent = requestUserAgent();
    $counterToken = createCounterToken($ip, $userAgent);
    $name = displayName($name);
    $greeting = $name !== '' ? 'Hej ' . $name . '!' : 'Hvem er dagens fedesvin?';
    $message = $name !== '' ? 'En eller anden synes åbenbart, at du er et fedt svin.' : 'Skriv et navn. Send linket.';
    $shareUrl ??= $name === '' ? '' : 'https://' . slugify($name) . '.' . DOMAIN;
    $canonicalUrl = $shareUrl ?: 'https://' . DOMAIN . '/';
    $variant = $pigVariant !== null && in_array($pigVariant, pigVariants(), true) ? $pigVariant : pigVariants()[random_int(0, count(pigVariants()) - 1)];
    $imageParams = $code !== null ? ['code' => $code] : ($name !== '' ? ['name' => $name] : []);
    $imageParams['art'] = (string) filemtime(__DIR__ . '/share-image.php');
    $imageParams['v'] = $variant;
    $imageUrl = 'https://' . DOMAIN . '/share-image.png' . ($imageParams ? '?' . http_build_query($imageParams) : '');
    $downloadUrl = $imageUrl . (str_contains($imageUrl, '?') ? '&' : '?') . 'download=1&v=' . $variant;
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
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Fedesvin.dk">
  <meta property="og:locale" content="da_DK">
  <meta property="og:title" content="<?= e($greeting) ?>">
  <meta property="og:description" content="<?= e($personalMessage !== '' ? $personalMessage : $message) ?>">
  <meta property="og:url" content="<?= e($canonicalUrl) ?>">
  <meta property="og:image" content="<?= e($imageUrl) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="<?= e($greeting . ' ' . ($personalMessage ?: $message)) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($greeting) ?>">
  <meta name="twitter:description" content="<?= e($personalMessage !== '' ? $personalMessage : $message) ?>">
  <meta name="twitter:image" content="<?= e($imageUrl) ?>">
  <meta name="counter-token" content="<?= e($counterToken) ?>">
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
</head>
<body>
  <main class="page-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card <?= $name === '' ? 'card-home' : 'card-result' ?>">
      <div class="sticker" aria-hidden="true">✳</div>
      <div class="retro-banner"><div class="retro-window" aria-hidden="true"><span class="retro-track"><span>*** VELKOMMEN TIL MIN SVINEHJEMMESIDE *** INGEN KAGE ER SIKKER ***</span><span>*** VELKOMMEN TIL MIN SVINEHJEMMESIDE *** INGEN KAGE ER SIKKER ***</span></span></div><button class="marquee-toggle" type="button" aria-label="Start rulleteksten" aria-pressed="false" hidden>Rul tekst ►</button></div>
      <h1><?= e($greeting) ?></h1>
      <p class="message"><?= e($message) ?></p>
      <?php if ($personalMessage !== ''): ?><blockquote class="personal-message"><?= e($personalMessage) ?></blockquote><?php endif; ?>
      <div class="mascot-stage">
        <button type="button" class="mascot" data-pig="<?= e($variant) ?>" data-pig-fixed="<?= $code !== null ? 'true' : 'false' ?>" aria-label="Prik til grisen" title="Prik til grisen"><span aria-hidden="true"><span class="ear ear-left"></span><span class="ear ear-right"></span><span class="eyes"><i></i><i></i></span><span class="snout"><i></i><i></i></span><span class="smile"></span><span class="cheek cheek-left"></span><span class="cheek cheek-right"></span><span class="pig-accessory"></span></span></button>
        <p class="pig-caption" aria-live="polite"></p>
      </div>
      <?php if ($name === ''): ?>
      <form class="name-form" action="/shortlink" method="post">
        <label for="name">Hvem skal have æren?</label>
        <div class="input-row"><input id="name" name="name" maxlength="40" autocomplete="off" placeholder="Fx Morten" required><button type="submit">Lav et link <span aria-hidden="true">↗</span></button></div>
        <details class="message-option"><summary>Tilføj en personlig besked</summary><label for="personal-message">En kærlig hilsen <span>(valgfrit)</span></label><textarea id="personal-message" name="message" maxlength="160" rows="2" placeholder="Du tog det sidste kage igen, Morten."></textarea><p>Op til 160 tegn. Beskeden vises for alle, der har linket.</p></details>
      </form>
      <?php else: ?>
      <div class="share-box"><span class="share-label">DEL JOKEN</span><div class="share-row"><a href="<?= e($shareUrl) ?>"><?= e(str_replace('https://', '', $shareUrl)) ?></a><button type="button" class="copy-button" data-copy="<?= e($shareUrl) ?>">Kopiér link</button></div><p class="copy-status" aria-live="polite"></p></div>
      <a class="image-download" data-image-download href="<?= e($downloadUrl) ?>">Gem som billede ↓</a>
      <a class="again" href="https://<?= DOMAIN ?>">Lav et til <span aria-hidden="true">↗</span></a>
      <?php endif; ?>
    </section>
    <footer><span>Gammeldags internet. Nye svin. · <a href="https://<?= DOMAIN ?>/gaestebog">Gæstebog</a> · <a href="https://<?= DOMAIN ?>/stats">Statistik</a></span><span><span data-visit-count><?= number_format($count, 0, ',', '.') ?></span> kærlige besøg</span><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
    <p class="geo-badge" aria-label="Bedst set med Netscape og en kærlig gris"><span>BEDST SET MED</span><strong>NETSCAPE 4.0</strong><span>SVINEUDGAVEN • 2003</span></p>
    <p class="retro-badges" aria-hidden="true"><span>BEST VIEWED IN IE 6</span><span>UNDER CONSTRUCTION</span><span>100% SVIN • 0% FLASH</span></p>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
    <?php
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/share-image.png') {
    require __DIR__ . '/share-image.php';
    exit;
}
if ($path === '/stats' || $path === '/stats/') {
    require __DIR__ . '/stats.php';
    exit;
}
if ($path === '/gaestebog' || $path === '/gaestebog/' || $path === '/gaestebog/admin') {
    require __DIR__ . '/guestbook.php';
    exit;
}
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
    $name = normalizeName(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    $personalMessage = normalizeText(is_string($_POST['message'] ?? null) ? $_POST['message'] : '', 160);
    if ($name === '' || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        http_response_code(400);
        page('');
    }
    $pdo = db();
    do {
        $code = bin2hex(random_bytes(6));
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO shortlinks (code, name, message, pig_variant) VALUES (:code, :name, :message, :pig_variant)');
        $stmt->execute([':code' => $code, ':name' => $name, ':message' => $personalMessage, ':pig_variant' => pigVariants()[random_int(0, count(pigVariants()) - 1)]]);
    } while ($stmt->rowCount() === 0);
    header('Location: /s/' . rawurlencode($code), true, 303);
    exit;
}

if (preg_match('~^/s/([a-z0-9-]{1,50})$~', $path, $match)) {
    $link = shortlink($match[1]);
    if ($link === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Det link findes ikke. Tilbage til https://' . DOMAIN;
        exit;
    }
    header('Location: https://' . slugify($link['name']) . '.' . DOMAIN . '/p/' . rawurlencode($match[1]), true, 302);
    exit;
}

$label = requestSubdomain();
if ($label !== null) {
    $shareUrl = null;
    $personalMessage = '';
    $pigVariant = null;
    $name = normalizeName(str_replace('-', ' ', $label));
    $code = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
    if (preg_match('~^/p/([a-z0-9-]{1,50})$~', $path, $match)) {
        $code = $match[1];
    }
    if ($code !== '') {
        $link = shortlink($code);
        if ($link !== null && slugify($link['name']) === $label) {
            $shareUrl = 'https://' . DOMAIN . '/s/' . rawurlencode($code);
            $personalMessage = $link['message'];
            $name = $link['name'];
            $pigVariant = $link['pig_variant'];
        }
    }
    page($name, $shareUrl, $personalMessage, $shareUrl !== null ? $code : null, $pigVariant);
}
page('');
