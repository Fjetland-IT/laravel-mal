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

# --helsesjekk (O13): sjekk /up mot nginx på 127.0.0.1 etter deployen; exit-koden sier om appen svarer.
# Actions sender «deploy --helsesjekk». Med forced command i authorized_keys kommer ordene hit som
# SSH_ORIGINAL_COMMAND; de kan bare slå på sjekken. For hånd: pull-deploy.sh --helsesjekk.
HELSESJEKK=0
read -r -a ORD <<<"${SSH_ORIGINAL_COMMAND:-}" || true
for ord in "$@" ${ORD[@]+"${ORD[@]}"}; do
    if [ "$ord" = "--helsesjekk" ]; then HELSESJEKK=1; fi
done

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
git fetch --quiet --depth=1 origin deploy
git reset --quiet --hard origin/deploy
ny="$(git rev-parse --short HEAD)"
logg "$(git log -1 --format='%h %s')"

# Hvert bygg er en ny orphan-commit med hele vendor og public/build. Uten dette ligger alle
# tidligere bygg igjen i .git (reflogen holder dem i 90 dager), og mappen vokser for hver deploy.
git reflog expire --expire=now --all
git gc --quiet --prune=now

[ -L public/storage ] || "$PHP_BIN" artisan storage:link --no-interaction

logg "Migrerer"
"$PHP_BIN" artisan migrate --force --no-interaction

logg "Bygger cache (config, events, ruter, views, Filament)"
"$PHP_BIN" artisan optimize --no-interaction

# Køarbeidere (hvis noen) starter på nytt med ny kode etter jobben de holder på med.
"$PHP_BIN" artisan queue:restart --no-interaction >/dev/null

# Helsesjekk lokalt (O13): https://<vert fra APP_URL>/up mot nginx på 127.0.0.1, forbi Cloudflare. Bot Fight
# Mode (lite.beer, litepils.no) utfordrer GitHub-runnere og kan ikke unntas på free-plan, så sjekken etter
# deploy gjøres her. Svarer ikke /up 200, avslutter skriptet med 1, og jobben i Actions blir rød.
helsesjekk_lokalt() {
    local url vert kode forsok
    case "$(pwd -P)" in
        /srv/*) ;;
        *) logg "FEIL: helsesjekken mot 127.0.0.1 gjelder bare vps1 (/srv/<app>), ikke $(pwd -P)"; return 1 ;;
    esac
    # shellcheck disable=SC2016  # PHP-koden skal ikke ekspanderes av skallet
    url="$("$PHP_BIN" -r '$c = require "bootstrap/cache/config.php"; echo $c["app"]["url"] ?? "";' 2>/dev/null || true)"
    if [[ ! "$url" =~ ^https://([a-z0-9-]+(\.[a-z0-9-]+)+)/?$ ]]; then
        logg "FEIL: APP_URL «${url}» er ikke https://<vertsnavn>, så helsesjekken vet ikke hvilken vert den skal spørre"
        return 1
    fi
    vert="${BASH_REMATCH[1]}"
    for forsok in 1 2 3 4 5; do
        kode="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
                     --resolve "$vert:443:127.0.0.1" "https://$vert/up" 2>/dev/null || true)"
        if [ "$kode" = "200" ]; then
            logg "Helsesjekk lokalt OK: https://$vert/up svarte 200 via 127.0.0.1 (forsøk $forsok)"
            return 0
        fi
        logg "Helsesjekk lokalt: https://$vert/up svarte ${kode:-000} via 127.0.0.1 (forsøk $forsok av 5)"
        if [ "$forsok" -lt 5 ]; then sleep 3; fi
    done
    logg "FEIL: https://$vert/up svarte ikke 200 via 127.0.0.1 (se /var/log/nginx/ og storage/logs/)"
    return 1
}

if [ "$HELSESJEKK" = 1 ]; then
    helsesjekk_lokalt || exit 1
fi

logg "Ferdig: $forrige -> $ny"
