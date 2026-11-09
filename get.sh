#!/bin/bash
# Fluxus — installazione da GitHub con un solo comando.
#
#     bash <(curl -fsSL https://raw.githubusercontent.com/rufusinfabula/fluxus/HEAD/get.sh)
#
# Chiede a GitHub quali versioni esistono (i branch stabili 0.4, 1.0, 1.1…),
# propone l'ultima e le tre precedenti, e a richiesta l'elenco completo; poi
# scarica il sorgente di quella versione e lancia install.sh.
#
# Opzioni (dopo il comando, così:  bash <(curl …) --version 1.0):
#   --version X.Y   installa quella versione senza mostrare il menu
#                   ('dev' = la versione in sviluppo, non rilasciata)
#   --dir PERCORSO  dove tenere il sorgente (predefinito: /opt/fluxus)
#   --help          questo testo
# Tutte le altre opzioni passano così come sono a install.sh (--instance,
# --dry-run, --yes…).
#
# 'bash <(curl …)' e non 'curl … | bash': con la pipe lo script non avrebbe un
# terminale, e né il menu né l'installer potrebbero chiedere niente. Senza un
# terminale (dentro uno script) si installa l'ultima versione senza domande.

set -u

REPO_URL='https://github.com/rufusinfabula/fluxus.git'
DIR='/opt/fluxus'
VERSIONE=''
OPZIONI=()

if [[ -t 1 ]]; then
    C_TIT=$'\e[1m'; C_OK=$'\e[32m'; C_AVV=$'\e[33m'; C_ERR=$'\e[31m'; C_FIN=$'\e[0m'
else
    C_TIT=''; C_OK=''; C_AVV=''; C_ERR=''; C_FIN=''
fi
muori() { printf '\n%serrore:%s %s\n\n' "$C_ERR" "$C_FIN" "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)   VERSIONE="${2-}"; shift 2 ;;
        --version=*) VERSIONE="${1#*=}"; shift ;;
        --dir)       DIR="${2-}"; shift 2 ;;
        --dir=*)     DIR="${1#*=}"; shift ;;
        --help|-h)   cat <<'FINE'
Fluxus — installazione da GitHub.

    bash <(curl -fsSL https://raw.githubusercontent.com/rufusinfabula/fluxus/HEAD/get.sh) [opzioni]

  --version X.Y   installa quella versione senza mostrare il menu ('dev' = in sviluppo)
  --dir PERCORSO  dove tenere il sorgente (predefinito: /opt/fluxus)
  --help          questo testo
Le altre opzioni passano a install.sh (--instance, --dry-run, --yes…).
FINE
                     exit 0 ;;
        *)           OPZIONI+=("$1"); shift ;;
    esac
done

printf '\n%sFluxus — installazione%s\n' "$C_TIT" "$C_FIN"

# ── Prerequisiti ──────────────────────────────────────────────────────────────

[[ "$(uname -s)" == Linux ]] || muori "Fluxus gira solo su Linux."
command -v apt-get >/dev/null || muori "serve un sistema Debian, Ubuntu o Raspberry Pi OS (apt-get)."

SUDO=''
if [[ $(id -u) -ne 0 ]]; then
    command -v sudo >/dev/null || muori "serve sudo, oppure lancia questo comando come root."
    SUDO='sudo'
fi

if ! command -v git >/dev/null; then
    printf '\ninstallo git…\n'
    $SUDO apt-get update -qq && $SUDO env DEBIAN_FRONTEND=noninteractive \
        apt-get install -y -qq --no-install-recommends git ca-certificates >/dev/null \
        || muori "non sono riuscito a installare git."
fi

# ── Versioni disponibili ──────────────────────────────────────────────────────
#
# Un branch stabile per versione (0.4, 1.0, 1.1…), dal più recente; per
# ciascuno l'ultimo tag vX.Y.Z, cioè la correzione più recente che contiene.

REMOTO=$(git ls-remote --heads --tags "$REPO_URL" 2>/dev/null) \
    || muori "non riesco a raggiungere $REPO_URL: la macchina è collegata a Internet?"
mapfile -t RAMI < <(sed -n 's#.*refs/heads/\([0-9][0-9]*\.[0-9][0-9]*\)$#\1#p' <<< "$REMOTO" | sort -V -r)
mapfile -t TAG < <(sed -n 's#.*refs/tags/v\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)$#\1#p' <<< "$REMOTO" | sort -V)
HA_DEV=0
grep -q 'refs/heads/dev$' <<< "$REMOTO" && HA_DEV=1

[[ ${#RAMI[@]} -gt 0 ]] || muori "su GitHub non trovo nessuna versione rilasciata."

ultima_correzione() {   # 1.0 → 1.0.4 (l'ultimo tag v1.0.*), o vuoto
    local ramo="$1" t ultima=''
    for t in "${TAG[@]}"; do [[ "$t" == "$ramo".* ]] && ultima="$t"; done
    printf '%s' "$ultima"
}

riga() {   # numero, ramo, nota
    local corr
    corr=$(ultima_correzione "$2")
    printf '  %2s) %-5s %-10s %s\n' "$1" "$2" "${corr:+($corr)}" "$3"
}

scegli() {
    local mostra=$(( ${#RAMI[@]} < 4 ? ${#RAMI[@]} : 4 )) tutte=0 i scelta nota max
    while :; do
        if (( tutte )); then
            printf '\nTutte le versioni:\n\n'
            max=${#RAMI[@]}
        else
            printf '\nVersioni disponibili:\n\n'
            max=$mostra
        fi
        for (( i = 0; i < max; i++ )); do
            nota=''
            (( i == 0 )) && nota='ultima, consigliata'
            riga $(( i + 1 )) "${RAMI[$i]}" "$nota"
        done
        if (( tutte )) && (( HA_DEV )); then
            printf '  %2s) %-5s %-10s %s\n' $(( max + 1 )) dev '' \
                "${C_AVV}⚠ in sviluppo: non è una versione rilasciata, solo per prove${C_FIN}"
        fi
        if (( ! tutte )) && (( ${#RAMI[@]} > mostra || HA_DEV )); then
            printf '\n   t) tutte le versioni\n'
        fi
        printf '\nQuale installo? [invio = 1] '
        read -r scelta || scelta=''
        case "$scelta" in
            '')   VERSIONE="${RAMI[0]}"; return ;;
            t|T)  tutte=1; continue ;;
        esac
        if [[ "$scelta" =~ ^[0-9]+$ ]]; then
            if (( scelta >= 1 && scelta <= max )); then VERSIONE="${RAMI[$(( scelta - 1 ))]}"; return; fi
            if (( tutte && HA_DEV && scelta == max + 1 )); then VERSIONE=dev; return; fi
        fi
        printf '%sscelta non valida%s\n' "$C_ERR" "$C_FIN"
    done
}

if [[ -n "$VERSIONE" ]]; then
    if [[ "$VERSIONE" != dev ]] && ! printf '%s\n' "${RAMI[@]}" | grep -qx "$VERSIONE"; then
        muori "la versione '$VERSIONE' non esiste. Disponibili: ${RAMI[*]}"
    fi
elif [[ -t 0 ]]; then
    scegli
else
    VERSIONE="${RAMI[0]}"
fi

if [[ "$VERSIONE" == dev ]]; then
    printf '\n%s⚠  dev è la versione in sviluppo: non è stata rilasciata.%s\n' "$C_AVV" "$C_FIN"
else
    printf '\n→ Fluxus %s%s\n' "$VERSIONE" "$( c=$(ultima_correzione "$VERSIONE"); [[ -n "$c" ]] && printf ' (%s)' "$c" )"
fi

# ── Sorgente ──────────────────────────────────────────────────────────────────
#
# In una cartella di sistema, di root: l'installer copia da qui, e 'sudo fluxus
# update' ci riprende gli aggiornamenti della stessa versione.

if [[ -d "$DIR/.git" ]]; then
    printf 'aggiorno il sorgente in %s…\n' "$DIR"
    $SUDO git -C "$DIR" fetch -q --tags origin \
        && $SUDO git -C "$DIR" checkout -q -B "$VERSIONE" "origin/$VERSIONE" \
        || muori "non riesco ad aggiornare $DIR alla versione $VERSIONE."
elif [[ -e "$DIR" ]]; then
    muori "$DIR esiste già e non è un sorgente di Fluxus: indicane un'altra con --dir."
else
    printf 'scarico il sorgente in %s…\n' "$DIR"
    $SUDO git clone -q --branch "$VERSIONE" "$REPO_URL" "$DIR" \
        || muori "non riesco a scaricare la versione $VERSIONE."
fi

[[ -x "$DIR/install.sh" ]] || muori "in $DIR manca install.sh."

# ── Installazione ─────────────────────────────────────────────────────────────

printf '\n'
exec $SUDO "$DIR/install.sh" "${OPZIONI[@]}"
