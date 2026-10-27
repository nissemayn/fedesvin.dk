<?php
declare(strict_types=1);

if (!defined('DOMAIN')) {
    http_response_code(404);
    exit;
}

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5c84c">
  <title>Privatliv — Fedesvin.dk</title>
  <meta name="description" content="Kort information om data og privatliv på Fedesvin.dk.">
  <link rel="stylesheet" href="/style.css">
  <link rel="stylesheet" href="/details.css">
  <link rel="stylesheet" href="/extras.css?v=<?= filemtime(__DIR__ . '/extras.css') ?>">
  <link rel="stylesheet" href="/privacy.css">
</head>
<body>
  <main class="page-shell privacy-shell">
    <header class="topbar"><a class="wordmark" href="https://<?= DOMAIN ?>">FEDESVIN<span>.DK</span></a><button type="button" class="top-note retro-toggle" aria-pressed="false" hidden>Retro-mode: FRA</button></header>
    <section class="card privacy-card" aria-labelledby="privacy-title">
      <div class="sticker" aria-hidden="true">✳</div>
      <p class="eyebrow">DET MED SMÅT</p>
      <h1 id="privacy-title">Privatliv.</h1>
      <p class="message">Kort fortalt: Vi tæller svin, ikke personer. Her er, hvad siden gemmer.</p>
      <div class="privacy-copy">
        <p>Dataansvarlig er ejeren af Fedesvin.dk. Kontakt: <a href="mailto:nissemayn@fedesvin.dk">nissemayn@fedesvin.dk</a>.</p>
        <p>Vi behandler oplysninger for at drive og sikre siden, tælle besøg, begrænse misbrug og levere de funktioner, du vælger at bruge. Retsgrundlaget er vores legitime interesse i at drive og beskytte siden (GDPR artikel 6, stk. 1, litra f). Når du sender en gæstebogshilsen eller opretter et kortlink, bruger vi oplysningerne til at vise hilsenen eller få linket til at fungere, som du bad om; det er tydeligt ved brugen af funktionerne, at hilsenen eller linkoplysningerne bliver offentlige.</p>
        <h2>Besøgstælleren</h2>
        <p>Vi tæller et besøg, når en browser har haft siden åben og synlig i mindst tre sekunder. For at undgå gentælling bruger vi en kortlivet, pseudonymiseret identifikator baseret på IP-adresse og browseroplysninger. Den bruges til at begrænse gentælling og misbrug; rå IP-adresser gemmes ikke i tællerens database. Oplysninger til tælling og beskyttelse mod misbrug ryddes løbende, når de udløber. Samlede besøgstal og subdomænernes placering på hitlisten gemmes, og hitlisten er offentlig.</p>
        <h2>Webserver-logfiler</h2>
        <p>Webserver-logfiler opbevares i syv dage. De kan indeholde IP-adresse, tidspunkt, sti og oplysninger fra browserens forespørgsel. Loggene bruges til drift og fejlsøgning og slettes derefter automatisk.</p>
        <h2>Gæstebog og kortlinks</h2>
        <p>Navne og hilsner, der sendes til gæstebogen, vises offentligt og opbevares, indtil indlægget slettes. Navn og eventuel personlig besked på et kortlink gemmes, så længe linket er aktivt, eller indtil det slettes. Du kan bede om sletning via kontaktadressen nedenfor. Databasen sikkerhedskopieres hver nat, og sikkerhedskopier opbevares i syv dage. Slettede oplysninger kan derfor findes i en sikkerhedskopi i op til syv dage efter sletning fra databasen.</p>
        <h2>Cookies og lokale indstillinger</h2>
        <p>Hvis du selv slår retro-mode til eller fra, gemmes dit valg i en cookie i op til et år, så indstillingen huskes på tværs af Fedesvin.dk og subdomæner. Admin-login bruger en nødvendig sessionscookie. Siden bruger ikke annonce- eller analysetrackers.</p>
        <h2>Opbevaring og adgang</h2>
        <p>Oplysninger behandles på en server, som sidens ejer administrerer. Siden bruger ikke eksterne analyse- eller annoncetjenester. Gæstebogsindlæg og subdomænenavne på hitlisten er offentligt tilgængelige, indtil de fjernes.</p>
        <h2>Dine ønsker</h2>
        <p>Du kan kontakte sidens ejer for at få indsigt i, rettet eller slettet oplysninger om dig eller gøre indsigelse mod behandlingen. Skriv til <a href="mailto:nissemayn@fedesvin.dk">nissemayn@fedesvin.dk</a>; oplys gerne linket eller navnet, så det er til at finde. Du kan også klage til <a href="https://www.datatilsynet.dk/">Datatilsynet</a>.</p>
        <p class="privacy-updated">Opdateret 27. oktober 2026.</p>
      </div>
    </section>
    <footer><span><a href="https://<?= DOMAIN ?>/gaestebog">Gæstebog</a> · <a href="https://<?= DOMAIN ?>/stats">Statistik</a></span><a href="https://<?= DOMAIN ?>">Tilbage til svinestien ↗</a><span class="vibe-note">Siden her er 100% vibecoded fordi jeg selv er et fedt svin!</span></footer>
  </main>
  <script src="/app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>" defer></script>
</body>
</html>
