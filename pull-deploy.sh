#!/usr/bin/env bash
#
# Oppdaterer appen på vps1 til nyeste bygg fra grenen `deploy`.
#
# Kjøres som app-brukeren i /srv/<app> (en checkout av `deploy`). Startes av
# GitHub Actions over SSH med en nøkkel som i app-brukerens authorized_keys har
#   command="/srv/<app>/pull-deploy.sh",restrict ssh-ed25519 AAAA… actions-<app>
# så nøkkelen bare kan starte dette skriptet, ikke få et skall.
#
# For hånd (som drift):  sudo -u <app> /srv/<app>/pull-deploy.sh
#
# Rører aldri .env eller innholdet i storage/ (utenfor git).
# OPcache har validate_timestamps=1, så PHP-FPM trenger ingen omstart.

set -euo pipefail
umask 027

cd "$(dirname "$(readlink -f "$0")")"

PHP_BIN="$(command -v php8.5 || command -v php)"
LOGG="storage/logs/deploy.log"

logg() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOGG"
}

[ -f .env ] || { echo "FEIL: .env mangler i $(pwd)" >&2; exit 1; }

# Én deploy om gangen.
exec 9>storage/framework/deploy.lock
flock -n 9 || { echo "FEIL: en deploy kjører allerede" >&2; exit 1; }

forrige="$(git rev-parse --short HEAD 2>/dev/null || echo ingen)"

logg "Henter deploy-grenen (nå: $forrige)"
git fetch --quiet origin deploy
git reset --quiet --hard origin/deploy
ny="$(git rev-parse --short HEAD)"
logg "$(git log -1 --format='%h %s')"

[ -L public/storage ] || "$PHP_BIN" artisan storage:link --no-interaction

logg "Migrerer"
"$PHP_BIN" artisan migrate --force --no-interaction

logg "Bygger cache (config, events, ruter, views, Filament)"
"$PHP_BIN" artisan optimize --no-interaction

# Køarbeidere (hvis noen) starter på nytt med ny kode etter jobben de holder på med.
"$PHP_BIN" artisan queue:restart --no-interaction >/dev/null

logg "Ferdig: $forrige -> $ny"
