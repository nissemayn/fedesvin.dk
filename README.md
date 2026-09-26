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
3. Behold named volume `fedesvin-data`. Compose publicerer appen på port `8088` på Docker-værten; peg Nginx-LXC upstream på Docker-værtens IP og port `8088`.
4. Slå GitOps webhook updates til for stacken. Sæt GitHub Actions secret `PORTAINER_WEBHOOK_URL` til webhook-URL'en.
5. Gør GHCR-pakken public efter første image-push, eller konfigurér registry credentials i Portainer.

Push til `main` bygger og pusher både `latest` og et immutable commit-SHA-tag til GHCR. Når `PORTAINER_WEBHOOK_URL` er sat, bliver stacken derefter redeployeret. I Portainer skal image pull/re-pull være slået til, da stacken bruger `latest`-tagget. Workflowet kan også bruges uden webhook: imaget publiceres stadig, og stacken kan opdateres manuelt. Sørg for, at værtsport `8088` kun er tilladt fra proxy-LXC'en via din firewall.

## Tælleren og links

HTML-sidevisninger tæller ikke i sig selv. JavaScript sender et POST til `/counter` tre sekunder efter `load`, men kun mens siden er synlig. SQLite gemmer totalen i den eksisterende `stats`-tabel, samt HMAC-baserede visitor hashes og rate-limit hashes; rå IP-adresser gemmes ikke. Samme IP/User-Agent-kombination tæller højst én gang pr. otte timer. Et signeret sidetoken er bundet til IP og User-Agent og udløber efter 15 minutter. En secret fra `COUNTER_SECRET` bruges, hvis den er sat; ellers oprettes en tilfældig secret i det persistente `/data`-volume.

Counter-endpointet filtrerer almindelige bot/crawler User-Agent-strenge og tillader højst 30 POST-requests pr. IP pr. minut. Visitor- og rate-limit-rækker ryddes probabilistisk på cirka 1 % af gyldige counter-requests. Sæt `TRUSTED_PROXY_IPS` i Portainer til Zoraxys IP-adresse(r), som app-containeren ser i `REMOTE_ADDR` (fx `192.168.1.152`). Zoraxy skal sende `X-Forwarded-For`; forwarded IP bruges kun, når den direkte forbindelse kommer fra en konfigureret betroet proxy.

Kortlinks bruger tilfældige suffikser for at undgå kollisioner. De ligger i databasen og overlever deploys via `fedesvin-data`.

## Ældre udgave

Den oprindelige PHP-side er bevaret i Git-branchen `legacy`.
