#!/bin/bash
# Fluxus — configurazione dell'istanza, per gli script.
#
# Ogni script comincia con:
#
#     source "$(dirname "${BASH_SOURCE[0]}")/fluxus-env.sh"
#
# e da lì in poi trova FM_BASE, FM_DB, FM_LOGS, FM_TMP e le altre già
# valorizzate, come se fossero scritte in cima allo script come una volta.
#
# ── Come trova la configurazione ──────────────────────────────────────────────
#
# 1. $FLUXUS_CONF, se impostata. È così che la passano i timer systemd e
#    l'applicazione quando lanciano uno script.
# 2. altrimenti <cartella dati>/fluxus.conf, risalendo di un livello dalla
#    propria posizione: gli script vivono in <cartella dati>/scripts, quindi la
#    trovano da soli. Quel file è un collegamento a /etc/fluxus/<istanza>.conf,
#    creato dall'installer.
#
# Questo secondo punto è il motivo per cui uno script lanciato a mano da un
# terminale, uno lanciato da systemd e uno lanciato da PHP finiscono sempre
# sulla stessa istanza, che è poi quella a cui appartengono.
#
# Se non la trova, esce con un errore invece di ripiegare su un valore
# predefinito: su una macchina con due installazioni, un percorso indovinato
# scriverebbe nella cartella sbagliata.
#
# ── Perché non è un 'source' ──────────────────────────────────────────────────
#
# Il file di configurazione viene letto, non eseguito. Con 'source', una riga
# come  FLUXUS_HW_ENCODER_OPTS=-preset veryfast -crf 23  farebbe eseguire a
# bash il comando '-crf', e ogni valore contenente uno spazio andrebbe messo
# fra virgolette per non rompere tutto. Leggendolo invece riga per riga i
# valori restano letterali, ed è esattamente ciò che fa anche il lettore PHP:
# uno stesso file, interpretato allo stesso modo dai due lati.

fluxus_die() { printf 'fluxus-env.sh: %s\n' "$*" >&2; exit 78; }   # 78 = EX_CONFIG

# ── Individuazione del file ───────────────────────────────────────────────────

if [[ -z "${FLUXUS_CONF:-}" ]]; then
    _fx_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd) \
        || fluxus_die "non riesco a risalire alla cartella dati da ${BASH_SOURCE[0]}"
    FLUXUS_CONF="$_fx_dir/fluxus.conf"
    unset _fx_dir
fi

[[ -r "$FLUXUS_CONF" ]] || fluxus_die "configurazione non leggibile: $FLUXUS_CONF"

# ── Lettura ───────────────────────────────────────────────────────────────────
# Righe CHIAVE=valore, commenti con '#', valore letterale fino a fine riga.
# Si accettano solo le chiavi FLUXUS_*: tutto il resto viene ignorato in
# silenzio, così il file non può ridefinire PATH, IFS o altro.

fluxus_read_conf() {
    local file="$1" line key value
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        line="${line#"${line%%[![:space:]]*}"}"          # via gli spazi iniziali
        [[ -z "$line" || "$line" == '#'* ]] && continue  # riga vuota o commento
        [[ "$line" == *=* ]] || continue
        key="${line%%=*}"
        value="${line#*=}"
        key="${key%"${key##*[![:space:]]}"}"
        [[ "$key" =~ ^FLUXUS_[A-Z0-9_]+$ ]] || continue
        value="${value#"${value%%[![:space:]]*}"}"; value="${value%"${value##*[![:space:]]}"}"
        # Le virgolette esterne servono solo a conservare spazi in testa o in
        # coda: si tolgono, non si interpreta nient'altro.
        if [[ ${#value} -ge 2 ]]; then
            case "$value" in
                \"*\"|\'*\') value="${value:1:${#value}-2}" ;;
            esac
        fi
        printf -v "$key" '%s' "$value"
    done < "$file"
}

fluxus_read_conf "$FLUXUS_CONF"

# ── Valori derivati ───────────────────────────────────────────────────────────
# Una chiave assente si ricava dal nome dell'istanza, con la stessa regola
# documentata in config/fluxus.conf.example e applicata dal lettore PHP.

[[ -n "${FLUXUS_INSTANCE:-}" ]] || fluxus_die "FLUXUS_INSTANCE mancante in $FLUXUS_CONF"

FLUXUS_APP_NAME="${FLUXUS_APP_NAME:-Fluxus}"
FLUXUS_DATA_DIR="${FLUXUS_DATA_DIR:-/var/lib/$FLUXUS_INSTANCE}"
FLUXUS_WEB_DIR="${FLUXUS_WEB_DIR:-/var/www/$FLUXUS_INSTANCE}"
FLUXUS_WEB_BASE="${FLUXUS_WEB_BASE-/$FLUXUS_INSTANCE}"     # può essere vuoto: '-' e non ':-'
FLUXUS_USER="${FLUXUS_USER:-www-data}"
FLUXUS_GROUP="${FLUXUS_GROUP:-www-data}"
FLUXUS_UNIT_PREFIX="${FLUXUS_UNIT_PREFIX:-$FLUXUS_INSTANCE}"
FLUXUS_RTMP_HOST="${FLUXUS_RTMP_HOST:-127.0.0.1}"
FLUXUS_RTMP_PORT="${FLUXUS_RTMP_PORT:-1935}"
FLUXUS_MEDIAMTX_API="${FLUXUS_MEDIAMTX_API:-http://127.0.0.1:9997}"
FLUXUS_HW_ENCODER="${FLUXUS_HW_ENCODER:-libx264}"
FLUXUS_HW_ENCODER_OPTS="${FLUXUS_HW_ENCODER_OPTS:--preset veryfast -crf 23}"

# ── Nomi storici ──────────────────────────────────────────────────────────────
# Gli script parlano da sempre di FM_BASE, FM_DB, FM_LOGS: restano quelli, così
# il corpo degli script non cambia. Le cartelle sotto la radice dati non sono
# configurabili — sono la struttura, non una scelta.
#
# Il nome del file di database resta 'fluxus_media.db' anche per le istanze che
# si chiamano diversamente: sta già dentro una cartella dati per istanza, non
# può collidere con nulla, e cambiarlo renderebbe illeggibile ogni installazione
# esistente senza alcun vantaggio.

FM_INSTANCE="$FLUXUS_INSTANCE"
FM_BASE="$FLUXUS_DATA_DIR"
FM_DB="$FM_BASE/db/fluxus_media.db"
FM_RECORDINGS="$FM_BASE/recordings"
FM_CLIPS="$FM_BASE/clips"
FM_TMP="$FM_BASE/tmp"
FM_LOGS="$FM_BASE/logs"
FM_SCRIPTS="$FM_BASE/scripts"
FM_WEB_DIR="$FLUXUS_WEB_DIR"
FM_UNIT_PREFIX="$FLUXUS_UNIT_PREFIX"

# Gli script lanciati da qui dentro (record.sh chiamato da run_schedule.sh)
# devono restare sulla stessa istanza anche se qualcuno ha spostato le cartelle.
export FLUXUS_CONF

# Indirizzo di ingresso di una sorgente rtmp-push, dato l'id o il percorso.
fluxus_rtmp_url() {
    printf 'rtmp://%s:%s/%s' "$FLUXUS_RTMP_HOST" "$FLUXUS_RTMP_PORT" "$1"
}
