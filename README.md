# Fedesvin.dk

En kærlig, lille kollegadriller inspireret af dansk internetnostalgi. Projektet er bygget med PHP, SQLite, JavaScript og CSS.

## Funktioner

- Personlige sider på wildcard-subdomæner og tilfældige kortlinks i formatet `/s/<kode>`. Kortlinks viderestiller til personens subdomæne.
- Valgfri personlig besked på kortlinks samt et delingsbillede med Open Graph-preview. Hvert kortlink beholder sin egen gris ved genindlæsning og i previewet.
- Offentlig statistik over besøg og populære subdomæner.
- Gæstebog med server-side moderation, enkle botværn og CSRF-beskyttelse.
- Privatlivsside med information om tæller, gæstebog, kortlinks, cookies og serverlogs.
- Retro-mode med rulletekst, badges og en lokalt indlejret comic-style font.

## Lokal udvikling

Kør appen med Docker:

```sh
docker build -t fedesvin:local .
docker run --rm -p 8080:80 -v fedesvin-data:/data fedesvin:local
```

Åbn `http://localhost:8080`. SQLite-databasen ligger i `/data`; brug et persistent volume, hvis data skal overleve, at containeren fjernes.

## Data og privatliv

Besøgstælleren tæller ikke almindelige sideindlæsninger. Synlig browser-JavaScript sender et POST efter tre sekunder. Besøgende deduplikeres i otte timer med en HMAC-baseret hash af IP og User-Agent; rå IP-adresser gemmes ikke. Almindelige crawlers filtreres fra, og endpointet har rate limiting.

Gæstebogen bruger honeypot, JavaScript-kontrol, en kort minimumstid for formularen og en grænse på tre opslag pr. IP i timen. Det dæmper simple bots, men er ikke CAPTCHA og kan omgås af målrettede bots. Navne og beskeder bliver synlige offentligt. `/gaestebog/admin` er beskyttet med HTTP Basic Auth; moderation og sletning er CSRF-beskyttet.

## Tredjepartsaktiver og licenser

- **Comic Neue** — webfonten i `assets/fonts/` er lavet af Comic Neue Project Authors og hentet via [Google Fonts](https://fonts.google.com/specimen/Comic+Neue). Den er et Comic Sans-lignende open-font-alternativ, indlejret for at give samme typografi på tværs af enheder. Licens: [SIL Open Font License 1.1](https://github.com/google/fonts/blob/main/ofl/comicneue/OFL.txt), også inkluderet i [`assets/fonts/ComicNeue-OFL.txt`](assets/fonts/ComicNeue-OFL.txt).
- **Griselyd** — “Mudchute pig 2”, optaget på Mudchute City Farm i 2007 af Secretlondon. [Kilde på Wikimedia Commons](https://commons.wikimedia.org/wiki/File:Mudchute_pig_2.ogg). Brugt uændret under [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/).

## Tests

Kør `node tests/stats.mjs` med Node.js 18+ og PHP med PDO SQLite og GD/FreeType. Testen starter en lokal server og bruger en isoleret midlertidig database. `PHP_BIN`, `PHP_EXT_DIR`, `SHARE_FONT_REGULAR` og `SHARE_FONT_BOLD` kan bruges til at pege på lokale testafhængigheder.
