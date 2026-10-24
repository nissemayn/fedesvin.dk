# Fedesvin.dk

En kærlig, lille kollegadriller inspireret af dansk internetnostalgi. Forsiden laver delbare, tilfældige `/s/...`-kortlinks, der viderestiller til navnets wildcard-subdomæne. Subdomæner virker også direkte som personlige sider.

## Lokal kørsel

Byg imaget og start appen:

```sh
docker build -t fedesvin:local .
docker run --rm -p 8080:80 -v fedesvin-data:/data fedesvin:local
```

Åbn `http://localhost:8080`. SQLite-filen ligger i `/data` og skal derfor have et persistent volume.

## Portainer og GHCR

1. Opret en Portainer Stack fra dette Git-repository, branch `main`, Compose path `compose.yaml`.
2. Sæt stack-variablen `IMAGE` til `ghcr.io/<github-owner>/<repo>:latest`.
3. Behold named volume `fedesvin-data`. Compose publicerer appen på port `8088` på Docker-værten; peg Zoraxy upstream på Docker-værtens IP og port `8088`.
4. Sæt `GUESTBOOK_ADMIN_PASSWORD` som stack environment variable i Portainer; brug en unik adgangskode på mindst 16 tegn. Valgfrit kan `GUESTBOOK_ADMIN_USER` ændre standardbrugeren `admin`.
5. Slå GitOps webhook updates til for stacken. Sæt GitHub Actions secret `PORTAINER_WEBHOOK_URL` til webhook-URL'en.
6. Gør GHCR-pakken public efter første image-push, eller konfigurér registry credentials i Portainer.

Push til `main` bygger og pusher både `latest` og et immutable commit-SHA-tag til GHCR. Når `PORTAINER_WEBHOOK_URL` er sat, bliver stacken derefter redeployeret. I Portainer skal image pull/re-pull være slået til, da stacken bruger `latest`-tagget. Workflowet kan også bruges uden webhook: imaget publiceres stadig, og stacken kan opdateres manuelt. Sørg for, at værtsport `8088` kun er tilladt fra proxy-LXC'en via din firewall.

## Tælleren og links

HTML-sidevisninger tæller ikke i sig selv. JavaScript sender et POST til `/counter` tre sekunder efter `load`, men kun mens siden er synlig. SQLite gemmer totalen i den eksisterende `stats`-tabel, samt HMAC-baserede visitor hashes og rate-limit hashes; rå IP-adresser gemmes ikke. Samme IP/User-Agent-kombination tæller højst én gang pr. otte timer. Et signeret sidetoken er bundet til IP og User-Agent og udløber efter 15 minutter. En secret fra `COUNTER_SECRET` bruges, hvis den er sat; ellers oprettes en tilfældig secret i det persistente `/data`-volume.

Counter-endpointet filtrerer almindelige bot/crawler User-Agent-strenge og tillader højst 30 POST-requests pr. IP pr. minut. Visitor- og rate-limit-rækker ryddes probabilistisk på cirka 1 % af gyldige counter-requests. Sæt `TRUSTED_PROXY_IPS` i Portainer til Zoraxys IP-adresse(r), som app-containeren ser i `REMOTE_ADDR` (fx `192.168.1.152`). Zoraxy skal sende `X-Forwarded-For`; forwarded IP bruges kun, når den direkte forbindelse kommer fra en konfigureret betroet proxy.

Kortlinks bruger tilfældige suffikser for at undgå kollisioner. De ligger i databasen og overlever deploys via `fedesvin-data`.

## Offentlig statistik

`/stats` viser de 20 mest besøgte subdomæner, den eksisterende besøgstotal, summerede subdomænebesøg, antal besøgte subdomæner og antal oprettede kortlinks. Der vises ingen visitor hashes, IP-adresser, tokens eller kortlinkkoder. Statistiksidevisninger tælles ikke.

Subdomænestatistik starter ved denne opdatering; historiske besøg kan ikke fordeles på subdomæner. `subdomain_stats` gemmer summer og første/seneste tællede tidspunkt. `counter_subdomain_visitors` deduplikerer på `(subdomain, visitor_hash)` i otte timer og ryddes sammen med den eksisterende visitor-tabel. Begge tabeller oprettes automatisk i den eksisterende SQLite-database. Registrering og increment sker i samme låste transaktion som den globale tæller. Globale besøg deduplikeres fortsat på tværs af domæner, mens hvert subdomæne har sit eget vindue; summerne er derfor ikke direkte sammenlignelige. Counter-tokenet er også bundet til subdomænet, så det ikke kan genbruges til at tælle på et andet subdomæne.

## Gæstebog og moderation

`/gaestebog` viser indlæg med det samme. Hvert opslag har et valgfrit navn og en besked på op til 400 tegn. Honeypot, IP-bundet signeret formular-token og en grænse på tre opslag pr. IP pr. time dæmper spam; kun HMAC-hashes af IP-adresser gemmes i `guestbook_rate_limits`. Gæstebogen starter automatisk med ét velkomstindlæg.

`/gaestebog/admin` bruger HTTP Basic Auth. Sæt `GUESTBOOK_ADMIN_PASSWORD` i Portainer Stack environment variables før brug; brugernavnet er `admin`, medmindre `GUESTBOOK_ADMIN_USER` er sat. Adminsiden har CSRF-beskyttede sletteknapper. Der findes ingen offentlig slettefunktion. Nye opslag bliver synlige med det samme, så moderering her betyder at kunne fjerne uønskede indlæg.

## Ældre udgave

Den oprindelige PHP-side er bevaret i Git-branchen `legacy`.

## Personlige beskeder, grise og retro

Kortlinkformularen kan gemme en valgfri besked på højst 160 Unicode-tegn. Den eksisterende `shortlinks`-tabel migreres automatisk med `message TEXT NOT NULL DEFAULT ''`; gamle kortlinks virker fortsat. Beskeder behandles som ren tekst og HTML-escapes ved visning. Et kortlink viderestiller nu til `navn.fedesvin.dk/p/<kode>`, så beskeden og det oprindelige navn bevares ved genindlæsning. Gamle `?s=<kode>`-adresser understøttes fortsat. Beskeder er offentlige for dem, der har linket, og indgår også i linkets chat-preview og billedkort.

Grisen har ni varianter: klassisk, rosa, mint, business, fest, royal, goth, pirat og rumgris. JavaScript vælger en ny ved hver sidevisning og bruger lokal browserlagring til at undgå samme variant to gange i træk på samme domæne. Uden lagringsadgang er valget stadig tilfældigt, men kan gentage sig. Tryk eller tastaturaktivering giver et hop, en syntetisk ØF-lydeffekt og en tilfældig sætning fra 24 muligheder, uden samme sætning to gange i træk; lyden kan slås fra, og hvert femte tryk giver hop og konfetti. Retro-mode deles mellem domænet og dets subdomæner via en cookie. Rulleteksten starter automatisk og kan altid sættes på pause. Et Netscape 4.0-badge fuldender svinestien.

`/share-image.png` laver et 1200 × 630 PNG-kort med PHP GD og DejaVu-skrifttyper fra Docker-imaget. `?code=<kortlinkkode>` medtager navn og besked; `?name=<navn>` understøtter direkte subdomæner. `download=1` sender billedet som download, og `v` kan vælge en af de ni grisevarianter. Formularresultatets download matcher den viste variant. Open Graph- og Twitter-metadata peger på PNG-billedet. Chat-apps kan cache deres previews; en allerede delt adresse kan derfor beholde sit gamle preview i en periode. Billeder, previews og almindelige HTML-downloads tæller ikke som besøg.

## Test

Kør `node tests/stats.mjs` med PHP (PDO SQLite og GD med FreeType aktiveret) og Node.js 18+ tilgængeligt. Testen starter en lokal PHP-server på port 18089 og bruger en isoleret midlertidig database. Den kontrollerer migration af gammel total og kortlinks, personlige beskeder/escaping, redirects, metadata, PNG-download, gæstebogsopslag/escaping og adminsletning, tokenbinding, botfilter, deduplikering, otte-timers udløb, top 20-sortering, rate limiting og offentligt output. `PHP_BIN` og eventuelt `PHP_EXT_DIR` kan pege på en portabel PHP-installation. `SHARE_FONT_REGULAR` og `SHARE_FONT_BOLD` kan pege på lokale TrueType-filer ved test uden for Docker.
