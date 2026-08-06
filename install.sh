#!/bin/bash
# Fluxus — installer.
#
#     sudo ./install.sh                            installa con i valori predefiniti
#     sudo ./install.sh --instance fluxus-dev      installa un'istanza di collaudo
#     sudo ./install.sh --dry-run                  mostra cosa farebbe, senza fare niente
#     ./install.sh --help                          l'elenco delle opzioni
#
# Installa dipendenze, cartelle, applicazione, servizi, permessi, server web e
# server RTMP; inizializza il database; stampa alla fine l'indirizzo a cui
# collegarsi. Deposita anche il comando 'fluxus', con cui poi si governa
# l'installazione (stato, aggiornamento, backup, ripristino, log, rimozione).
#
# ── Rieseguibile ──────────────────────────────────────────────────────────────
#
# Rilanciarlo sulla stessa istanza è il modo normale di aggiornarla, ed è ciò
# che fa 'fluxus update'. Alla riesecuzione i valori che non vengono ripetuti
# sulla riga di comando si rileggono dalla configurazione esistente, quindi
# nessuna scelta si perde per strada. Non tocca mai il database, le
# registrazioni, le clip, i log e i segreti del relay: li lascia dove sono e
# lascia che sia l'applicazione ad applicare le eventuali migrazioni.
#
# ── Convivenza con un'installazione già in servizio ───────────────────────────
#
# Su una macchina possono esserci più installazioni — è esattamente il motivo
# per cui esiste il nome dell'istanza (vedi la sezione "Configurazione
# dell'istanza" nelle note tecniche). Da qui le guardie, che sono la parte più
# importante di questo script:
#
#   · non scrive dentro una radice web o una cartella dati che esistono già e
#     non appartengono all'istanza che sta installando;
#   · non tocca unit systemd con un prefisso che non ha installato lui;
#   · si ferma se l'istanza bersaglio ha una registrazione in corso;
#   · apre il database solo come utente di Fluxus, mai come root: è in modalità
#     WAL, e i file -wal/-shm prendono il proprietario di chi scrive per primo;
#   · di nginx fa una copia di sicurezza, verifica con 'nginx -t' e ripristina
#     da solo se la verifica non passa; ricarica, non riavvia;
#   · non riscrive la configurazione di un MediaMTX condiviso già presente.
#
# ── Il sorgente ───────────────────────────────────────────────────────────────
#
# Lo script lavora sul repository in cui si trova. Se lo si copia altrove, il
# sorgente va indicato con --source. La forma 'curl … | sudo bash' della roadmap
# arriverà quando il repository sarà pubblico: oggi non ci sarebbe niente da
# scaricare senza credenziali.

set -euo pipefail
export LC_ALL=C
# /usr/local/bin serve: è lì che stanno mediamtx e i comandi di Fluxus.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

VERSIONE_INSTALLER=$(cat "$(dirname -- "${BASH_SOURCE[0]}")/VERSION" 2>/dev/null || echo sconosciuta)

# ── Uscite a video ────────────────────────────────────────────────────────────

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    C_TIT=$'\033[1m'; C_OK=$'\033[32m'; C_AVV=$'\033[33m'; C_ERR=$'\033[31m'; C_FIN=$'\033[0m'
else
    C_TIT=''; C_OK=''; C_AVV=''; C_ERR=''; C_FIN=''
fi

SILENZIOSO=0
passo()  { (( SILENZIOSO )) || printf '\n%s▸ %s%s\n' "$C_TIT" "$*" "$C_FIN"; }
dice()   { (( SILENZIOSO )) || printf '  %s\n' "$*"; }
fatto()  { (( SILENZIOSO )) || printf '  %s✓%s %s\n' "$C_OK" "$C_FIN" "$*"; }
avvisa() { printf '  %s⚠%s  %s\n' "$C_AVV" "$C_FIN" "$*" >&2; }
muori()  { printf '\n%serrore:%s %s\n\n' "$C_ERR" "$C_FIN" "$*" >&2; exit 1; }

AVVISI=()
segna_avviso() { AVVISI+=("$1"); avvisa "$1"; }

# ── Esecuzione, con prova a vuoto ─────────────────────────────────────────────
# Con --dry-run nessuna di queste funzioni tocca il sistema: stampano soltanto.

PROVA=0

esegui() {
    if (( PROVA )); then printf '    · %s\n' "$*"; return 0; fi
    "$@"
}

# scrivi_file <destinazione> <modo> <proprietario:gruppo>   < contenuto
# Scrive il contenuto che arriva da stdin, con modo e proprietario stabiliti.
scrivi_file() {
    local dest="$1" modo="$2" propr="$3" tmp
    tmp=$(mktemp) || muori "non riesco a creare un file temporaneo"
    cat > "$tmp"
    if (( PROVA )); then
        printf '    · scriverei %s (%s %s, %s byte)\n' "$dest" "$modo" "$propr" "$(wc -c < "$tmp")"
        rm -f "$tmp"
        return 0
    fi
    install -D -m "$modo" -o "${propr%%:*}" -g "${propr##*:}" "$tmp" "$dest"
    rm -f "$tmp"
}

# ── Resa dei modelli ──────────────────────────────────────────────────────────
# I modelli .in portano segnaposto @NOME@. Qui si sostituiscono con i valori
# dell'istanza. Il file reso porta in testa una riga che dice da dove viene: chi
# lo trova fra sei mesi deve capire subito che modificarlo a mano non serve.

declare -A SEGNAPOSTO

# Protegge un valore perché sed lo tratti come testo, non come espressione.
per_sed() { printf '%s' "$1" | sed -e 's/[\\&|]/\\&/g'; }

rendi() {
    local modello="$1" chiave script=''
    [[ -r "$modello" ]] || muori "modello non leggibile: $modello"
    for chiave in "${!SEGNAPOSTO[@]}"; do
        script+="s|@${chiave}@|$(per_sed "${SEGNAPOSTO[$chiave]}")|g;"
    done
    printf '# Generato da install.sh (Fluxus %s) per l'\''istanza %s, %s.\n' \
        "$VERSIONE_INSTALLER" "$ISTANZA" "$(date '+%Y-%m-%d %H:%M')"
    printf '# Modificarlo a mano non serve: il prossimo aggiornamento lo riscrive.\n#\n'
    sed -e "$script" "$modello"
}

# ── Domande ───────────────────────────────────────────────────────────────────
# Si chiede solo se c'è davvero qualcuno a rispondere. Lanciato da uno script,
# da cron o in una pipe, prosegue con i valori predefiniti senza fermarsi mai:
# è la modalità non interattiva richiesta dalla roadmap, e non ha bisogno di
# un'opzione apposita per attivarsi.

AUTOMATICO=0
TTY=''

apri_tty() {
    (( AUTOMATICO )) && return 1
    [[ -n "$TTY" ]] && return 0
    if [[ -r /dev/tty && -w /dev/tty ]] && : > /dev/tty 2>/dev/null; then TTY=/dev/tty; return 0; fi
    return 1
}

# chiedi <domanda> <valore predefinito>  → risposta su stdout
chiedi() {
    local domanda="$1" predef="$2" risposta
    if ! apri_tty; then printf '%s' "$predef"; return 0; fi
    printf '  %s [%s]: ' "$domanda" "$predef" > "$TTY"
    IFS= read -r risposta < "$TTY" || risposta=''
    printf '%s' "${risposta:-$predef}"
}

# conferma <domanda>  → 0 se sì
conferma() {
    local risposta
    if ! apri_tty; then return 0; fi
    printf '  %s [S/n]: ' "$1" > "$TTY"
    IFS= read -r risposta < "$TTY" || risposta=''
    [[ -z "$risposta" || "$risposta" =~ ^[SsYy]$ ]]
}

# ── Opzioni ───────────────────────────────────────────────────────────────────

aiuto() {
    cat <<'FINE'
Fluxus — installer.

    sudo ./install.sh [opzioni]

Identità e percorsi
  --instance NOME        nome dell'istanza (predefinito: fluxus). Da qui
                         derivano percorsi, servizi, configurazione e permessi
  --app-name NOME        nome mostrato nell'interfaccia (predefinito: Fluxus)
  --data-dir PERCORSO    cartella dati      (predefinito: /var/lib/<istanza>)
  --web-dir PERCORSO     radice web         (predefinito: /var/www/<istanza>)
  --web-base PERCORSO    sottopercorso web  (predefinito: /<istanza>;
                         vuoto = radice del vhost)
  --user UTENTE          utente di sistema  (predefinito: www-data)
  --group GRUPPO         gruppo di sistema  (predefinito: come l'utente)
  --unit-prefix PREFISSO prefisso dei servizi systemd (predefinito: <istanza>)

Server web
  --nginx MODO           auto  inserisce l'inclusione nel vhost (predefinito)
                         none  scrive solo lo snippet e stampa la riga da aggiungere
  --nginx-vhost FILE     vhost in cui inserire l'inclusione
                         (predefinito: /etc/nginx/sites-available/default)
  --php-fpm-sock FILE    socket di PHP-FPM (predefinito: rilevato)

Server RTMP (MediaMTX)
  --mediamtx MODO        instance  un MediaMTX dedicato all'istanza (predefinito)
                         none      non se ne occupa
  --rtmp-port PORTA      porta RTMP di ingresso (predefinito: la prima libera da 1935)
  --mediamtx-api-port N  porta dell'API di controllo (predefinito: la prima libera da 9997)
  --mediamtx-binary FILE eseguibile mediamtx (predefinito: rilevato o scaricato)

Altro
  --source PERCORSO      sorgente da installare (predefinito: la cartella di questo script)
  --volume-helper MODO   keep      non tocca /usr/local/bin/fluxus-enable-volume.sh
                                   se è diverso da quello del sorgente (predefinito)
                         overwrite lo sostituisce, con copia di sicurezza
  --network-helper MODO  keep      non tocca /usr/local/bin/fluxus-network.sh
                                   se è diverso da quello del sorgente (predefinito)
                         overwrite lo sostituisce, con copia di sicurezza
  --connect-conf-helper MODO
                         keep      non tocca /usr/local/bin/fluxus-write-connect-conf.sh
                                   se è diverso da quello del sorgente (predefinito)
                         overwrite lo sostituisce, con copia di sicurezza
  --no-deps              non installa pacchetti
  --force                prosegue anche con una registrazione in corso
  --dry-run              mostra cosa farebbe, senza toccare niente
  --yes                  non fa domande, usa i valori predefiniti
  --quiet                stampa solo il riepilogo finale
  --help                 questo testo

Senza un terminale (in una pipe, da uno script, da cron) non fa domande:
prosegue con i valori predefiniti come se ci fosse --yes.
FINE
}

ISTANZA=''
APP_NAME=''
DATA_DIR=''
WEB_DIR=''
WEB_BASE=''; WEB_BASE_DATO=0
UTENTE=''
GRUPPO=''
PREFISSO=''
PHP_FPM_SOCK=''
NGINX_MODO='auto'
NGINX_VHOST=''
MEDIAMTX_MODO='instance'
RTMP_PORT=''
MEDIAMTX_API_PORT=''
MEDIAMTX_BIN=''
SORGENTE=''
VOLUME_HELPER='keep'
NETWORK_HELPER_MODO='keep'
CONNECT_CONF_HELPER='keep'
NIENTE_PACCHETTI=0
FORZA=0

valore() {
    [[ $# -ge 2 ]] || muori "l'opzione $1 vuole un valore"
    printf '%s' "$2"
}

while [[ $# -gt 0 ]]; do
    # Si accettano sia  --chiave valore  sia  --chiave=valore.
    if [[ "$1" == --*=* ]]; then
        set -- "${1%%=*}" "${1#*=}" "${@:2}"
    fi
    case "$1" in
        --instance)          ISTANZA=$(valore "$@"); shift 2 ;;
        --app-name)          APP_NAME=$(valore "$@"); shift 2 ;;
        --data-dir)          DATA_DIR=$(valore "$@"); shift 2 ;;
        --web-dir)           WEB_DIR=$(valore "$@"); shift 2 ;;
        --web-base)          WEB_BASE=${2-}; WEB_BASE_DATO=1; shift 2 ;;
        --user)              UTENTE=$(valore "$@"); shift 2 ;;
        --group)             GRUPPO=$(valore "$@"); shift 2 ;;
        --unit-prefix)       PREFISSO=$(valore "$@"); shift 2 ;;
        --php-fpm-sock)      PHP_FPM_SOCK=$(valore "$@"); shift 2 ;;
        --nginx)             NGINX_MODO=$(valore "$@"); shift 2 ;;
        --nginx-vhost)       NGINX_VHOST=$(valore "$@"); shift 2 ;;
        --mediamtx)          MEDIAMTX_MODO=$(valore "$@"); shift 2 ;;
        --rtmp-port)         RTMP_PORT=$(valore "$@"); shift 2 ;;
        --mediamtx-api-port) MEDIAMTX_API_PORT=$(valore "$@"); shift 2 ;;
        --mediamtx-binary)   MEDIAMTX_BIN=$(valore "$@"); shift 2 ;;
        --source)            SORGENTE=$(valore "$@"); shift 2 ;;
        --volume-helper)     VOLUME_HELPER=$(valore "$@"); shift 2 ;;
        --network-helper)    NETWORK_HELPER_MODO=$(valore "$@"); shift 2 ;;
        --connect-conf-helper) CONNECT_CONF_HELPER=$(valore "$@"); shift 2 ;;
        --no-deps)           NIENTE_PACCHETTI=1; shift ;;
        --force)             FORZA=1; shift ;;
        --dry-run)           PROVA=1; shift ;;
        --yes|-y)            AUTOMATICO=1; shift ;;
        --quiet|-q)          SILENZIOSO=1; AUTOMATICO=1; shift ;;
        --help|-h)           aiuto; exit 0 ;;
        *)                   muori "opzione sconosciuta: $1 (--help per l'elenco)" ;;
    esac
done

[[ "$NGINX_MODO"     =~ ^(auto|none)$      ]] || muori "--nginx vuole 'auto' o 'none'"
[[ "$MEDIAMTX_MODO"  =~ ^(instance|none)$  ]] || muori "--mediamtx vuole 'instance' o 'none'"
[[ "$VOLUME_HELPER"  =~ ^(keep|overwrite)$ ]] || muori "--volume-helper vuole 'keep' o 'overwrite'"
[[ "$NETWORK_HELPER_MODO" =~ ^(keep|overwrite)$ ]] || muori "--network-helper vuole 'keep' o 'overwrite'"
[[ "$CONNECT_CONF_HELPER" =~ ^(keep|overwrite)$ ]] || muori "--connect-conf-helper vuole 'keep' o 'overwrite'"

# ── Preliminari ───────────────────────────────────────────────────────────────

(( PROVA )) || [[ $(id -u) -eq 0 ]] || muori "va lanciato come root:  sudo $0 $*"
[[ -d /run/systemd/system ]] || muori "serve systemd: Fluxus fa il palinsesto con i timer di systemd"

if [[ -z "$SORGENTE" ]]; then
    SORGENTE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
fi
SORGENTE=${SORGENTE%/}
for atteso in app/index.php app/includes/conf.php scripts/fluxus-env.sh systemd nginx config VERSION; do
    [[ -e "$SORGENTE/$atteso" ]] || muori "in $SORGENTE manca $atteso: non sembra il sorgente di Fluxus (--source)"
done
VERSIONE=$(tr -d '[:space:]' < "$SORGENTE/VERSION")

CONF_DIR=/etc/fluxus

# ── Risoluzione dei valori ────────────────────────────────────────────────────
#
# Alla prima installazione valgono le opzioni e i valori predefiniti. Alla
# riesecuzione, ciò che non viene ripetuto sulla riga di comando si rilegge
# dalla configurazione esistente: rilanciare l'installer per aggiornare non deve
# poter riportare di nascosto un'istanza ai valori di fabbrica.

if [[ -z "$ISTANZA" ]]; then
    # Se c'è una sola istanza installata, è quella che si intende aggiornare.
    esistenti=()
    for f in "$CONF_DIR"/*.conf; do
        [[ -e "$f" ]] || continue
        [[ "$f" == *.remote.conf || "$f" == *.connect.conf ]] && continue
        esistenti+=("$(basename "$f" .conf)")
    done
    if [[ ${#esistenti[@]} -eq 1 ]]; then
        ISTANZA="${esistenti[0]}"
        dice "istanza già installata, la aggiorno: $ISTANZA"
    else
        ISTANZA=$(chiedi "Nome dell'istanza" "fluxus")
    fi
fi
[[ "$ISTANZA" =~ ^[a-z0-9][a-z0-9-]*$ ]] \
    || muori "nome dell'istanza non valido: '$ISTANZA' (lettere minuscole, cifre e trattini)"

CONF_FILE="$CONF_DIR/$ISTANZA.conf"
REMOTE_FILE="$CONF_DIR/$ISTANZA.remote.conf"
CONNECT_FILE="$CONF_DIR/$ISTANZA.connect.conf"
MANIFESTO="$CONF_DIR/$ISTANZA.install"

# Legge una chiave da un file CHIAVE=valore, con le stesse regole dei due
# lettori dell'istanza (commenti con '#', valore letterale fino a fine riga).
leggi_chiave() {
    local file="$1" chiave="$2"
    [[ -r "$file" ]] || return 1
    sed -n -e 's/\r$//' -e "s/^[[:space:]]*${chiave}[[:space:]]*=//p" "$file" | tail -n 1 \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# predefinito <variabile> <chiave nella conf> <valore di fabbrica>
predefinito() {
    local var="$1" chiave="$2" fabbrica="$3" precedente
    [[ -n "${!var}" ]] && return 0
    if precedente=$(leggi_chiave "$CONF_FILE" "$chiave") && [[ -n "$precedente" ]]; then
        printf -v "$var" '%s' "$precedente"
    else
        printf -v "$var" '%s' "$fabbrica"
    fi
}

predefinito APP_NAME FLUXUS_APP_NAME    'Fluxus'
predefinito DATA_DIR FLUXUS_DATA_DIR    "/var/lib/$ISTANZA"
predefinito WEB_DIR  FLUXUS_WEB_DIR     "/var/www/$ISTANZA"
predefinito UTENTE   FLUXUS_USER        'www-data'
predefinito GRUPPO   FLUXUS_GROUP       "$UTENTE"
predefinito PREFISSO FLUXUS_UNIT_PREFIX "$ISTANZA"

# Il sottopercorso può legittimamente essere vuoto (Fluxus alla radice del
# vhost): il valore vuoto va distinto dal "non l'ha detto nessuno".
if (( ! WEB_BASE_DATO )); then
    if [[ -r "$CONF_FILE" ]] && grep -qE '^[[:space:]]*FLUXUS_WEB_BASE[[:space:]]*=' "$CONF_FILE"; then
        WEB_BASE=$(leggi_chiave "$CONF_FILE" FLUXUS_WEB_BASE)
    else
        WEB_BASE="/$ISTANZA"
    fi
fi
WEB_BASE=${WEB_BASE%/}
[[ -z "$WEB_BASE" || "$WEB_BASE" == /* ]] || WEB_BASE="/$WEB_BASE"

DATA_DIR=${DATA_DIR%/}
WEB_DIR=${WEB_DIR%/}
[[ "$DATA_DIR" == /* && "$WEB_DIR" == /* ]] || muori "cartella dati e radice web vogliono percorsi assoluti"
[[ "$DATA_DIR" != "$WEB_DIR" ]] || muori "cartella dati e radice web non possono coincidere"

# Socket di PHP-FPM: si prende il più recente fra quelli presenti.
if [[ -z "$PHP_FPM_SOCK" ]]; then
    predefinito PHP_FPM_SOCK FLUXUS_PHP_FPM_SOCK ''
fi
if [[ -z "$PHP_FPM_SOCK" ]]; then
    # Si preferisce il socket con la versione nel nome a quello generico
    # /run/php/php-fpm.sock, che è un collegamento gestito da 'alternatives':
    # con due PHP installati cambierebbe sotto i piedi a Fluxus.
    for s in $(ls -1 /run/php/php[0-9]*-fpm.sock 2>/dev/null | sort -V); do PHP_FPM_SOCK="$s"; done
    [[ -n "$PHP_FPM_SOCK" && -S "$PHP_FPM_SOCK" ]] || PHP_FPM_SOCK=/run/php/php-fpm.sock
fi

# Porte: la prima libera a partire da quella di fabbrica. È ciò che permette a
# una seconda installazione di funzionare senza che nessuno debba scegliere un
# numero a mano.
porta_occupata() {
    ss -tlnH "sport = :$1" 2>/dev/null | grep -q . && return 0
    return 1
}
prima_libera() {
    local p="$1"
    while porta_occupata "$p"; do p=$((p + 1)); done
    printf '%s' "$p"
}

if [[ -z "$RTMP_PORT" ]]; then
    predefinito RTMP_PORT FLUXUS_RTMP_PORT ''
fi
if [[ -z "$MEDIAMTX_API_PORT" ]]; then
    precedente_api=$(leggi_chiave "$CONF_FILE" FLUXUS_MEDIAMTX_API || true)
    [[ "$precedente_api" =~ :([0-9]+)/?$ ]] && MEDIAMTX_API_PORT="${BASH_REMATCH[1]}"
fi
if [[ "$MEDIAMTX_MODO" == instance ]]; then
    [[ -n "$RTMP_PORT" ]]         || RTMP_PORT=$(prima_libera 1935)
    [[ -n "$MEDIAMTX_API_PORT" ]] || MEDIAMTX_API_PORT=$(prima_libera 9997)
else
    [[ -n "$RTMP_PORT" ]]         || RTMP_PORT=1935
    [[ -n "$MEDIAMTX_API_PORT" ]] || MEDIAMTX_API_PORT=9997
fi
[[ "$RTMP_PORT" =~ ^[0-9]+$ && "$MEDIAMTX_API_PORT" =~ ^[0-9]+$ ]] || muori "le porte vogliono un numero"

MEDIAMTX_CONF="$CONF_DIR/$ISTANZA.mediamtx.yml"
MEDIAMTX_UNIT="$PREFISSO-mediamtx.service"
MEDIAMTX_USER='mediamtx'

if [[ -z "$NGINX_VHOST" ]]; then
    NGINX_VHOST=$(leggi_chiave "$MANIFESTO" FLUXUS_NGINX_VHOST || true)
fi
if [[ -z "$NGINX_VHOST" ]]; then
    # Se il vhost abilitato è uno solo, è quello; altrimenti il predefinito.
    abilitati=()
    for f in /etc/nginx/sites-enabled/*; do [[ -e "$f" ]] && abilitati+=("$f"); done
    if [[ ${#abilitati[@]} -eq 1 ]]; then
        NGINX_VHOST=$(readlink -f "${abilitati[0]}")
    else
        NGINX_VHOST=/etc/nginx/sites-available/default
    fi
fi
# Lo snippet porta il nome dell'istanza, come il file di sudo e come la
# configurazione: un solo nome da cercare quando si va a vedere che cosa c'è.
NGINX_SNIPPET="/etc/nginx/snippets/$ISTANZA.conf"

SUDOERS_FILE="/etc/sudoers.d/$ISTANZA"
SUDO_ALIAS=$(printf '%s' "$ISTANZA" | tr 'a-z-' 'A-Z_')
LOGROTATE_FILE="/etc/logrotate.d/$ISTANZA"

# ── Guardie ───────────────────────────────────────────────────────────────────

passo "Verifiche preliminari"

# 1. La radice web e la cartella dati devono essere vuote, oppure già di questa
#    istanza. Un'installazione preesistente e diversa non si adotta di nascosto:
#    è così che un errore di battitura nel nome dell'istanza non può finire
#    dentro un'installazione in servizio.
cartella_vuota() { [[ -z "$(ls -A "$1" 2>/dev/null)" ]]; }

if [[ -e "$WEB_DIR" ]] && ! cartella_vuota "$WEB_DIR"; then
    dichiarata=''
    if [[ -f "$WEB_DIR/includes/instance.php" ]]; then
        dichiarata=$(sed -n "s/.*return[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" \
            "$WEB_DIR/includes/instance.php" | head -n 1)
    fi
    if [[ "$dichiarata" != "$ISTANZA" ]]; then
        if [[ -n "$dichiarata" ]]; then
            perche="dichiara di appartenere all'istanza '$dichiarata'"
        else
            perche="manca includes/instance.php, che ogni installazione ha"
        fi
        muori \
"$WEB_DIR esiste già, e non è un'installazione fatta da questo installer:
  $perche.
  Non ci scrivo dentro: se è un'installazione in servizio la romperei.
  Scegli un altro --instance o un altro --web-dir."
    fi
fi

if [[ -e "$DATA_DIR" ]] && ! cartella_vuota "$DATA_DIR"; then
    # Il collegamento si legge alla lettera, non si risolve: dopo una
    # disinstallazione che ha conservato i dati punta a un file che non c'è
    # più, ed è proprio quello il caso in cui deve valere come firma.
    puntata=$(readlink "$DATA_DIR/fluxus.conf" 2>/dev/null || true)
    [[ "$puntata" == "$CONF_FILE" ]] || muori \
"$DATA_DIR esiste già e non è la cartella dati dell'istanza '$ISTANZA'
  (manca il collegamento fluxus.conf → $CONF_FILE).
  Non ci scrivo dentro: potrebbero esserci le registrazioni di qualcun altro.
  Scegli un altro --instance o un altro --data-dir."
fi

# 2. Il prefisso dei servizi. Due istanze con lo stesso prefisso si
#    disabiliterebbero i timer a vicenda; e l'installazione da cui il progetto
#    proviene usa il prefisso storico 'fm', che nessuno deve poter calpestare.
if [[ ! -e "$MANIFESTO" ]]; then
    for u in /etc/systemd/system/"$PREFISSO"-*.timer /etc/systemd/system/"$PREFISSO"-*.service; do
        [[ -e "$u" ]] || continue
        muori \
"esistono già servizi con il prefisso '$PREFISSO' ($(basename "$u")), e non li ho
  installati io. Due istanze con lo stesso prefisso si spengono i timer a
  vicenda: scegli un --unit-prefix diverso."
    done
fi
for altro in "$CONF_DIR"/*.install; do
    [[ -e "$altro" ]] || continue
    [[ "$altro" == "$MANIFESTO" ]] && continue
    if [[ "$(leggi_chiave "$altro" FLUXUS_UNIT_PREFIX)" == "$PREFISSO" ]]; then
        muori "il prefisso '$PREFISSO' è già dell'istanza $(basename "$altro" .install)"
    fi
done

# 3. Utente e gruppo devono esistere: l'installer non inventa utenti di sistema.
getent passwd "$UTENTE" >/dev/null || muori "l'utente di sistema '$UTENTE' non esiste"
getent group  "$GRUPPO" >/dev/null || muori "il gruppo di sistema '$GRUPPO' non esiste"

# 4. Una registrazione in corso. Riscrivere gli script e riavviare i timer
#    mentre ffmpeg sta scrivendo un file è il modo migliore per perdere una
#    diretta: qui ci si ferma, e chi ha davvero fretta usa --force.
DB="$DATA_DIR/db/fluxus_media.db"
if [[ -f "$DB" ]] && command -v sqlite3 >/dev/null; then
    # ⚠️ Il database si apre SOLO come utente di Fluxus, nemmeno per leggere: è
    #    in WAL, e i file -wal/-shm nascono con il proprietario di chi arriva
    #    per primo. Aperto da root, PHP-FPM non riesce più a scrivere.
    in_corso=$(sudo -u "$UTENTE" sqlite3 -cmd ".timeout 5000" "$DB" \
        "SELECT count(*) FROM recordings WHERE status='recording';" 2>/dev/null || echo 0)
    if [[ "$in_corso" != 0 ]]; then
        (( FORZA )) || muori "l'istanza '$ISTANZA' ha $in_corso registrazione/i in corso. Con --force procedo comunque."
        segna_avviso "registrazione in corso, procedo perché è stato chiesto --force"
    fi
fi

fatto "nessun ostacolo"

# ── Riepilogo e conferma ──────────────────────────────────────────────────────

if ! (( SILENZIOSO )); then
    passo "Riepilogo"
    printf '  %-22s %s\n' \
        'istanza'        "$ISTANZA" \
        'nome mostrato'  "$APP_NAME" \
        'cartella dati'  "$DATA_DIR" \
        'radice web'     "$WEB_DIR" \
        'sottopercorso'  "${WEB_BASE:-/ (radice del vhost)}" \
        'utente:gruppo'  "$UTENTE:$GRUPPO" \
        'servizi'        "$PREFISSO-*" \
        'configurazione' "$CONF_FILE" \
        'nginx'          "$([[ $NGINX_MODO == auto ]] && echo "$NGINX_VHOST" || echo 'solo snippet')" \
        'PHP-FPM'        "$PHP_FPM_SOCK" \
        'MediaMTX'       "$([[ $MEDIAMTX_MODO == instance ]] && echo "$MEDIAMTX_UNIT — RTMP $RTMP_PORT, API $MEDIAMTX_API_PORT" || echo 'non gestito')" \
        'rotazione log'  "$LOGROTATE_FILE" \
        'sorgente'       "$SORGENTE ($VERSIONE)"
    (( PROVA )) && printf '\n  (prova a vuoto: non verrà toccato niente)\n'
    if ! conferma "Procedo?"; then muori "annullato"; fi
fi

# ── 1. Dipendenze ─────────────────────────────────────────────────────────────

passo "Dipendenze"

if (( NIENTE_PACCHETTI )); then
    dice "--no-deps: non installo pacchetti"
else
    # Si installa solo ciò che manca davvero: su una macchina che ospita già
    # un'installazione in servizio, un 'apt install' inutile è un rischio inutile.
    manca=()
    for pacchetto in nginx php-fpm php-cli php-sqlite3 php-curl sqlite3 ffmpeg rsync logrotate network-manager; do
        dpkg-query -W -f='${Status}' "$pacchetto" 2>/dev/null | grep -q '^install ok installed$' \
            || manca+=("$pacchetto")
    done
    # I metapacchetti php-* possono essere assenti pur essendoci le versioni
    # numerate (php8.2-fpm e simili): si guarda allora al risultato, non al nome.
    [[ -S "$PHP_FPM_SOCK" ]] && manca=("${manca[@]/php-fpm}")
    php -m 2>/dev/null | grep -qi '^pdo_sqlite$' && manca=("${manca[@]/php-sqlite3}")
    php -m 2>/dev/null | grep -qi '^curl$'       && manca=("${manca[@]/php-curl}")
    command -v php     >/dev/null && manca=("${manca[@]/php-cli}")
    manca=($(printf '%s\n' "${manca[@]}" | grep -v '^$' || true))

    if [[ ${#manca[@]} -eq 0 ]]; then
        fatto "c'è già tutto"
    else
        dice "installo: ${manca[*]}"
        esegui apt-get update -qq
        esegui env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${manca[@]}"
        fatto "pacchetti installati"
    fi
fi

for comando in ffmpeg sqlite3 php nginx rsync; do
    command -v "$comando" >/dev/null || segna_avviso "manca $comando: Fluxus non funzionerà finché non c'è"
done

# ── 2. Configurazione dell'istanza ────────────────────────────────────────────
#
# Il file è l'unico punto di verità di percorsi e nomi, e viene scritto per
# esteso anche dove il valore coinciderebbe con quello derivato dal nome
# dell'istanza: un file esplicito è più facile da leggere fra sei mesi.

passo "Configurazione"

scrivi_file "$CONF_FILE" 0644 root:root <<FINE
# Fluxus — configurazione dell'istanza '$ISTANZA'.
#
# Scritto da install.sh il $(date '+%Y-%m-%d %H:%M'). Lo leggono l'applicazione
# (includes/conf.php) e gli script (scripts/fluxus-env.sh): è l'unico punto in
# cui vivono percorsi e nomi di questa installazione.
#
# Si può modificare a mano — l'installer, se rilanciato, rilegge da qui i valori
# che non gli vengono ripetuti sulla riga di comando. Dopo averlo modificato:
#
#     sudo fluxus --instance $ISTANZA update
#
# perché regole di nginx, servizi e permessi vengono da questi valori e vanno
# rigenerati. Un esempio commentato di tutte le chiavi è in
# config/fluxus.conf.example, nel sorgente.

# ── Identità ──
FLUXUS_INSTANCE=$ISTANZA
FLUXUS_APP_NAME=$APP_NAME

# ── Percorsi ──
FLUXUS_DATA_DIR=$DATA_DIR
FLUXUS_WEB_DIR=$WEB_DIR
FLUXUS_WEB_BASE=$WEB_BASE

# ── Sistema ──
FLUXUS_USER=$UTENTE
FLUXUS_GROUP=$GRUPPO
FLUXUS_UNIT_PREFIX=$PREFISSO
FLUXUS_PHP_FPM_SOCK=$PHP_FPM_SOCK

# ── Server RTMP (MediaMTX) ──
FLUXUS_RTMP_HOST=127.0.0.1
FLUXUS_RTMP_PORT=$RTMP_PORT
FLUXUS_MEDIAMTX_API=http://127.0.0.1:$MEDIAMTX_API_PORT

# ── Encoder di ripiego ──
# Non riguarda la registrazione: l'encoder lo sceglie il profilo di qualità
# della sorgente. Il Raspberry Pi 5 non ha encoder H.264 hardware.
FLUXUS_HW_ENCODER=libx264
FLUXUS_HW_ENCODER_OPTS=-preset veryfast -crf 23
FINE
fatto "$CONF_FILE"

# I segreti del relay stanno a parte, con permessi più stretti, e non vengono
# mai riscritti: un aggiornamento non deve poter cancellare una chiave.
if [[ ! -e "$REMOTE_FILE" ]]; then
    scrivi_file "$REMOTE_FILE" 0640 "root:$GRUPPO" <<FINE
# Fluxus — segreti dell'istanza '$ISTANZA'. Sta a parte dalla configurazione
# principale, che è leggibile da tutti: la chiave del relay non deve esserlo.
#
# Vuoto = Fluxus Remote disattivato. Per attivarlo, i valori li dà il relay:
#
# FLUXUS_REMOTE_URL=
# FLUXUS_REMOTE_API_KEY=
# FLUXUS_NODE_NAME=
FINE
    fatto "$REMOTE_FILE (vuoto: Fluxus Remote disattivato)"
else
    dice "$REMOTE_FILE c'era già, non lo tocco"
fi

REMOTE_ATTIVO=0
if [[ -n "$(leggi_chiave "$REMOTE_FILE" FLUXUS_REMOTE_URL 2>/dev/null || true)" ]]; then
    REMOTE_ATTIVO=1
fi

# Stesso trattamento, stesso file a parte, per il token di Fluxus Connect.
if [[ ! -e "$CONNECT_FILE" ]]; then
    scrivi_file "$CONNECT_FILE" 0640 "root:$GRUPPO" <<FINE
# Fluxus — segreti dell'istanza '$ISTANZA' per Fluxus Connect. Sta a parte
# dalla configurazione principale, che è leggibile da tutti: il token non
# deve esserlo.
#
# Vuoto = Fluxus Connect disattivato. Per attivarlo, i valori li dà il
# pannello di Fluxus Connect:
#
# FLUXUS_CONNECT_URL=
# FLUXUS_CONNECT_TOKEN=
FINE
    fatto "$CONNECT_FILE (vuoto: Fluxus Connect disattivato)"
else
    dice "$CONNECT_FILE c'era già, non lo tocco"
fi

CONNECT_ATTIVO=0
if [[ -n "$(leggi_chiave "$CONNECT_FILE" FLUXUS_CONNECT_URL 2>/dev/null || true)" ]]; then
    CONNECT_ATTIVO=1
fi

# ── 3. Cartelle dati ──────────────────────────────────────────────────────────

passo "Cartelle"

for d in "$DATA_DIR" "$DATA_DIR"/{db,recordings,clips,tmp,logs,scripts,volumes}; do
    esegui install -d -m 0775 -o "$UTENTE" -g "$GRUPPO" "$d"
done
# Le sessioni sono l'unica cartella che nessun altro deve poter leggere:
# dentro ci sono i cookie di chi è entrato.
esegui install -d -m 0700 -o "$UTENTE" -g "$GRUPPO" "$DATA_DIR/sessions"

# Il collegamento è ciò che permette a uno script di trovare la propria
# configurazione partendo dalla sua posizione, senza variabili d'ambiente.
esegui ln -sfn "$CONF_FILE" "$DATA_DIR/fluxus.conf"
fatto "$DATA_DIR"

# ── 4. Applicazione ───────────────────────────────────────────────────────────

passo "Applicazione"

esegui install -d -m 0755 -o "$UTENTE" -g "$GRUPPO" "$WEB_DIR"
# --delete toglie i file di una versione precedente che non esistono più; le
# due eccezioni sono i file che scrive l'installer, non il sorgente.
esegui rsync -a --delete \
    --exclude='/includes/instance.php' --exclude='/VERSION' \
    --chown="$UTENTE:$GRUPPO" \
    "$SORGENTE/app/" "$WEB_DIR/"

# Dice a questa copia dell'applicazione a quale istanza appartiene: senza,
# dedurrebbe il nome dalla cartella che la contiene, che è giusto solo finché
# la radice web si chiama come l'istanza.
scrivi_file "$WEB_DIR/includes/instance.php" 0644 "$UTENTE:$GRUPPO" <<FINE
<?php
// Scritto da install.sh: l'istanza a cui appartiene questa copia
// dell'applicazione. Da qui viene $CONF_FILE.
return '$ISTANZA';
FINE

# VERSION è l'unica fonte del numero di versione, e l'applicazione lo legge
# dalla propria radice.
esegui install -m 0644 -o "$UTENTE" -g "$GRUPPO" "$SORGENTE/VERSION" "$WEB_DIR/VERSION"
fatto "$WEB_DIR ($VERSIONE)"

# Gli script vivono nella cartella dati, un livello sotto la configurazione:
# è così che si localizzano da soli.
esegui rsync -a --chown="$UTENTE:$GRUPPO" "$SORGENTE/scripts/" "$DATA_DIR/scripts/"
esegui find "$DATA_DIR/scripts" -type f -name '*.sh' -exec chmod 0755 {} +
fatto "$DATA_DIR/scripts"

# ── 5. Comandi di sistema ─────────────────────────────────────────────────────
#
# Questi non appartengono a nessuna istanza: sono della macchina.

passo "Comandi di sistema"

# Il comando di gestione. Gira come root, quindi la sua copia del lettore di
# configurazione deve essere root:root: se leggesse quello in
# <cartella dati>/scripts, che appartiene all'utente di Fluxus, quell'utente
# potrebbe riscriverlo e ottenere root al primo 'fluxus status' dell'amministratore.
esegui install -D -m 0644 -o root -g root "$SORGENTE/scripts/fluxus-env.sh" /usr/local/lib/fluxus/fluxus-env.sh
esegui install -D -m 0755 -o root -g root "$SORGENTE/bin/fluxus" /usr/local/bin/fluxus
fatto "/usr/local/bin/fluxus"

# Script privilegiati unici per la macchina (fluxus-enable-volume.sh per i
# dischi, fluxus-network.sh per la pagina Rete): li condividono tutte le
# istanze, sostituirli cambia il comportamento anche di quelle che non
# stiamo installando. Se quello presente è diverso, per default non si tocca.
#
# installa_helper_macchina <sorgente> <destinazione> <modo> <nome-opzione>
# Stampa nella variabile globale HELPER_STATO l'esito, per il riepilogo finale.
installa_helper_macchina() {
    local src="$1" dest="$2" modo="$3" opzione="$4"
    if [[ -e "$dest" ]] && ! cmp -s "$src" "$dest"; then
        if [[ "$modo" == overwrite ]]; then
            esegui cp -a "$dest" "$dest.bak-$(date +%Y%m%d-%H%M%S)"
            esegui install -m 0755 -o root -g root "$src" "$dest"
            HELPER_STATO='sostituito (copia di sicurezza accanto)'
            fatto "$dest sostituito"
        else
            HELPER_STATO='lasciato com'\''era (diverso da quello del sorgente)'
            segna_avviso "$dest esiste ed è diverso da quello del sorgente: non lo tocco,
     perché lo usano anche le altre istanze. La funzione corrispondente
     dall'interfaccia non funzionerà per '$ISTANZA' finché non lo si aggiorna
     ($opzione overwrite, quando si è certi che nessun'altra istanza
     dipenda dalla versione vecchia)."
        fi
    else
        esegui install -D -m 0755 -o root -g root "$src" "$dest"
        HELPER_STATO='installato'
        fatto "$dest"
    fi
}

HELPER=/usr/local/bin/fluxus-enable-volume.sh
installa_helper_macchina "$SORGENTE/bin/fluxus-enable-volume.sh" "$HELPER" "$VOLUME_HELPER" --volume-helper
VOLUME_HELPER_STATO="$HELPER_STATO"

NETWORK_HELPER=/usr/local/bin/fluxus-network.sh
installa_helper_macchina "$SORGENTE/bin/fluxus-network.sh" "$NETWORK_HELPER" "$NETWORK_HELPER_MODO" --network-helper
NETWORK_HELPER_STATO="$HELPER_STATO"

CONNECT_CONF_HELPER_BIN=/usr/local/bin/fluxus-write-connect-conf.sh
installa_helper_macchina "$SORGENTE/bin/fluxus-write-connect-conf.sh" "$CONNECT_CONF_HELPER_BIN" "$CONNECT_CONF_HELPER" --connect-conf-helper
CONNECT_CONF_HELPER_STATO="$HELPER_STATO"

# ── 6. Modelli: servizi, permessi, server web ─────────────────────────────────

SEGNAPOSTO=(
    [FLUXUS_INSTANCE]="$ISTANZA"
    [FLUXUS_APP_NAME]="$APP_NAME"
    [FLUXUS_USER]="$UTENTE"
    [FLUXUS_GROUP]="$GRUPPO"
    [FLUXUS_DATA_DIR]="$DATA_DIR"
    [FLUXUS_WEB_DIR]="$WEB_DIR"
    [FLUXUS_WEB_BASE]="$WEB_BASE"
    [FLUXUS_CONF_FILE]="$CONF_FILE"
    [FLUXUS_UNIT_PREFIX]="$PREFISSO"
    [FLUXUS_PHP_FPM_SOCK]="$PHP_FPM_SOCK"
    [FLUXUS_SUDO_ALIAS]="$SUDO_ALIAS"
    [FLUXUS_RTMP_PORT]="$RTMP_PORT"
    [FLUXUS_MEDIAMTX_API_PORT]="$MEDIAMTX_API_PORT"
    [FLUXUS_MEDIAMTX_BIN]="${MEDIAMTX_BIN:-/usr/local/bin/mediamtx}"
    [FLUXUS_MEDIAMTX_CONF]="$MEDIAMTX_CONF"
    [FLUXUS_MEDIAMTX_USER]="$MEDIAMTX_USER"
)

passo "Servizi"

UNIT_INSTALLATI=()
for modello in "$SORGENTE"/systemd/*.service.in "$SORGENTE"/systemd/*.timer.in; do
    [[ -e "$modello" ]] || continue
    base=$(basename "$modello" .in)
    [[ "$base" == mediamtx.service ]] && continue     # ha un nome tutto suo, più sotto
    unit="$PREFISSO-$base"
    # remote-sync (5s), connect-sync (2s) e connect-catalog-sync (30s) girano
    # di continuo: senza relay/Connect configurati sarebbero timer che si
    # svegliano per uscire subito. Si installano, ma non si attivano.
    rendi "$modello" | scrivi_file "/etc/systemd/system/$unit" 0644 root:root
    UNIT_INSTALLATI+=("$unit")
done
fatto "${#UNIT_INSTALLATI[@]} unit in /etc/systemd/system"

passo "Permessi (sudo)"

# Il file di sudo si verifica prima di metterlo al suo posto: un sudoers non
# valido rende illeggibile tutto sudo, non solo la propria regola.
tmp_sudo=$(mktemp)
rendi "$SORGENTE/config/sudoers.fluxus.in" > "$tmp_sudo"
if visudo -cqf "$tmp_sudo"; then
    scrivi_file "$SUDOERS_FILE" 0440 root:root < "$tmp_sudo"
    fatto "$SUDOERS_FILE"
else
    rm -f "$tmp_sudo"
    muori "il file di sudo generato non è valido: non lo installo"
fi
rm -f "$tmp_sudo"

passo "Rotazione dei log"

# logrotate.timer è già attivo di sistema: basta lo stanza in /etc/logrotate.d.
# -d è la prova a vuoto di logrotate stesso: verifica la sintassi senza ruotare
# niente, lo stesso spirito di 'nginx -t' e 'visudo -cqf' qui sopra.
if command -v logrotate >/dev/null; then
    tmp_logrotate=$(mktemp)
    rendi "$SORGENTE/config/logrotate.fluxus.in" > "$tmp_logrotate"
    if logrotate -d "$tmp_logrotate" >/dev/null 2>&1; then
        scrivi_file "$LOGROTATE_FILE" 0644 root:root < "$tmp_logrotate"
        fatto "$LOGROTATE_FILE"
    else
        segna_avviso "il file di rotazione dei log generato non è valido: non lo installo
     ($(logrotate -d "$tmp_logrotate" 2>&1 | tail -1))"
    fi
    rm -f "$tmp_logrotate"
else
    segna_avviso "logrotate non è installato: i log di servizio cresceranno senza limite"
fi

# ── 7. Server web ─────────────────────────────────────────────────────────────

passo "Server web"

rendi "$SORGENTE/nginx/locations-fluxus.conf.in" | scrivi_file "$NGINX_SNIPPET" 0644 root:root
fatto "$NGINX_SNIPPET"

INCLUSIONE="include $(basename "$(dirname "$NGINX_SNIPPET")")/$(basename "$NGINX_SNIPPET");"
MARCA_INIZIO="# >>> fluxus:$ISTANZA — inizio (install.sh)"
MARCA_FINE="# <<< fluxus:$ISTANZA — fine"

if [[ "$NGINX_MODO" == none ]]; then
    dice "--nginx none: aggiungi a mano dentro il blocco server del tuo vhost,"
    dice "prima di ogni 'location ~ \\.php\$' generica:"
    dice "    $INCLUSIONE"
elif [[ ! -f "$NGINX_VHOST" ]]; then
    segna_avviso "$NGINX_VHOST non esiste: aggiungi a mano '$INCLUSIONE' nel tuo vhost"
elif grep -qF "$MARCA_INIZIO" "$NGINX_VHOST"; then
    fatto "l'inclusione in $NGINX_VHOST c'era già"
else
    # ⚠️ L'inclusione va messa SUBITO DOPO 'server {', non in fondo: nginx
    #    valuta le location con espressione regolare nell'ordine in cui
    #    compaiono, e il blocco PHP di Fluxus deve precedere il 'location ~
    #    \.php$' generico del vhost. Altrimenti il fastcgi_read_timeout lungo
    #    non viene applicato e l'estrazione di una clip lunga muore a metà.
    copia="$NGINX_VHOST.bak-fluxus-$(date +%Y%m%d-%H%M%S)"
    esegui cp -a "$NGINX_VHOST" "$copia"
    if (( PROVA )); then
        printf '    · inserirei in %s, subito dopo «server {»:\n        %s\n' "$NGINX_VHOST" "$INCLUSIONE"
    else
        awk -v inizio="$MARCA_INIZIO" -v riga="    $INCLUSIONE" -v fine="$MARCA_FINE" '
            !messo && /^[[:space:]]*server[[:space:]]*\{/ {
                print; print "    " inizio; print riga; print "    " fine; messo = 1; next
            }
            { print }
            END { if (!messo) exit 3 }
        ' "$copia" > "$NGINX_VHOST" || muori "in $NGINX_VHOST non ho trovato un blocco 'server {' (--nginx none per farne a meno)"
    fi
    if ! esegui nginx -t 2>/dev/null; then
        (( PROVA )) || cp -a "$copia" "$NGINX_VHOST"
        muori "nginx -t non passa con l'inclusione: ho rimesso $NGINX_VHOST com'era.
  Il dettaglio con:  nginx -t"
    fi
    fatto "inclusione aggiunta a $NGINX_VHOST (copia in $(basename "$copia"))"
fi

if [[ "$NGINX_MODO" != none ]]; then
    esegui systemctl reload nginx || segna_avviso "nginx non si è ricaricato: controlla 'systemctl status nginx'"
fi

# L'utente di nginx e quello di PHP-FPM devono poter leggere le cartelle dati:
# se l'istanza gira con un utente suo, le pool e i permessi vanno rivisti a mano.
if [[ "$UTENTE" != www-data ]]; then
    segna_avviso "l'istanza gira come '$UTENTE': verifica che la pool di PHP-FPM giri con lo stesso utente,
     o il database finirà con i permessi sbagliati (è in WAL)."
fi

# ── 8. Server RTMP ────────────────────────────────────────────────────────────

passo "Server RTMP (MediaMTX)"

if [[ "$MEDIAMTX_MODO" == none ]]; then
    dice "--mediamtx none: le sorgenti 'rtmp-push' funzioneranno solo se un MediaMTX"
    dice "risponde su 127.0.0.1:$RTMP_PORT con l'API su $MEDIAMTX_API_PORT"
else
    if [[ -z "$MEDIAMTX_BIN" ]]; then
        MEDIAMTX_BIN=$(command -v mediamtx || true)
    fi
    if [[ -z "$MEDIAMTX_BIN" || ! -x "$MEDIAMTX_BIN" ]]; then
        segna_avviso "mediamtx non è installato: le sorgenti 'rtmp-push' non funzioneranno.
     L'eseguibile si prende da https://github.com/bluenviron/mediamtx/releases,
     si mette in /usr/local/bin/mediamtx e poi si rilancia questo installer
     (oppure lo si indica con --mediamtx-binary)."
    else
        SEGNAPOSTO[FLUXUS_MEDIAMTX_BIN]="$MEDIAMTX_BIN"
        # Un utente di sistema tutto suo: MediaMTX riceve connessioni dalla rete
        # e non ha nessun motivo di girare come l'utente di Fluxus, che possiede
        # le registrazioni.
        if ! getent passwd "$MEDIAMTX_USER" >/dev/null; then
            esegui useradd --system --no-create-home --shell /usr/sbin/nologin "$MEDIAMTX_USER"
            fatto "creato l'utente di sistema $MEDIAMTX_USER"
        fi
        rendi "$SORGENTE/config/mediamtx.yml.in" | scrivi_file "$MEDIAMTX_CONF" 0644 root:root
        rendi "$SORGENTE/systemd/mediamtx.service.in" | scrivi_file "/etc/systemd/system/$MEDIAMTX_UNIT" 0644 root:root
        fatto "$MEDIAMTX_UNIT — RTMP $RTMP_PORT, API 127.0.0.1:$MEDIAMTX_API_PORT"
    fi
fi

# ── Hotspot di primo avvio ──────────────────────────────────────────────────
#
# Unit systemd machine-wide, non per istanza: nessun segnaposto @FLUXUS_...@,
# non passa da rendi(), non entra in UNIT_INSTALLATI né nel manifesto qui
# sotto — 'fluxus uninstall' non deve mai spegnerla, stesso motivo per cui
# non tocca fluxus-enable-volume.sh. Si abilita ma non si avvia (enable,
# senza --now): scatta solo al prossimo riavvio vero, mai durante questo
# install.sh, nemmeno su una macchina già in servizio. Vedi
# docs/NOTE-TECNICHE.md, sezione Rete.

passo "Hotspot di primo avvio"

esegui install -D -m 0644 -o root -g root "$SORGENTE/systemd/fluxus-hotspot-check.service" \
    /etc/systemd/system/fluxus-hotspot-check.service
esegui systemctl daemon-reload
esegui systemctl enable fluxus-hotspot-check.service
fatto "fluxus-hotspot-check.service (abilitato, scatta al prossimo riavvio)"

# ── 9. Database ───────────────────────────────────────────────────────────────
#
# Non c'è uno schema da applicare a mano: lo fa l'applicazione, che sa creare il
# database se manca e migrarlo se è vecchio (includes/db_init.php). Qui la si
# carica una volta da riga di comando, come utente di Fluxus, che è ciò che
# garantisce la proprietà giusta dei file -wal e -shm.

passo "Database"

if (( PROVA )); then
    printf '    · caricherei l'\''applicazione come %s per creare/migrare %s\n' "$UTENTE" "$DB"
else
    # Il percorso arriva dall'ambiente e non interpolato nella riga di comando
    # di php: un percorso con un apice romperebbe il programma da una riga.
    if ! sudo -u "$UTENTE" env FLUXUS_CONF="$CONF_FILE" FLUXUS_WEB="$WEB_DIR" \
        php -r 'require getenv("FLUXUS_WEB") . "/includes/config.php";' > /dev/null; then
        muori "l'inizializzazione del database non è riuscita.
  Riprova a mano per vedere l'errore:
    sudo -u $UTENTE env FLUXUS_CONF=$CONF_FILE php -r 'require \"$WEB_DIR/includes/config.php\";'"
    fi
    schema=$(sudo -u "$UTENTE" sqlite3 -cmd ".timeout 5000" "$DB" \
        "SELECT value FROM settings WHERE key='schema_version';" 2>/dev/null || true)
    fatto "$DB (schema $schema)"
fi

# ── 10. Avvio dei servizi ─────────────────────────────────────────────────────

passo "Avvio"

esegui systemctl daemon-reload

DA_ATTIVARE=()
for unit in "${UNIT_INSTALLATI[@]}"; do
    [[ "$unit" == *.timer ]] || continue
    if [[ "$unit" == *remote-sync* ]] && (( ! REMOTE_ATTIVO )); then
        dice "$unit installato ma non attivato (Fluxus Remote non è configurato)"
        continue
    fi
    if [[ "$unit" == *connect-sync* || "$unit" == *connect-catalog-sync* ]] && (( ! CONNECT_ATTIVO )); then
        dice "$unit installato ma non attivato (Fluxus Connect non è configurato)"
        continue
    fi
    DA_ATTIVARE+=("$unit")
done
[[ ${#DA_ATTIVARE[@]} -gt 0 ]] && esegui systemctl enable --now "${DA_ATTIVARE[@]}"

if [[ "$MEDIAMTX_MODO" == instance && -x "${MEDIAMTX_BIN:-}" ]]; then
    esegui systemctl enable --now "$MEDIAMTX_UNIT"
fi
fatto "timer attivi: ${DA_ATTIVARE[*]:-nessuno}"

# ── 11. Manifesto ─────────────────────────────────────────────────────────────
#
# Ciò che serve a 'fluxus update' e a 'fluxus uninstall' per sapere che cosa
# è stato fatto e che cosa disfare. Non è configurazione: è memoria.

scrivi_file "$MANIFESTO" 0644 root:root <<FINE
# Fluxus — che cosa ha installato install.sh per l'istanza '$ISTANZA'.
# Lo legge il comando 'fluxus' per aggiornare e per disinstallare. Non è
# configurazione: le scelte stanno in $CONF_FILE.
FLUXUS_INSTALLED_VERSION=$VERSIONE
FLUXUS_INSTALLED_AT=$(date '+%Y-%m-%dT%H:%M:%S%z')
FLUXUS_SOURCE=$SORGENTE
FLUXUS_UNIT_PREFIX=$PREFISSO
FLUXUS_UNITS=${UNIT_INSTALLATI[*]}
FLUXUS_NGINX_MODE=$NGINX_MODO
FLUXUS_NGINX_VHOST=$NGINX_VHOST
FLUXUS_NGINX_SNIPPET=$NGINX_SNIPPET
FLUXUS_SUDOERS=$SUDOERS_FILE
FLUXUS_LOGROTATE=$LOGROTATE_FILE
FLUXUS_MEDIAMTX_MODE=$MEDIAMTX_MODO
FLUXUS_MEDIAMTX_UNIT=$MEDIAMTX_UNIT
FLUXUS_MEDIAMTX_CONF=$MEDIAMTX_CONF
FLUXUS_VOLUME_HELPER=$VOLUME_HELPER_STATO
FLUXUS_NETWORK_HELPER=$NETWORK_HELPER_STATO
FLUXUS_CONNECT_CONF_HELPER=$CONNECT_CONF_HELPER_STATO
FINE

# ── Fine ──────────────────────────────────────────────────────────────────────

INDIRIZZO_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[[ -n "$INDIRIZZO_IP" ]] || INDIRIZZO_IP=$(hostname)

printf '\n%s%s %s installato.%s\n\n' "$C_TIT" "$APP_NAME" "$VERSIONE" "$C_FIN"
printf '  Interfaccia   %shttp://%s%s/%s\n' "$C_OK" "$INDIRIZZO_IP" "$WEB_BASE" "$C_FIN"
[[ "$MEDIAMTX_MODO" == instance ]] && \
printf '  Ingresso RTMP rtmp://%s:%s/<id-sorgente>\n' "$INDIRIZZO_IP" "$RTMP_PORT"
printf '  Gestione      sudo fluxus --instance %s status\n' "$ISTANZA"
printf '  Configurazione\n                %s\n' "$CONF_FILE"

if [[ ${#AVVISI[@]} -gt 0 ]]; then
    printf '\n  %sDa sapere:%s\n' "$C_AVV" "$C_FIN"
    for a in "${AVVISI[@]}"; do printf '  · %s\n' "$a"; done
fi

if (( PROVA )); then
    printf '\n  (prova a vuoto: non è stato toccato niente)\n'
fi
printf '\n'
