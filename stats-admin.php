<?php
declare(strict_types=1);

if (!isset($path) || !function_exists('db')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/admin_auth.php';
requireSiteAdmin();

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $submittedToken = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!hash_equals((string) $_SESSION['guestbook_csrf'], $submittedToken)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Formularen er udløbet. Genindlæs siden og prøv igen.';
        exit;
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $subdomain = is_string($_POST['subdomain'] ?? null) ? strtolower($_POST['subdomain']) : '';
    if (in_array($action, ['hide-subdomain', 'show-subdomain'], true) && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain)) {
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            if ($action === 'hide-subdomain') {
                $hide = $pdo->prepare('INSERT INTO hidden_subdomains (subdomain, hidden_at) VALUES (:subdomain, :hidden_at) ON CONFLICT(subdomain) DO NOTHING');
                $hide->execute([':subdomain' => $subdomain, ':hidden_at' => time()]);
                $removeStats = $pdo->prepare('DELETE FROM subdomain_stats WHERE subdomain = :subdomain');
                $removeStats->execute([':subdomain' => $subdomain]);
                $removeVisitors = $pdo->prepare('DELETE FROM counter_subdomain_visitors WHERE subdomain = :subdomain');
                $removeVisitors->execute([':subdomain' => $subdomain]);
            } else {
                $show = $pdo->prepare('DELETE FROM hidden_subdomains WHERE subdomain = :subdomain');
                $show->execute([':subdomain' => $subdomain]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }
    header('Location: /stats/admin', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$subdomains = $pdo->query('SELECT subdomain, visits FROM subdomain_stats ORDER BY visits DESC, subdomain ASC')->fetchAll(PDO::FETCH_ASSOC);
$hiddenSubdomains = $pdo->query('SELECT subdomain, hidden_at FROM hidden_subdomains ORDER BY hidden_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = (string) $_SESSION['guestbook_csrf'];
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5c84c">
  <title>Moderér hitlisten — Fedesvin.dk</title>
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
  <link rel="stylesheet" href="/stats-admin.css">
</head>
<body>
  <main class="page-shell stats-admin-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card stats-admin-card" aria-labelledby="stats-admin-title">
      <p class="eyebrow">KUN FOR SVINEPASSEREN</p>
      <h1 id="stats-admin-title">Moderér hitlisten.</h1>
      <p class="message">Skjul subdomæner, der ikke skal stå på den offentlige hitliste.</p>
      <section class="subdomain-moderation" aria-labelledby="visible-subdomains-title">
        <h2 id="visible-subdomains-title">Synlige subdomæner</h2>
        <p>Skjulning sletter subdomænets hitlistedata og gemte besøgshashes. Besøg tælles ikke for subdomænet, før du viser det igen; så starter historikken fra nul.</p>
        <?php if ($subdomains === []): ?><p>Ingen synlige subdomæner på hitlisten.</p><?php else: ?>
        <ul>
          <?php foreach ($subdomains as $subdomain): ?>
          <li><span><strong><?= e(ucfirst($subdomain['subdomain'])) ?>.<?= DOMAIN ?></strong><small><?= number_format((int) $subdomain['visits'], 0, ',', '.') ?> besøg</small></span><form method="post" action="/stats/admin" data-delete-confirm data-confirm-title="Skjule subdomænet?" data-confirm-description="Hitlistedata og gemte besøgshashes slettes. Hvis du viser det igen, starter historikken fra nul." data-confirm-cancel="Behold subdomænet" data-confirm-submit="Skjul subdomæne"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="hide-subdomain"><input type="hidden" name="subdomain" value="<?= e($subdomain['subdomain']) ?>"><button type="submit">Skjul fra hitlisten</button></form></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>
      <?php if ($hiddenSubdomains !== []): ?>
      <section class="subdomain-moderation hidden-subdomain-list" aria-labelledby="hidden-subdomains-title">
        <h2 id="hidden-subdomains-title">Skjulte subdomæner</h2>
        <ul>
          <?php foreach ($hiddenSubdomains as $subdomain): ?>
          <li><span><strong><?= e(ucfirst($subdomain['subdomain'])) ?>.<?= DOMAIN ?></strong><small>Skjult <?= gmdate('d.m.Y H:i', (int) $subdomain['hidden_at']) ?> UTC</small></span><form class="subdomain-moderation-restore" method="post" action="/stats/admin"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="show-subdomain"><input type="hidden" name="subdomain" value="<?= e($subdomain['subdomain']) ?>"><button type="submit">Vis igen</button></form></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
    </section>
    <dialog class="stats-admin-dialog" data-delete-dialog aria-labelledby="delete-dialog-title" aria-describedby="delete-dialog-description">
      <form method="dialog">
        <h2 id="delete-dialog-title">Skjule subdomænet?</h2>
        <p id="delete-dialog-description">Hitlistedata og gemte besøgshashes slettes.</p>
        <div><button value="cancel" data-dialog-cancel>Behold subdomænet</button><button value="confirm" data-dialog-confirm class="stats-admin-confirm">Skjul subdomæne</button></div>
      </form>
    </dialog>
    <footer><span><a href="https://<?= DOMAIN ?>/stats">Offentlig statistik</a> · <a href="https://<?= DOMAIN ?>/gaestebog/admin">Gæstebogsmoderation</a></span><a href="https://<?= DOMAIN ?>">Tilbage til svinestien ↗</a></footer>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
