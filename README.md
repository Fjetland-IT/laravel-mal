# laravel-mal

Mal for nye Laravel-apper på vps1: Laravel 13, Filament 5, Vite og PHP 8.5
(`config.platform.php = 8.5.0`). App-repoene lages fra malen på `akselfoley`; malen ligger i
`Fjetland-IT`. Se `claude/dash-plan-2026-10-15.md` i prosjektet, fase 4.

## Innhold

| Del | Hvor |
|---|---|
| Filament-panel på `/admin` med tofaktor (TOTP), påkrevd i produksjon | `app/Providers/Filament/AdminPanelProvider.php`, `config/admin.php` |
| MFA-tilpasninger fra amexguiden (fokus på kodefeltet, avvist kode tømmes; Filaments egen auto-innsending fra 5.10) | `app/Filament/Auth/` |
| Flere autentiseringsapper per bruker (høyst 5), varsel ved endringer og nødvei `mfa:nullstill` (O15) | `app/Filament/Auth/MfaApperAuthentication.php`, `app/Filament/Auth/MfaApper/`, `app/Models/MfaApp.php`, `app/Console/Commands/MfaNullstill.php` |
| Innloggingsvarsel på e-post, unntatt fra kjente enheter (`/admin/kjent-enhet`) | `app/Listeners/SendBackendLoginAlert.php`, `routes/web.php` |
| E-post via SMTP2GO-API-et | `app/Mail/Transport/Smtp2goTransport.php` |
| Helsesjekk `/up` | `bootstrap/app.php` |
| `/sitemap.xml` og IndexNow (`indexnow:send` hver time) | `app/Http/Controllers/SitemapController.php`, `app/Support/IndexNow.php` |
| Tittelkutting for SEO (maks 70 tegn, merkenavnet droppes først) | `app/Support/Seo.php`, `resources/views/layouts/app.blade.php` |
| Valkey for sesjoner, cache og kø i produksjon | `.env.example` |
| Test, audit, bygg og deploy | `.github/workflows/deploy.yml`, `pull-deploy.sh` |
| Dependabot for Actions-pinnene, Composer og npm | `.github/dependabot.yml` |
| Actions-policy per app-repo | `scripts/sett-actions-policy.sh` |
| Mal for CLAUDE.md | `CLAUDE.md.tpl` |
| Lokalt utviklingsmiljø i Docker, valgfritt per app (`.utvikling`) | `compose.yaml`, `docker/`, `dev` |

## Nytt app-repo fra malen

Normalt gjør veiviseren i dash dette (fase 4, steg 5). For hånd:

1. Lag repoet på `akselfoley` fra malen (privat), med reponavn = hoveddomenet (se planens «Apper og domener»).
2. Sett Actions-policyen **før** første kjøring: `scripts/sett-actions-policy.sh <repo>`.
3. Render `CLAUDE.md.tpl` til `CLAUDE.md`, slett `.tpl`-fila, og sett `name`/`description` i
   `composer.json`. `name` inngår i `content-hash` i `composer.lock`, så kjør `composer update --lock`
   (eller `./dev composer update --lock`) etterpå og commit lock-fila; ellers advarer `composer install`
   om at lock-fila er utdatert. Plassholdere:

   | Plassholder | Eksempel |
   |---|---|
   | `{{APP}}` | `eurobonus` |
   | `{{VISNINGSNAVN}}` | `EuroBonus-guiden` |
   | `{{REPO}}` / `{{REPO_NAVN}}` | `akselfoley/eurobonus.guide` / `eurobonus.guide` |
   | `{{HOVEDDOMENE}}` | `eurobonus.guide` |
   | `{{DOMENER}}` | Markdown-liste, én linje per domene: `  - eurobonus.guide – hoveddomene` |
   | `{{DATABASE}}` | `eurobonus` (eller `SQLite (database/database.sqlite)`) |
   | `{{CF_SONER}}` | `eurobonus.guide (<sone-ID>)` |
   | `{{MAILMD_FRA}}` | `aksel@amexguiden.no` |
   | `{{DASH_URL}}` | `https://dash.fjetland.it/apper/eurobonus` |

4. Server og secrets (veiviseren / `dash-nytt-nettsted.sh`): app-bruker, pool, database, Valkey-ACL, vhost,
   sertifikat, deploy-nøkkel og Actions-secrets under.

## Flere MFA-apper (O15)

Hver bruker kan registrere opptil fem autentiseringsapper (TOTP), for eksempel telefonen og
passordbehandleren. Innlogging godtar en kode fra hvilken som helst av dem. To apper er ikke påkrevd, men
profilsiden ber om en til når brukeren bare har én.

- **Lagring:** tabellen `mfa_apper` (`navn`, kryptert `hemmelighet`, `sist_brukt_at`, `sist_brukt_steg`).
  Filaments enkeltkolonne `users.app_authentication_secret` brukes ikke lenger. Gjenopprettingskodene ligger
  fortsatt på `users` og virker som før.
- **Gjenbruk:** en kode fra samme eller et eldre tidssteg avvises per app (`sist_brukt_steg`, betinget
  UPDATE). Kodevinduet og rate limitingen på innloggingen er Filaments, som før.
- **Profilsiden:** lista viser navn, når appen ble lagt til og sist brukt. Hemmeligheter vises bare i
  dialogen der appen legges til.
  - Første app settes opp med Filaments egen handling (passord, QR og gjenopprettingskoder) og får navnet
    «Første app».
  - «Legg til app» krever en kode fra den nye appen og en fersk kode fra en app brukeren har fra før.
  - «Fjern» krever en kode fra en av de andre appene. Siste app kan ikke fjernes når panelet krever MFA
    (`ADMIN_MFA_REQUIRED`, på i produksjon).
- **Varsel:** e-post til `ADMIN_ALERT_EMAIL` når en app legges til eller fjernes, og ved `mfa:nullstill`
  (navn på appen, tidspunkt og IP, aldri hemmeligheten), og en `mfa:`-linje i `laravel.log`.

### Nødvei: alle appene er borte

En gjenopprettingskode gir innlogging, men ikke en ny app: «Legg til app» krever en kode fra en app
brukeren har. Er alle appene borte, nullstilles MFA fra serveren, som app-brukeren:

```sh
ssh drift@45.88.201.138
sudo -iu <app> bash -c 'cd /srv/<app> && php8.5 artisan mfa:nullstill <e-post>'
```

Kommandoen viser brukeren og appene og spør før den sletter appene og gjenopprettingskodene. Neste
innlogging ber om ny registrering. Den sender samme varsel som når en app fjernes. Kommandoen finnes ikke i
dash eller MCP. Lokalt: `php artisan mfa:nullstill <e-post>` (eller `./dev artisan …`).

Til neste innlogging har brukeren bare passordet, og den som logger inn først, registrerer appen. Er
passordet eller økten kanskje kompromittert, bytt passordet også (`php artisan tinker`), og nullstill rett
før brukeren selv logger inn.

### Migrering i en app med brukere fra før

1. `2026_10_20_000000_create_mfa_apper_table.php` lager tabellen og kopierer hver eksisterende hemmelighet
   til `mfa_apper` som «Første app». Verdien kopieres kryptert som den er (samme `APP_KEY`), så ingen må
   registrere på nytt. Den gamle kolonnen blir stående, men tømmes for en bruker når en app fjernes eller
   MFA nullstilles, så en tilbakerulling ikke gjør en fjernet app gyldig igjen. Rulles migreringen
   tilbake, går apper lagt til etterpå tapt.
2. Når innlogging er bekreftet i drift, flyttes
   `database/migrations/senere/2026_10_20_000001_drop_app_authentication_secret_from_users.php` til
   `database/migrations/` i en egen commit, og deployes. Laravel leser ikke `senere/`, så den kjøres ikke
   før den flyttes. En ny app uten brukere kan flytte den med en gang.

Tester: `tests/Feature/MfaApperTest.php` og `tests/Feature/MfaApperMigreringTest.php`.

## Deploy-oppsett

Workflowen kjører på push til `main`, på PR-er (bare test) og manuelt.

| Jobb | Rettigheter | Gjør |
|---|---|---|
| `test` | `contents: read` | `composer install`, `composer audit`, `npm ci`, `npm audit --audit-level=high`, `npm run build`, `php artisan test` |
| `bygg` | `contents: write` | Prod-vendor og `public/build` som én commit på orphan-grenen `deploy` |
| `deploy` | ingen | SSH til vps1; hoppes over (med notice) når secrets mangler |

Repo-secrets (`Settings → Secrets and variables → Actions`):

| Navn | Innhold |
|---|---|
| `DEPLOY_HOST` | `45.88.201.138` |
| `DEPLOY_USER` | appnavnet (systembrukeren) |
| `DEPLOY_SSH_KEY` | privat del av Actions-nøkkelen (ed25519, uten passfrase) |
| `DEPLOY_KNOWN_HOSTS` | `45.88.201.138 ssh-ed25519 AAAA…` (vertsnøkkelen til vps1) |

Etter deploy sjekker `pull-deploy.sh` `/up` mot nginx på 127.0.0.1 (vert fra `APP_URL`), forbi Cloudflare.
Workflowen ber om sjekken med `deploy --helsesjekk` (kommer fram som `SSH_ORIGINAL_COMMAND`), og
skriptets exit-kode styrer jobben (O13). Valgfri repo-variabel `HELSESJEKK_URL` (f.eks.
`https://eurobonus.guide/up`) sjekkes i tillegg via Cloudflare, men bare som varsel: Bot Fight Mode gir
GitHub-runnere en managed challenge, og bak Cloudflare Access svarer Access med en omdirigering.

På serveren, i `~<app>/.ssh/authorized_keys`, slik at nøkkelen bare kan starte deploy:

```
command="/srv/<app>/pull-deploy.sh",restrict ssh-ed25519 AAAA… actions-<app>
```

Serveren henter `deploy` med en egen deploy-nøkkel (bare lesing) på repoet.

## Lokalt: `docker` eller `mac`

Hver app velger lokalt utviklingsmiljø i fila `.utvikling` i repoet, med ett ord: `docker` eller `mac`.
Mangler fila, gjelder `mac`. Malen har ingen `.utvikling`; valget settes når appen hentes første gang:

```sh
~/Dev/_oppsett/hent-app.sh <repo> --docker    # eller --mac
```

`hent-app.sh` skriver fila og spør før den committer den. Alle maskiner og Claude-økter ser da samme valg.
Docker-filene ligger alltid i repoet, men brukes bare med `docker`.

### `mac`

PHP 8.5 og Composer på Macen. `hent-app.sh` lager `.env` (SQLite, fil-cache og -sesjoner, mail til logg),
og kjører `composer install`, `npm ci` og bygg, nøkkel, migrering og tester. Node er ikke installert på
Macen; npm kjøres i Docker via `~/Dev/_oppsett/npm.sh` (Docker Desktop må kjøre). For hånd:

```sh
composer install
~/Dev/_oppsett/npm.sh ci && ~/Dev/_oppsett/npm.sh run build
cp .env.example .env    # og sett lokale verdier, se toppen av fila
php artisan key:generate
php artisan migrate
php artisan test
php artisan make:filament-user
```

### `docker`

Containere som ligner vps1, styrt med `./dev` (Docker Desktop må kjøre):

| Tjeneste | Bilde | Som på vps1 |
|---|---|---|
| `php` | `docker/php/Dockerfile` (`php:8.5-fpm` + Composer) | PHP 8.5-FPM med utvidelsene fra `php8.5-*`-pakkene, OPcache-innstillingene og `expose_php = Off` |
| `nginx` | `nginx:stable-alpine` | Samme vhost-regler: bare `/index.php` til PHP, punktfiler avvist, samme sikkerhetshoder |
| `mysql` | `mysql:8.4` | MySQL 8.4, `local_infile = 0`, `skip-name-resolve`; database og bruker heter som appen |
| `valkey` | `valkey/valkey:9.0` | Bare unix-socket (`/run/valkey/valkey.sock`, delt med `php`), `default` av, ACL-bruker med samme regel som på vps1 (`~<app>:*`, ingen `FLUSHDB`/`KEYS`/`SCAN`) |
| `vite` | `node:24` | Samme Node-major som Actions; `npm run dev` med HMR |
| `mailpit` | `axllent/mailpit` | Fanger all e-post fra appen (i prod: SMTP2GO) |

```sh
./dev oppsett          # første gang: .env, porter, image, composer, npm, nøkkel, migrering, tester, start
./dev up               # start (viser adressene)
./dev down             # stopp; ./dev down -v sletter også database, Valkey-data og cache
./dev artisan migrate
./dev composer require …
./dev npm ci
./dev test             # mot MySQL (<DB_DATABASE>_test), ikke SQLite
./dev mysql | sh | logs [tjeneste] | ps | porter | build | compose …
./dev artisan make:filament-user
./dev prosjektprompt   # prompt til claude.ai-prosjektet for appen (trenger ikke Docker)
```

- **Prosjektprompt:** `./dev oppsett` lager til slutt `Claude outputs/prosjektprompt.md` fra
  `docker/prosjektprompt.md.tpl`, med appens navn, repo, porter, Docker-oppsettet, arbeidsmåten med Macen og
  «Produksjon»-delen fra CLAUDE.md. Den limes inn som instruksjoner i claude.ai-prosjektet for appen. Kjør
  `./dev prosjektprompt` på nytt når porter, CLAUDE.md eller oppsettet endres.

- **`.env`:** `./dev oppsett` lager den fra `.env.example` når den mangler. MySQL og Valkey på socket
  beholdes som i prod, med appnavnet fra CLAUDE.md («Teknisk appnavn») som database, bruker og
  Valkey-prefiks, og tilfeldige lokale passord. `APP_URL=http://localhost:<APP_PORT>`, `MAIL_MAILER=smtp`
  mot Mailpit, `QUEUE_CONNECTION=sync` (ingen køarbeider lokalt), og egen `SESSION_COOKIE` per app, siden
  alle appene deler `localhost`. En `.env` fra `mac` brukes ikke: flytt den bort (`mv .env .env.mac`).
- **Porter og navn:** prosjektnavnet er mappenavnet (`eurobonus.guide` → `eurobonus-guide`), så containere
  og volumer aldri deles mellom apper. `APP_PORT` (fra 8100), `VITE_PORT` (fra 5180) og `MAILPIT_PORT` (fra
  8025) velges ledige: ingen annen app i `~/Dev/*/.env` har dem, ingen fast vertsport i en annen apps
  `docker-compose.yml`/`compose.yaml` bruker dem (eldre stacker, også stoppet), og ingenting lytter på dem.
  De skrives i `.env`. Alt publiseres bare
  på 127.0.0.1; MySQL og Valkey publiseres ikke. Egne tillegg legges i `compose.override.yaml`
  (gitignorert), f.eks. `services: { mysql: { ports: ["127.0.0.1:33061:3306"] } }`.
- **Tester:** `./dev test` setter `DB_CONNECTION=mysql` og `DB_DATABASE=<DB_DATABASE>_test`, som går foran
  `<env>` i `phpunit.xml`. `./dev` lager testdatabasen (idempotent) med det lokale `DB_ROOT_PASSWORD` fra
  `.env` før hver testkjøring.
  Actions tester fortsatt med SQLite.
- **Bruker-ID:** `php` og `vite` kjører med din UID/GID (`./dev` setter `DEV_UID`/`DEV_GID`), så filer i
  repoet ikke blir eid av root på Linux.
- **Vite:** `./dev up` starter `vite`, som skriver `public/hot`; `./dev down` fjerner fila. Uten Vite
  brukes `public/build` (`./dev npm run build`).
- **Ingen hemmeligheter:** compose-fila og bildet har ingen; alt kommer fra den lokale `.env`.
- **Deploy:** uendret. Workflowen fjerner `compose.yaml`, `docker/`, `dev` og `.utvikling` før grenen
  `deploy` bygges, og `pull-deploy.sh` vet ingenting om Docker.
- **Bytte valg:** endre `.utvikling` i en egen commit, flytt `.env` bort og kjør `hent-app.sh <repo>` på
  nytt. `./dev down -v` rydder Docker-volumene når appen går tilbake til `mac`.

## Vedlikehold av malen

- Oppdateringer kommer som Dependabot-PR-er. Endringer i malen går ikke automatisk til apper som er laget
  fra den; ta dem over for hånd ved behov.
- Ny tredjeparts-action: legg den til i `TILLATTE_MONSTRE` i `scripts/sett-actions-policy.sh`, i
  Fjetland-IT-policyen, og kjør skriptet på app-repoene.
