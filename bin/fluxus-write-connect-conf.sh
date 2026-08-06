#!/bin/bash
# Fluxus — fluxus-write-connect-conf.sh <istanza> <gruppo> <prefisso-unit> <url> <token>
#
# Scrive /etc/fluxus/<istanza>.connect.conf (root:<gruppo> 0640) e attiva o
# disattiva a caldo il timer di sincronizzazione, per la card "Fluxus Connect"
# in Impostazioni.
#
# Invocato da app/includes/helpers.php (fmWriteConnectConf) via sudo (regola
# in /etc/sudoers.d/<istanza>).
#
# ⚠️ Come fluxus-enable-volume.sh e fluxus-network.sh: questo script è UNICO
# per tutta la macchina, anche se ogni chiamata riguarda una sola istanza —
# istanza, gruppo e prefisso-unit arrivano come argomenti, non da una propria
# configurazione, perché gira come root su richiesta di un processo che root
# non è. È la regola sudo, che fissa istanza/gruppo/prefisso ai valori di
# quell'installazione, l'unica autorità su quali valori siano leciti: qui si
# controlla solo che siano sensati.
#
# ⚠️ Deve restare root:root 0755 e FUORI dalla cartella scripts dell'istanza,
# che appartiene all'utente di Fluxus: se quello potesse riscriverlo, la
# regola sudo NOPASSWD gli darebbe root completo.
#
# Semantica di URL/token identica a config/fluxus-connect.conf.example:
# entrambi vuoti disattiva la funzione, entrambi valorizzati la attiva. Uno
# solo dei due è un errore, non un caso intermedio.
#
# Stampa una riga "OK" oppure "ERR <messaggio>".

set -u
export LC_ALL=C
PATH=/usr/sbin:/usr/bin:/sbin:/bin

fail() { echo "ERR $*"; exit 1; }

ISTANZA="${1:-}"
GRUPPO="${2:-}"
PREFISSO="${3:-}"
URL="${4:-}"
TOKEN="${5:-}"

[[ "$ISTANZA" =~ ^[a-z0-9][a-z0-9-]*$ ]] || fail "Istanza non valida"
[[ "$PREFISSO" =~ ^[a-z0-9][a-z0-9-]*$ ]] || fail "Prefisso unit non valido"
getent group "$GRUPPO" >/dev/null 2>&1 || fail "Gruppo $GRUPPO inesistente"

if [[ -n "$URL" ]]; then
    [[ ${#URL} -le 300 ]] || fail "URL troppo lungo"
    [[ "$URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[[:print:]]*)?$ ]] || fail "URL non valido"
fi
if [[ -n "$TOKEN" ]]; then
    [[ "$TOKEN" =~ ^[A-Za-z0-9._-]{8,256}$ ]] || fail "Token non valido"
fi
if [[ -z "$URL" && -n "$TOKEN" ]] || [[ -n "$URL" && -z "$TOKEN" ]]; then
    fail "URL e token vanno impostati o svuotati insieme"
fi

CONF_FILE="/etc/fluxus/$ISTANZA.connect.conf"
[[ -d /etc/fluxus ]] || fail "/etc/fluxus non esiste: istanza non installata"

TMP=$(mktemp) || fail "impossibile creare un file temporaneo"
cat > "$TMP" <<FINE
# Fluxus Connect — broker per seguire e controllare Fluxus da console esterne.
#
# Sta in un file separato da quello principale perché contiene un token: va
# installato in  /etc/fluxus/<istanza>.connect.conf  come root:<gruppo di
# Fluxus> 0640, mentre la configurazione principale è leggibile da tutti.
#
# Stesso formato dell'altro: CHIAVE=valore, commenti con '#'.
#
# Lasciare i valori vuoti per tenere la funzione disattivata: senza indirizzo e
# senza token la sincronizzazione esce subito e non fa alcuna chiamata di rete.
#
# Scritto dalla card "Fluxus Connect" in Impostazioni.

# Indirizzo di base di Fluxus Connect, senza barra finale.
FLUXUS_CONNECT_URL=$URL

# Token di primo livello di questo Pi, generato dal pannello di Fluxus
# Connect (non è una sotto-chiave: quelle sono per le console esterne).
FLUXUS_CONNECT_TOKEN=$TOKEN
FINE

install -m 0640 -o root -g "$GRUPPO" "$TMP" "$CONF_FILE" || { rm -f "$TMP"; fail "scrittura di $CONF_FILE fallita"; }
rm -f "$TMP"

# Attiva/disattiva a caldo il timer: senza questo, il cambiamento resterebbe
# fermo fino al prossimo install.sh. Best-effort: un timer non installato (o
# systemd che non risponde) non deve far fallire la scrittura già riuscita.
UNIT="$PREFISSO-connect-sync.timer"
if [[ -n "$URL" ]]; then
    systemctl enable --now "$UNIT" >/dev/null 2>&1 || true
else
    systemctl disable --now "$UNIT" >/dev/null 2>&1 || true
fi

echo "OK"
