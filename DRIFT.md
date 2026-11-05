# DRIFT.md – denne appen i produksjon på vps1

Gjelder foran alt annet om prod, deploy og database. Endringer i serveroppsettet gjøres i prosjektet
«Websecurity & global functions»; denne fila speiler det som gjelder for appen. Fellesdelen vedlikeholdes i
`Fjetland-IT/laravel-mal` og kopieres til appene; appens egen del fylles ut under.

## Felles for alle appene på vps1

**Produksjon er vps1** (Gigahost, Ubuntu 26.04, PHP 8.5-FPM, nginx, MySQL 8.4, Valkey), bak Cloudflare.
Domeneshop-webhotellet er sagt opp (25. okt 2026) og finnes ikke. Foreslå aldri `lite@login.domeneshop.no`,
`litebeer01`/`litebeer02` eller andre Domeneshop-kommandoer. Alt som fortsatt nevner Domeneshop i dette repoet
(skript, kommentarer, CLAUDE.md) er utdatert og skal fjernes når det treffes.

**Appen på serveren**
- Ligger i `/srv/<app>`, kjører som systembrukeren `<app>` med egen PHP-FPM-pool og egen MySQL-bruker.
- Aksel logger inn som `drift@45.88.201.138` (sudo). Artisan i prod kjøres som appbrukeren:
  `ssh drift@45.88.201.138 'sudo -u <app> php /srv/<app>/artisan <kommando>'`. Skriv aldri `ssh <app>@vps1` eller `ssh vps1`.
- Prod-`.env` finnes bare på vps1 (`/srv/<app>/.env`, 600). Hemmeligheter legges inn, byttes og fjernes av
  Aksel på **https://port.fjetland.it/nokler**; plattformverdier (`APP_*`, `DB_*`, `REDIS_*`, `SESSION_*`,
  `CACHE_*`, `QUEUE_*`, `LOG_*`, `MAIL_FROM_ADDRESS`) endres bare over SSH av Aksel. Nye miljøvariabler:
  legg dem i `.env.example` med kommentar og i CLAUDE.md, så legger Aksel dem inn på Nøkler.
  `env-push-prod.sh` nekter mot vps1.
- Alle appene kjører `QUEUE_CONNECTION=sync`, cache og sesjoner i Valkey, e-post via SMTP2GO.

**Deploy skjer av seg selv etter `git push` til `main`.** Det finnes ingen manuelle steg etterpå.
- GitHub Actions kjører testene, bygger grenen `deploy`, og jobben «Deploy til vps1» kjører `pull-deploy.sh
  --helsesjekk` på serveren (låst SSH-nøkkel) og sjekker `/up`. Grønn jobb = deployen er ute.
- `pull-deploy.sh` kjører `migrate --force`, `config:cache` osv. **Seeders kjøres ikke.** Data som skal inn i
  prod legges derfor i en migrering (idempotent), ikke i en seeder man «husker å kjøre». Må noe likevel kjøres
  for hånd, si det eksplisitt som et manuelt steg med `sudo -u <app>`-formen over, og forklar hvorfor det ikke
  kan være en migrering.
- Ny deploy uten ny commit: «Kjør deploy på nytt» på https://port.fjetland.it/handlinger, eller
  `gh workflow run deploy.yml -R akselfoley/<repo>`. Endringer i `pull-deploy.sh` virker først fra neste deploy.
- **Push gjør Aksel.** Claude committer (`git add <fil>`, aldri `git add -A`) og skriver aldri «kjør dette på
  serveren etter push».
- Ta alltid backup («Ta backup nå» i port) før en migrering som sletter eller endrer data.

**Cron** kjører `schedule:run` hvert minutt. Ingen timinutters-begrensning lenger.

**Cache**: `FLUSHDB` er nektet i Valkey, så `php artisan cache:clear` feiler i prod. Bruk `Cache::forget(...)`,
eller tøm Cloudflare-cachen fra port.

**Backup**: port tar databasekopi hver natt 03:30 (`/var/backups/apper/<app>/`, 14 dager), med off-site kopi
til Cloudflare R2, kryptert med age på vps1 før opplasting (privat nøkkel bare på Macen). Databaser i
`vps1-backup` (EU-jurisdiksjon, bucket lock), `storage/app/public` i `vps1-media` (Europa, bucket lock).
Status på https://port.fjetland.it/backup. Personverntekst: databehandlere er Gigahost AS, Cloudflare, Inc.
(USA; DPF + SCC) og SMTP2GO (New Zealand; adekvansvedtak); sikkerhetskopier lagres kryptert i EU.
Ferske prod-data lokalt hentes fra nattkopien:
`ssh drift@45.88.201.138 'sudo bash -c "gzip -cd \$(ls -1 /var/backups/apper/<app>/*.sql.gz | tail -1)"' > _ignore/vps1-siste.sql`

**Overvåking** er i **https://port.fjetland.it** (tidligere dash.fjetland.it): oppetid, deployet commit mot
`main`, sårbare/utdaterte pakker, sertifikater, brannmur, backup, besøk, varsler, nøkler og en driftsterminal på
`/terminal`. `miljo:sjekk`, system-status-sider og egne backupskript i appene er fjernet – ikke lag dem på nytt.
Besøksstatistikk finnes både i appen og i port.

**Logger**: `/srv/<app>/storage/logs/laravel.log` og `storage/logs/deploy.log`, leses med
`ssh drift@45.88.201.138 'sudo -u <app> tail -50 /srv/<app>/storage/logs/laravel.log'`.

**Tofaktor** (apper med Filament): flere autentiseringsapper per bruker (`mfa_apper`); mistet alle →
`mfa:nullstill <e-post>` som appbrukeren. «Kjent enhet» er en HMAC-cookie (180 dager) på
`ADMIN_KNOWN_DEVICE_TOKEN`; `/admin/kjent-enhet` åpnes på nytt per enhet etter rotasjon.

**Cloudflare**: alle soner på free-plan, proxied. Claude kan ikke hente sidene fra chatten (challenge/Access);
verifiser prod gjennom Aksel eller port, ikke ved å gjette.

**PHP-versjon** i Actions og `config.platform.php`: endres ikke uten at Aksel sier fra.

**Kommandoer til Terminal** gis uten `#`-kommentarer (zsh). vps1 nås på IP, ikke ved navn: `ssh drift@45.88.201.138`.

Endringer i serveroppsett, nginx, Cloudflare eller port gjøres i prosjektet «Websecurity & global functions»,
ikke her. Trenger appen noe nytt fra drift (kø, opplasting, cron, eksterne API-er, nye vertsnavn), noter det i
CLAUDE.md og si fra.

## Spesielt for denne appen

- App og bruker `<app>`, database `<app>`, sti `/srv/<app>`, repo `akselfoley/<repo>` (fylles ut).
- Vertsnavn, Cloudflare-regler, opplastingsgrenser, delte databaser og egne prod-skript noteres her.
