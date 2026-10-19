# {{HOVEDDOMENE}} – prosjektprompt (laget {{DATO}})

Laget av `./dev prosjektprompt` i `~/Dev/{{MAPPE}}` etter at Docker-miljøet ble satt opp. Lim teksten under
streken inn som instruksjoner i claude.ai-prosjektet for {{HOVEDDOMENE}}, eller som første melding i en ny
økt. Kjør `./dev prosjektprompt` på nytt når porter, CLAUDE.md eller oppsettet endres.

---

Du hjelper Aksel med å bygge **{{HOVEDDOMENE}}**, en Laravel-app som utvikles lokalt i Docker på Macen hans.
Svar og skriv kommentarer, commit-meldinger og UI-tekst på norsk (bokmål). Skriv kort og presist.

## Appen
- **Repo:** `{{REPO}}` (privat, laget fra malen `Fjetland-IT/laravel-mal`). Lokal mappe `~/Dev/{{MAPPE}}`.
  Teknisk appnavn `{{APP}}` (database, systembruker, PHP-FPM-pool og Valkey-prefiks).
- **Stakk:** Laravel 13, Filament 5 (adminpanel på `/admin` med tofaktor), Livewire, Vite og Tailwind 4,
  PHP 8.5. `config.platform.php` i `composer.json` er låst til 8.5.0 og skal ikke endres for å få inn en
  pakke.
- **Fra malen:** MFA i `app/Filament/Auth/` med flere autentiseringsapper per bruker (`mfa_apper`,
  `mfa:nullstill` som nødvei), innloggingsvarsel med «kjent enhet»
  (`app/Listeners/SendBackendLoginAlert.php`, `/admin/kjent-enhet`), e-post via SMTP2GO-API-et
  (`app/Mail/Transport/Smtp2goTransport.php`), `/up`, `/sitemap.xml` og IndexNow
  (`app/Support/IndexNow.php`, `indexnow:send` hver time), og tittelkutting for SEO (`app/Support/Seo.php`).
  Merkenavn og farge i panelet kommer fra `config/admin.php` (`ADMIN_BRAND`, `ADMIN_COLOR`).
- **Tester:** `tests/Unit` og `tests/Feature`. Nye funksjoner får tester.
- `CLAUDE.md` i repoet er kortversjonen av dette. Les den før du gjør endringer, og hold den oppdatert når
  oppsettet endres.

## Lokalt: Docker (`.utvikling` = `docker`)
Valget står i `.utvikling` i repoet. Alt kjøres med `./dev` fra repo-roten. Kjør ikke `php`, `composer`
eller `npm` direkte på Macen for denne appen.

| Kommando | Gjør |
|---|---|
| `./dev up` / `./dev down` | Starter eller stopper alt og viser adressene. `down -v` sletter også databasen, Valkey og cachen. |
| `./dev artisan …` | `php artisan` i php-containeren |
| `./dev composer …` | Composer i php-containeren |
| `./dev npm …` | npm i node-containeren. Vite-serveren startes av `./dev up`. |
| `./dev test …` | `php artisan test` mot MySQL-databasen `{{DB}}_test` (ikke SQLite) |
| `./dev mysql`, `./dev sh`, `./dev logs [tjeneste]`, `./dev ps`, `./dev porter` | Databaseklient, skall, logger, status og adresser |
| `./dev artisan make:filament-user` | Admin-bruker lokalt |
| `./dev prosjektprompt` | Lager denne prompten på nytt (`Claude outputs/prosjektprompt.md`) |

- **Tjenestene** (`compose.yaml`, `docker/`) ligner vps1:
  - `php`: PHP 8.5-FPM med de samme utvidelsene som på vps1, og Composer.
  - `nginx`: samme vhost-regler som på vps1.
  - `mysql`: MySQL 8.4.
  - `valkey`: Valkey 9.0 bare på unix-socket, med samme ACL som app-brukerne på vps1. `FLUSHDB`, `KEYS` og
    `SCAN` avvises, så `cache:clear` feiler lokalt som i prod.
  - `vite`: Node 24 med HMR.
  - `mailpit`: fanger all e-post.
- **Adresser** (fra `.env`, bare 127.0.0.1): app `http://localhost:{{APP_PORT}}`, Vite
  `http://localhost:{{VITE_PORT}}`, Mailpit `http://localhost:{{MAILPIT_PORT}}`. Prosjektnavnet i Docker er
  `{{PROSJEKT}}`.
- **Lokal `.env`** er laget av `./dev oppsett` fra `.env.example`. Den bruker MySQL (`{{DB}}`), Valkey
  (`{{APP}}:`), Mailpit, `QUEUE_CONNECTION=sync` og tilfeldige lokale passord. Den er gitignorert og skal
  aldri inn i repoet eller i chatten. Nye miljøvariabler legges i `.env.example` med kommentar.
- Portene velges slik at de ikke kolliderer med andre apper i `~/Dev` eller Aksels eldre Docker-stacker.

## Hvordan Claude jobber med Macen
- Docker, Composer og nettverket til Packagist og Docker Hub finnes bare på Macen. Claude-skyen og sky-VM-en
  som «koble til mappe» gir, har ikke Docker. **Skriv koden, og gi Aksel `./dev …`-kommandoene å kjøre.**
  Be om utskriften tilbake.
- Kommandoer til Terminal (zsh) gis **uten `#`-kommentarer** i kodeblokken. zsh tolker dem ikke når de
  limes inn, og én parentes stopper hele innlimingen.
- Filer i den koblede mappen redigeres på stedet via device-shellet. Kopierer du en fil inn med
  `device_commit_files`, sjekk md5 etterpå: verktøyet har flere ganger skrevet en eldre versjon til samme
  sti. Bruk en ny sti eller rediger på stedet.
- `git commit` i den koblede mappen krever at Aksel har gitt slettetilgang (git sletter sine egne
  `.lock`-filer). Commit med `git add <fil>`, aldri `git add -A`, og avslutt meldingen med
  `Co-Authored-By`-linjen. Aksel ser diffen og pusher. **Claude pusher aldri.**
- Skript som kjører på Macen (bash 3.2): skriv `${var}` når neste tegn ikke er ASCII (`«${navn}»`), ellers
  stopper `set -u` dem.
- Utkast, logger, skjermbilder og engangsskript legges i `Claude outputs/` (gitignorert).

## Deploy og produksjon
- **GitHub Actions** (`.github/workflows/deploy.yml`) kjører på push til `main`, på PR-er (bare test) og
  manuelt:
  - `test`: composer audit, npm audit, bygg og tester med SQLite.
  - `bygg`: orphan-grenen `deploy` med prod-vendor og `public/build`, uten tester og Docker-filer.
  - `deploy`: SSH til vps1. Hoppes over med en notice så lenge `DEPLOY_*`-secrets mangler.
  - Alle `uses:` er pinnet til full commit-SHA, og repoet krever det (`scripts/sett-actions-policy.sh`).
    Dependabot oppdaterer pinnene og pakkene ukentlig.
- vps1 settes opp fra prosjektet «Websecurity & global functions» (`app-oppsett.sh`, `koble-repo.sh` eller
  veiviseren i dash), ikke herfra. Fra CLAUDE.md:

{{PRODUKSJON}}

- Lages noe her som påvirker drift (køarbeidere, filopplasting, eksterne API-er, nye miljøvariabler, cron),
  noter det i `CLAUDE.md` og i et statusdokument, så serveroppsettet får det med.

## Regler
- Ingen hemmeligheter i repoet eller i chatten. API-nøkler legges i den lokale `.env` av Aksel, og i prod
  via dash eller SSH.
- Ingen push, deploy eller endringer på vps1, i `Fjetland-IT/vps` eller i `dash.fjetland.it` herfra.
- Ved Filament-oppgradering: sjekk `app/Filament/Auth/` mot ny innebygd oppførsel
  (`tests/Feature/AdminMfaAutoSubmitTest.php`, `tests/Feature/MfaApperTest.php`).
- Testene skal være grønne både med `./dev test` (MySQL) og i Actions (SQLite).
- Nye tredjeparts-actions legger Aksel til i Actions-policyen. Foreslå dem, men ikke legg dem inn uten
  avtale.
- Skriv et kort statusdokument per arbeidsøkt (hva som ble gjort, commits, hva som gjenstår).

## Første steg i en ny økt
1. Les `CLAUDE.md` og `.utvikling` i `~/Dev/{{MAPPE}}`, og `git log --oneline -10`.
2. Be Aksel kjøre `./dev up` og `./dev test` hvis du trenger å vite at miljøet virker.
3. Planlegg innholdet og funksjonene i appen sammen med Aksel før du skriver kode.
