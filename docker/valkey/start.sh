#!/bin/sh
# Starter Valkey slik vps1 kjører det: bare unix-socket (/run/valkey/valkey.sock), ingen TCP,
# default-brukeren av, og én ACL-bruker for appen med samme regel som app-brukerne på vps1
# (Fjetland-IT/vps, app-tjenester.sh): bare egne nøkler, ingen FLUSHDB/KEYS/SCAN. Da feiler det samme
# lokalt som i prod, f.eks. cache:clear mot Valkey.
#
# Kjøres som root i containeren (compose.yaml, entrypoint) og gir fra seg rettighetene via bildets
# docker-entrypoint.sh. Brukernavn, passord og prefiks kommer fra den lokale .env.
set -eu

case "${REDIS_USERNAME:-}" in
    ''|*[!a-z0-9_-]*) echo "start.sh: ugyldig REDIS_USERNAME" >&2; exit 1 ;;
esac
case "${REDIS_PASSWORD:-}" in
    ''|*[!A-Za-z0-9]*) echo "start.sh: REDIS_PASSWORD må være bokstaver og tall (lokalt passord)" >&2; exit 1 ;;
esac
case "${REDIS_PREFIX:-}" in
    ''|*[!a-z0-9_:.-]*) echo "start.sh: ugyldig REDIS_PREFIX" >&2; exit 1 ;;
esac

install -d -o valkey -g valkey -m 0755 /run/valkey

konf=/etc/valkey-lokal.conf
umask 077
cat >"$konf" <<KONF
port 0
unixsocket /run/valkey/valkey.sock
unixsocketperm 777
maxmemory 128mb
maxmemory-policy volatile-lru
user default off
user ${REDIS_USERNAME} on >${REDIS_PASSWORD} ~${REDIS_PREFIX}* &* -@all +@read +@write +@set +@sortedset +@list +@hash +@string +@bitmap +@hyperloglog +@geo +@stream +@keyspace +@connection +@transaction +@scripting +@pubsub -@dangerous -flushall -flushdb -keys -scan -randomkey -swapdb
KONF
chown valkey:valkey "$konf"

exec docker-entrypoint.sh valkey-server "$konf"
