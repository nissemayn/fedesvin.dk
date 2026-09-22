<?php
declare(strict_types=1);

const COOKIE_TTL = 28800;
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

function issueVisitCookie(): void
{
    setcookie('fedesvin_seen_v2', (string) time(), [
        'expires' => time() + COOKIE_TTL,
        'path' => '/',
        'domain' => DOMAIN,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function countVisit(): void
{
    $seenAt = filter_var($_COOKIE['fedesvin_seen_v2'] ?? null, FILTER_VALIDATE_INT);
    if ($seenAt !== false && $seenAt !== null && time() - $seenAt < COOKIE_TTL && time() >= $seenAt) {
        return;
    }
    db()->exec('UPDATE stats SET visits = visits + 1 WHERE id = 1');
    issueVisitCookie();
}

function visitorCount(): int
{
    return (int) db()->query('SELECT visits FROM stats WHERE id = 1')->fetchColumn();
}

function page(string $name, ?string $shareUrl = null): never
{
    countVisit();
    $count = visitorCount();
    $greeting = $name !== '' ? 'Hej ' . ucfirst($name) . '!' : 'Hvem er dagens fedesvin?';
    $message = $name !== '' ? 'En eller anden synes åbenbart, at du er et fedt svin.' : 'Skriv et navn. Send linket.';
    $shareUrl ??= $name === '' ? '' : 'https://' . slugify($name) . '.' . DOMAIN;
    http_response_code(200);
    ?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5c84c">
  <title><?= e($name === '' ? 'Fedesvin.dk' : 'Hej ' . $name . ' — Fedesvin.dk') ?></title>
  <meta name="description" content="<?= e($message) ?>">
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
    <footer><span>Gammeldags internet. Nye svin.</span><span><?= number_format($count, 0, ',', '.') ?> kærlige besøg</span><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
  </main>
  <script src="/app.js" defer></script>
</body>
</html>
    <?php
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
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
