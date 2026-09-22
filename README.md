# Fedesvin.dk

En kærlig, lille kollegadriller inspireret af dansk internetnostalgi. Forsiden laver delbare `/s/...`-links; wildcard-subdomæner virker også som personlige sider.

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
3. Sæt `APP_BIND_ADDRESS` til Docker-værtens IP-adresse, som Nginx-LXC'en kan nå. Appen bliver tilgængelig på port `8088` dér. Behold named volume `fedesvin-data`.
4. Slå GitOps webhook updates til for stacken. Sæt GitHub Actions secret `PORTAINER_WEBHOOK_URL` til webhook-URL'en.
5. Gør GHCR-pakken public efter første image-push, eller konfigurér registry credentials i Portainer.

Push til `main` bygger og pusher både `latest` og et immutable commit-SHA-tag til GHCR. Når `PORTAINER_WEBHOOK_URL` er sat, bliver stacken derefter redeployeret. I Portainer skal image pull/re-pull være slået til, da stacken bruger `latest`-tagget. Workflowet kan også bruges uden webhook: imaget publiceres stadig, og stacken kan opdateres manuelt. Sørg for, at værtsport `8088` kun er tilladt fra proxy-LXC'en via din firewall.

## Tælleren og links

SQLite gemmer det samlede antal besøg og kortlinks. En sikker, HttpOnly-cookie på domænet `fedesvin.dk` gør, at samme browser tæller højst én gang pr. otte timer på tværs af siden. Cookie-sletning, privat browsing og bots uden cookies kan stadig påvirke tallet. Der gemmes ingen IP-adresser.

Kortlinks bruger tilfældige suffikser for at undgå kollisioner. De ligger i databasen og overlever deploys via `fedesvin-data`.

## Ældre udgave

Den oprindelige PHP-side er bevaret i Git-branchen `legacy`.
