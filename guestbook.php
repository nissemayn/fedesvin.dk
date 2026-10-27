<?php
declare(strict_types=1);

if (!isset($path) || !function_exists('db')) {
    http_response_code(404);
    exit;
}

function guestbookRedirect(string $status): never
{
    header('Location: /gaestebog?status=' . rawurlencode($status) . '#indlaeg', true, 303);
    exit;
}

function guestbookAdminCredentials(): array
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

function guestbookRequireAdmin(): void
{
    $expectedPassword = getenv('GUESTBOOK_ADMIN_PASSWORD');
    if (!is_string($expectedPassword) || strlen($expectedPassword) < 8) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Administration kræver GUESTBOOK_ADMIN_PASSWORD på mindst 8 tegn i Portainer.';
        exit;
    }

    [$username, $password] = guestbookAdminCredentials();
    $expectedUsername = getenv('GUESTBOOK_ADMIN_USER') ?: 'admin';
    if (!hash_equals($expectedUsername, $username) || !hash_equals($expectedPassword, $password)) {
        header('WWW-Authenticate: Basic realm="Fedesvin gæstebog", charset="UTF-8"');
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

function guestbookDate(int $timestamp): string
{
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Copenhagen'));
    $months = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
    return $date->format('j') . '. ' . $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y') . ' kl. ' . $date->format('H:i');
}

$adminPage = $path === '/gaestebog/admin';
if ($adminPage) {
    guestbookRequireAdmin();
    $pdo = db();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $submittedToken = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
        if (!hash_equals((string) $_SESSION['guestbook_csrf'], $submittedToken)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Formularen er udløbet. Genindlæs siden og prøv igen.';
            exit;
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (($_POST['action'] ?? '') === 'delete' && $id !== false) {
            $delete = $pdo->prepare('DELETE FROM guestbook_entries WHERE id = :id');
            $delete->execute([':id' => $id]);
        } elseif (($_POST['action'] ?? '') === 'update' && $id !== false) {
            $author = normalizeText(is_string($_POST['author'] ?? null) ? $_POST['author'] : '', 32);
            $message = normalizeText(is_string($_POST['message'] ?? null) ? $_POST['message'] : '', 400);
            if ($message === '') {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Beskeden må ikke være tom.';
                exit;
            }
            $update = $pdo->prepare('UPDATE guestbook_entries SET author = :author, message = :message WHERE id = :id');
            $update->execute([':author' => $author !== '' ? $author : 'Anonymt svin', ':message' => $message, ':id' => $id]);
        }
        header('Location: /gaestebog/admin', true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET, POST');
        http_response_code(405);
        exit;
    }
    $entries = $pdo->query('SELECT id, author, message, created_at FROM guestbook_entries ORDER BY created_at DESC, id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    $csrf = (string) $_SESSION['guestbook_csrf'];
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Moderér gæstebog — Fedesvin.dk</title>
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
  <link rel="stylesheet" href="/guestbook.css?v=<?= filemtime(__DIR__ . '/guestbook.css') ?>">
</head>
<body>
  <main class="page-shell guestbook-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card guestbook-card">
      <p class="eyebrow">KUN FOR SVINEPASSEREN</p>
      <h1>Moderér gæstebogen.</h1>
      <p class="message">Ret navne og beskeder — eller slet indlæg, der har fået for meget sovs på sig.</p>
      <p class="guestbook-admin-back"><a href="/gaestebog">Tilbage til gæstebogen ↗</a></p>
      <div class="guestbook-entries guestbook-admin-entries">
        <?php if ($entries === []): ?>
        <p>Gæstebogen er tom. Grisen venter i spænding.</p>
        <?php else: foreach ($entries as $entry): ?>
        <article class="guestbook-entry">
          <header><strong><?= e($entry['author']) ?></strong><time><?= e(guestbookDate((int) $entry['created_at'])) ?></time></header>
          <form class="guestbook-admin-edit" method="post" action="/gaestebog/admin">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
            <input type="hidden" name="action" value="update">
            <label for="author-<?= (int) $entry['id'] ?>">Navn</label>
            <input id="author-<?= (int) $entry['id'] ?>" name="author" maxlength="32" value="<?= e($entry['author']) ?>" required>
            <label for="message-<?= (int) $entry['id'] ?>">Besked</label>
            <textarea id="message-<?= (int) $entry['id'] ?>" name="message" maxlength="400" rows="3" required><?= e($entry['message']) ?></textarea>
            <button type="submit">Gem ændringer</button>
          </form>
          <form class="guestbook-admin-delete-form" method="post" action="/gaestebog/admin" data-delete-confirm>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="guestbook-delete">Slet indlæg</button>
          </form>
        </article>
        <?php endforeach; endif; ?>
      </div>
    </section>
    <dialog class="guestbook-delete-dialog" data-delete-dialog aria-labelledby="delete-dialog-title" aria-describedby="delete-dialog-description">
      <form method="dialog">
        <h2 id="delete-dialog-title">Slette indlægget?</h2>
        <p id="delete-dialog-description">Det kan ikke fortrydes.</p>
        <div><button value="cancel">Behold indlæg</button><button value="confirm" class="guestbook-delete">Slet indlæg</button></div>
      </form>
    </dialog>
    <footer><a href="/gaestebog">Gæstebogen ↗</a><a href="https://<?= DOMAIN ?>">Tilbage til svinestien ↗</a></footer>
    <p class="geo-badge" aria-label="Bedst set med Netscape og en kærlig gris"><span>BEDST SET MED</span><strong>NETSCAPE 4.0</strong><span>SVINEUDGAVEN • 2003</span></p>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
    <?php
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if (!validGuestbookToken($token)) {
        guestbookRedirect('expired');
    }
    if (($_POST['js_check'] ?? '') !== 'ready') {
        guestbookRedirect('browser-check');
    }
    $tokenIssuedAt = (int) explode('.', $token, 2)[0];
    if (time() - $tokenIssuedAt < 2) {
        guestbookRedirect('too-fast');
    }
    if (is_string($_POST['company'] ?? null) && $_POST['company'] !== '') {
        guestbookRedirect('posted');
    }

    $pdo = db();
    $now = time();
    $ipHash = hash_hmac('sha256', 'guestbook-rate|' . clientIp(), counterSecret());
    $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $cleanup = $pdo->prepare('DELETE FROM guestbook_rate_limits WHERE window_started <= :cutoff');
        $cleanup->execute([':cutoff' => $now - 86400]);
        $rate = $pdo->prepare('INSERT INTO guestbook_rate_limits (ip_hash, window_started, requests) VALUES (:ip_hash, :started, 1)
            ON CONFLICT(ip_hash) DO UPDATE SET
                window_started = CASE WHEN guestbook_rate_limits.window_started <= :cutoff THEN :reset_time ELSE guestbook_rate_limits.window_started END,
                requests = CASE WHEN guestbook_rate_limits.window_started <= :request_cutoff THEN 1 ELSE guestbook_rate_limits.requests + 1 END');
        $rate->execute([':ip_hash' => $ipHash, ':started' => $now, ':cutoff' => $now - 3600, ':reset_time' => $now, ':request_cutoff' => $now - 3600]);
        $rateQuery = $pdo->prepare('SELECT requests FROM guestbook_rate_limits WHERE ip_hash = :ip_hash');
        $rateQuery->execute([':ip_hash' => $ipHash]);
        $requests = (int) $rateQuery->fetchColumn();
        if ($requests > 3) {
            $pdo->exec('COMMIT');
            guestbookRedirect('slow-down');
        }

        $author = normalizeText(is_string($_POST['author'] ?? null) ? $_POST['author'] : '', 32);
        $message = normalizeText(is_string($_POST['message'] ?? null) ? $_POST['message'] : '', 400);
        if ($message === '') {
            $pdo->exec('COMMIT');
            guestbookRedirect('empty');
        }
        $insert = $pdo->prepare('INSERT INTO guestbook_entries (author, message, created_at) VALUES (:author, :message, :created_at)');
        $insert->execute([':author' => $author !== '' ? $author : 'Anonymt svin', ':message' => $message, ':created_at' => $now]);
        $pdo->exec('COMMIT');
    } catch (Throwable $exception) {
        $pdo->exec('ROLLBACK');
        error_log('Guestbook submission failed: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gæstebogen kunne ikke gemme indlægget lige nu.';
        exit;
    }
    guestbookRedirect('posted');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$pdo = db();
$entries = $pdo->query('SELECT author, message, created_at FROM guestbook_entries ORDER BY created_at DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$count = visitorCount();
$counterToken = createCounterToken(clientIp(), requestUserAgent());
$guestbookToken = createGuestbookToken();
$notice = match ($_GET['status'] ?? '') {
    'posted' => 'Din hilsen er kommet i bogen. Øf tak!',
    'slow-down' => 'Rolig, lille gris — prøv igen om en times tid.',
    'empty' => 'Skriv lige en hilsen først.',
    'expired' => 'Formularen nåede at blive for gammel. Prøv igen.',
    'browser-check' => 'Formularen kunne ikke bekræfte, at JavaScript kører. Genindlæs siden og prøv igen.',
    'too-fast' => 'Det gik stærkt! Vent et øjeblik, og prøv igen.',
    default => '',
};
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="counter-token" content="<?= e($counterToken) ?>">
  <meta name="theme-color" content="#f5c84c">
  <title>Gæstebog — Fedesvin.dk</title>
  <meta name="description" content="Skriv en hilsen i Fedesvin.dk's gæstebog. Gammeldags internet. Nye svin.">
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
  <link rel="stylesheet" href="/guestbook.css?v=<?= filemtime(__DIR__ . '/guestbook.css') ?>">
</head>
<body>
  <main class="page-shell guestbook-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card guestbook-card" aria-labelledby="guestbook-title">
      <div class="sticker" aria-hidden="true">✳</div>
      <p class="eyebrow">DEN DIGITALE SVINESTI</p>
      <h1 id="guestbook-title">Gæstebogen.</h1>
      <p class="message">Efterlad en hilsen, en dårlig joke eller en kærlig fornærmelse. Som i gamle dage — bare med lidt mere flæsk.</p>
      <form class="guestbook-form" action="/gaestebog" method="post">
        <input type="hidden" name="token" value="<?= e($guestbookToken) ?>">
        <input type="hidden" name="js_check" value="">
        <div class="guestbook-honeypot" aria-hidden="true"><label for="company">Lad dette felt være tomt</label><input id="company" name="company" tabindex="-1" autocomplete="off"></div>
        <label for="author">Dit navn <span>(valgfrit)</span></label>
        <input id="author" name="author" maxlength="32" autocomplete="nickname" placeholder="Anonymt svin">
        <label for="guestbook-message">Din hilsen</label>
        <textarea id="guestbook-message" name="message" maxlength="400" rows="4" required placeholder="Øf øf, verden!"></textarea>
        <p class="guestbook-privacy-note">Dit navn og din hilsen bliver synlige for alle. <a href="/privatliv">Læs om privatliv og sletning</a>.</p>
        <button type="submit">Skriv i gæstebogen <span aria-hidden="true">✎</span></button>
        <p class="guestbook-notice" aria-live="polite"><?= e($notice) ?></p>
      </form>
      <div class="guestbook-heading" id="indlaeg"><h2>Hilsner fra svinestien</h2><span><?= number_format(count($entries), 0, ',', '.') ?> indlæg</span></div>
      <div class="guestbook-entries">
        <?php foreach ($entries as $entry): ?>
        <article class="guestbook-entry">
          <header><strong><?= e($entry['author']) ?></strong><time><?= e(guestbookDate((int) $entry['created_at'])) ?></time></header>
          <p><?= e($entry['message']) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
      <p class="guestbook-admin-link"><a href="/gaestebog/admin">Moderér gæstebogen</a></p>
    </section>
    <footer><span>Gammeldags internet. Nye svin. · <a href="https://<?= DOMAIN ?>/stats">Statistik</a> · <a href="https://<?= DOMAIN ?>/privatliv">Privatliv</a></span><span><span data-visit-count><?= number_format($count, 0, ',', '.') ?></span> kærlige besøg</span><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
    <p class="geo-badge" aria-label="Bedst set med Netscape og en kærlig gris"><span>BEDST SET MED</span><strong>NETSCAPE 4.0</strong><span>SVINEUDGAVEN • 2003</span></p>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
