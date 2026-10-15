#!/usr/bin/env bash
#
# Setter GitHub Actions-policyen på et app-repo, tilsvarende Fjetland-IT-organisasjonens:
#   - bare actions fra GitHub, verifiserte skapere og shivammathur/setup-php@*
#   - alle actions må være pinnet til full commit-SHA
#   - GITHUB_TOKEN er bare-les som standard (workflows ber selv om mer med permissions:)
#   - Actions kan ikke godkjenne pull requests
#
# Bruk (på Macen, med gh innlogget som akselfoley):
#   scripts/sett-actions-policy.sh <repo>          # eier akselfoley
#   scripts/sett-actions-policy.sh <eier>/<repo>
#
# Kjøres på hvert app-repo som lages fra malen, før første workflow-kjøring.
# Malen selv (Fjetland-IT/laravel-mal) dekkes av organisasjonens policy.

set -euo pipefail

STANDARD_EIER="akselfoley"
TILLATTE_MONSTRE=("shivammathur/setup-php@*")

if [ $# -ne 1 ]; then
    echo "Bruk: $0 <repo> | <eier>/<repo>" >&2
    exit 64
fi

repo="$1"
[[ "$repo" == */* ]] || repo="$STANDARD_EIER/$repo"

if ! [[ "$repo" =~ ^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$ ]]; then
    echo "FEIL: ugyldig reponavn: $repo" >&2
    exit 64
fi

command -v gh >/dev/null || { echo "FEIL: gh er ikke installert (brew install gh)" >&2; exit 69; }
command -v jq >/dev/null || { echo "FEIL: jq er ikke installert (brew install jq)" >&2; exit 69; }

echo "==> $repo"
gh api "repos/$repo" --jq '"    \(.full_name) (\(.visibility))"'

echo "==> Tillatte actions: utvalgte, SHA-pinning påkrevd"
gh api -X PUT "repos/$repo/actions/permissions" \
    -F enabled=true \
    -f allowed_actions=selected \
    -F sha_pinning_required=true

echo "==> Utvalg: GitHub, verifiserte skapere, ${TILLATTE_MONSTRE[*]}"
jq -n --args '{github_owned_allowed: true, verified_allowed: true, patterns_allowed: $ARGS.positional}' \
    "${TILLATTE_MONSTRE[@]}" |
    gh api -X PUT "repos/$repo/actions/permissions/selected-actions" --input -

echo "==> GITHUB_TOKEN bare-les, Actions kan ikke godkjenne PR-er"
gh api -X PUT "repos/$repo/actions/permissions/workflow" \
    -f default_workflow_permissions=read \
    -F can_approve_pull_request_reviews=false

echo "==> Kontroll"
feil=0
sjekk() { # <beskrivelse> <faktisk> <forventet>
    if [ "$2" = "$3" ]; then
        printf '    OK    %s\n' "$1"
    else
        printf '    FEIL  %s: %s (forventet %s)\n' "$1" "$2" "$3"
        feil=1
    fi
}

perm="$(gh api "repos/$repo/actions/permissions")"
sel="$(gh api "repos/$repo/actions/permissions/selected-actions")"
wf="$(gh api "repos/$repo/actions/permissions/workflow")"

sjekk "Actions på" "$(jq -r .enabled <<<"$perm")" true
sjekk "allowed_actions" "$(jq -r .allowed_actions <<<"$perm")" selected
sjekk "SHA-pinning påkrevd" "$(jq -r '.sha_pinning_required // "mangler"' <<<"$perm")" true
sjekk "GitHub-eide actions" "$(jq -r .github_owned_allowed <<<"$sel")" true
sjekk "Verifiserte skapere" "$(jq -r .verified_allowed <<<"$sel")" true
sjekk "Mønstre" "$(jq -r '.patterns_allowed | sort | join(",")' <<<"$sel")" \
    "$(printf '%s\n' "${TILLATTE_MONSTRE[@]}" | sort | paste -sd, -)"
sjekk "Standard token-rettighet" "$(jq -r .default_workflow_permissions <<<"$wf")" read
sjekk "Actions kan godkjenne PR-er" "$(jq -r .can_approve_pull_request_reviews <<<"$wf")" false

if [ "$feil" -ne 0 ]; then
    echo "Policyen ble ikke satt som forventet. Sjekk Settings → Actions → General på repoet." >&2
    exit 1
fi
echo "Ferdig."
