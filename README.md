# laravel-mal

Mal for nye Laravel-apper på vps1: Laravel 13, Filament 5, Vite og PHP 8.5
(`config.platform.php = 8.5.0`). App-repoene lages fra malen på `akselfoley`; malen ligger i
`Fjetland-IT`. Se `claude/dash-plan-2026-10-15.md` i prosjektet, fase 4.

## Innhold

| Del | Hvor |
|---|---|
| Filament-panel på `/admin` med tofaktor (TOTP), påkrevd i produksjon | `app/Providers/Filament/AdminPanelProvider.php`, `config/admin.php` |
| MFA-tilpasninger fra amexguiden (fokus på kodefeltet, avvist kode tømmes; Filaments egen auto-innsending fra 5.10) | `app/Filament/Auth/` |
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

## Nytt app-repo fra malen

Normalt gjør veiviseren i dash dette (fase 4, steg 5). For hånd:

1. Lag repoet på `akselfoley` fra malen (privat), med reponavn = hoveddomenet (se planens «Apper og domener»).
2. Sett Actions-policyen **før** første kjøring: `scripts/sett-actions-policy.sh <repo>`.
3. Render `CLAUDE.md.tpl` til `CLAUDE.md`, slett `.tpl`-fila, og sett `name`/`description` i
   `composer.json`. Plassholdere:

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

Valgfri repo-variabel `HELSESJEKK_URL` (f.eks. `https://eurobonus.guide/up`): workflowen sjekker at den
svarer 200 etter deploy. Ikke for verter bak Cloudflare Access (der gir Access en omdirigering).

På serveren, i `~<app>/.ssh/authorized_keys`, slik at nøkkelen bare kan starte deploy:

```
command="/srv/<app>/pull-deploy.sh",restrict ssh-ed25519 AAAA… actions-<app>
```

Serveren henter `deploy` med en egen deploy-nøkkel (bare lesing) på repoet.

## Lokalt

`~/Dev/_oppsett/hent-app.sh <repo>` kloner og setter opp `.env` (SQLite, fil-cache, mail til logg),
`composer install`, `npm ci`, nøkkel, migrering og tester. For hånd:

```sh
composer install
npm ci && npm run build
cp .env.example .env    # og sett lokale verdier, se toppen av fila
php artisan key:generate
php artisan migrate
php artisan test
php artisan make:filament-user
```

## Vedlikehold av malen

- Oppdateringer kommer som Dependabot-PR-er. Endringer i malen går ikke automatisk til apper som er laget
  fra den; ta dem over for hånd ved behov.
- Ny tredjeparts-action: legg den til i `TILLATTE_MONSTRE` i `scripts/sett-actions-policy.sh`, i
  Fjetland-IT-policyen, og kjør skriptet på app-repoene.
