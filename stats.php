<?php
declare(strict_types=1);

// This template is loaded through index.php, which owns the database helpers.
if (!function_exists('db')) {
    http_response_code(404);
    exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $total = visitorCount();
    $summary = $pdo->query('SELECT COUNT(*) AS domains, COALESCE(SUM(visits), 0) AS visits, MIN(first_counted_at) AS started_at FROM subdomain_stats')->fetch(PDO::FETCH_ASSOC);
    $shortlinks = (int) $pdo->query('SELECT COUNT(*) FROM shortlinks')->fetchColumn();
    $leaders = $pdo->query('SELECT subdomain, visits FROM subdomain_stats ORDER BY visits DESC, subdomain ASC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
$format = static fn (int $value): string => number_format($value, 0, ',', '.');
$maximum = $leaders === [] ? 1 : max(1, (int) $leaders[0]['visits']);
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5c84c">
  <title>Svin på toppen — Fedesvin.dk</title>
  <meta name="description" content="De 20 mest besøgte subdomæner på Fedesvin.dk. En lille svinehitliste med store ambitioner.">
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
  <link rel="stylesheet" href="/stats.css">
</head>
<body>
  <main class="page-shell stats-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card stats-card" aria-labelledby="stats-title">
      <div class="sticker" aria-hidden="true">✳</div>
      <p class="eyebrow">DEN STORE SVINEHITLISTE</p>
      <h1 id="stats-title">Svin på toppen.</h1>
      <p class="message">Nogle svin får bare mere kærlighed end andre.</p>
      <dl class="stats-metrics">
        <div><dt>Kærlige besøg i alt</dt><dd><?= $format($total) ?></dd></div>
        <div><dt>Besøg på subdomæner</dt><dd><?= $format((int) $summary['visits']) ?></dd></div>
        <div><dt>Subdomæner besøgt</dt><dd><?= $format((int) $summary['domains']) ?></dd></div>
        <div><dt>Kortlinks oprettet</dt><dd><?= $format($shortlinks) ?></dd></div>
      </dl>
      <div class="stats-heading"><h2>Top 20</h2><span>Rangeret efter besøg</span></div>
      <?php if ($leaders === []): ?>
      <div class="stats-empty"><p>Hitlisten venter på sit første svin.</p><p>Send et link til en ven. Et synligt besøg på mindst tre sekunder kan starte festen.</p></div>
      <?php else: ?>
      <ol class="stats-ranking">
        <?php foreach ($leaders as $index => $leader): ?>
        <li>
          <span class="stats-rank" aria-label="Nummer <?= $index + 1 ?>"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <div class="stats-domain"><a href="https://<?= e($leader['subdomain']) ?>.<?= DOMAIN ?>"><?= e(ucfirst($leader['subdomain'])) ?><span>.<?= DOMAIN ?></span></a><div class="stats-track" aria-hidden="true"><div style="width:<?= round((int) $leader['visits'] / $maximum * 100, 2) ?>%"></div></div></div>
          <span class="stats-visits"><?= $format((int) $leader['visits']) ?><small>besøg</small></span>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <aside class="stats-explainer" aria-label="Sådan tæller vi">
        <h2>Et lille gran salt. Og flæsk.</h2>
        <p>Et besøg tæller efter tre sekunder med siden synlig. Samme besøgende tæller højst én gang pr. subdomæne hver otte timer. Kendte bots filtreres fra.</p>
        <p>Totalen tæller samme besøgende én gang på tværs af hele siden hver otte timer, så tallene skal ikke nødvendigvis gå op. Hitlisten og subdomænetallene starter med denne opdatering<?= $summary['started_at'] !== null ? ' (første registrering ' . gmdate('d.m.Y', (int) $summary['started_at']) . ')' : '' ?>; gamle besøg findes kun i totalen.</p>
        <p>Kun subdomæner og samlede tal vises offentligt. Besøg på selve statistiksiden tælles ikke. Tallene opdateres, når du genindlæser siden.</p>
      </aside>
    </section>
    <footer><span>Gammeldags internet. Nye svin.</span><a href="https://<?= DOMAIN ?>">Tilbage til svinestien ↗</a><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
    <p class="retro-badges" aria-hidden="true"><span>BEST VIEWED IN IE 6</span><span>UNDER CONSTRUCTION</span><span>100% SVIN • 0% FLASH</span></p>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
