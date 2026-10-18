<!-- Generert fra CLAUDE.md.tpl i Fjetland-IT/laravel-mal. Dash oppdaterer fila når domener eller
     roller endres, alltid som commit Aksel ser diffen av. Endre gjerne for hånd; dash viser avvik. -->
# CLAUDE.md – {{VISNINGSNAVN}}

## App og domener
- Teknisk appnavn: `{{APP}}` (systembruker, database, PHP-FPM-pool og Valkey-prefiks). Fast.
- Repo: `{{REPO}}`, lokal mappe `~/Dev/{{REPO_NAVN}}`.
- Hoveddomene: **{{HOVEDDOMENE}}** (APP_URL, canonical).
- Domener og roller (*hoveddomene*, *alias* = 301 til hoveddomenet, *eget innhold* = appen svarer selv):
{{DOMENER}}

## Produksjon (vps1)
- Gigahost-VPS `vps1` (Ubuntu 26.04, PHP 8.5-FPM, nginx, MySQL 8, Valkey), bak Cloudflare.
  Origin svarer bare Cloudflares IP-områder på 80/443.
- Sti `/srv/{{APP}}`, systembruker `{{APP}}`, egen PHP-FPM-pool `{{APP}}`.
- Database: `{{DATABASE}}`. Valkey: ACL-bruker `{{APP}}`, nøkkelprefiks `{{APP}}:`, unix-socket
  `/run/valkey/valkey.sock`. Passord står bare i `/srv/{{APP}}/.env` på serveren (chmod 600).
- Cloudflare-soner: {{CF_SONER}}
- Sertifikat fra Let's Encrypt (DNS-01), ett per domene, fornyes av `certbot.timer`.
- Cron hvert minutt: `php artisan schedule:run` som `{{APP}}`.
- Prod-`.env` finnes bare på VPS-en og endres i dash eller via SSH. Ingen prod-hemmeligheter på Macen
  eller i repoet.

## Deploy
`main` → GitHub Actions (`.github/workflows/deploy.yml`: composer audit, npm audit, tester) → orphan-grenen
`deploy` (prod-vendor + `public/build`) → SSH til vps1, der nøkkelen bare kan kjøre `pull-deploy.sh`
(`git reset --hard origin/deploy`, `migrate --force`, `optimize`).
- Alle `uses:` er pinnet til full commit-SHA med versjonskommentar; repoet krever det. Dependabot
  oppdaterer pinnene. Nye tredjeparts-actions legger Aksel til i Actions-policyen.
- Rollback: `git revert` på `main` og push, eller kjør workflowen manuelt fra en eldre commit.
- Status, pakker og varsler: {{DASH_URL}}

## Lokalt (Macen)
- `~/Dev/{{REPO_NAVN}}`, satt opp med `~/Dev/_oppsett/hent-app.sh {{REPO_NAVN}}`.
- **Utviklingsmiljøet velges per app i `.utvikling`** i repoet: `docker` eller `mac`. Mangler fila,
  gjelder `mac`. Les fila før du kjører noe lokalt. Valget settes med `hent-app.sh {{REPO_NAVN}} --docker`
  (eller `--mac`) og committes som en egen endring.
- **`docker`:** alt kjører i containere som ligner vps1 (PHP 8.5-FPM + nginx, MySQL 8.4, Valkey på
  socket med samme ACL som på vps1, Node 24 for Vite med HMR, Mailpit for e-post). Bruk `./dev`:
  `./dev up`, `./dev down`, `./dev artisan …`, `./dev composer …`, `./dev npm …`, `./dev test` (mot MySQL,
  databasen `<DB_DATABASE>_test`), `./dev logs`, `./dev porter`. Adressene står i `.env` (`APP_PORT`,
  `VITE_PORT`, `MAILPIT_PORT`) og er bare på 127.0.0.1. Kjør ikke `php`, `composer` eller `npm.sh` på Macen
  for denne appen.
- **`mac`:** PHP 8.5 og Composer på Macen; lokal `.env` bruker SQLite, fil-cache og mail til logg.
  Tester: `php artisan test`. Node er ikke installert på Macen: npm kjøres i Docker (`node:24`, samme major
  som Actions) med `~/Dev/_oppsett/npm.sh`, f.eks. `npm.sh ci`, `npm.sh run build` og `npm.sh run dev`
  (Vite på `localhost:5173`). `node_modules` har Linux-binærer.
- Docker og Composer kjører på Macen. Packagist og Docker Hub er blokkert fra Claude-skyen, og sky-VM-en
  har ikke Docker: gi Aksel kommandoene (`./dev …`) i stedet for å kjøre dem der.
- Actions tester fortsatt med SQLite (`phpunit.xml`); `./dev test` kjører de samme testene mot MySQL.
- Utkast, forhåndsvisninger, logger og engangsskript legges i `Claude outputs/` (gitignorert).

## Regler
- Norsk i UI, kommentarer og commit-meldinger.
- `config.platform.php` i `composer.json` er låst til produksjonens PHP (8.5). Ikke endre den for å få
  inn en pakke.
- Ingen push, deploy eller prod-endringer uten at Aksel har godkjent. Claude committer; Aksel ser diffen
  og pusher.
- Ingen hemmeligheter i repoet eller i chatten.
- Ved Filament-oppgradering: sjekk `app/Filament/Auth/` mot ny innebygd oppførsel
  (`tests/Feature/AdminMfaAutoSubmitTest.php`).

## E-post fra terminalen: `mailmd`
Aksel sender egne e-poster med `mailmd` (`~/.local/bin/mailmd`), som sender Markdown via mail.1kb.no og
legger en kopi i Sendte elementer. E-post appen selv sender, går via Laravel Mail og SMTP2GO.
- Standard avsender i dette repoet: `-f {{MAILMD_FRA}}`.
- Skriv meldingen i `Claude outputs/epost/ÅÅÅÅ-MM-DD-<emne>.md` med hodeblokk (`Fra:`, `Til:`, `Emne:`),
  tom linje og brødtekst. Vis utkastet; send **aldri** uten at Aksel har godkjent akkurat den meldingen.
- Send med `mailmd -y "Claude outputs/epost/<fil>.md"`. I en sky-VM virker ikke `mailmd` (passordene
  ligger i macOS-nøkkelringen): lag fila og gi Aksel kommandoen.

## Lenker
- Dash: {{DASH_URL}}
- Prosjektdokumenter: claude.ai-prosjektet «Websecurity & global functions» (`claude/dash-plan-2026-10-15.md`).
