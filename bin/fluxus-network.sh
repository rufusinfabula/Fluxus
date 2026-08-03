#!/bin/bash
# Fluxus — fluxus-network.sh <comando> [argomenti]
#
# Unico punto di ingresso per tutto ciò che tocca la rete della macchina:
# stato, scansione WiFi, cambio rete, IP statico/automatico, nome host.
# Invocato da app/api/network_*.php via sudo (regola in /etc/sudoers.d/<istanza>).
#
# ⚠️ Come fluxus-enable-volume.sh: questo script è UNICO per tutta la
# macchina, non per istanza — la rete non appartiene a nessuna installazione
# di Fluxus in particolare. Non legge configurazione propria: tutto arriva
# come argomenti, validati qui.
#
# ⚠️ Deve restare root:root 0755 e FUORI dalla cartella scripts dell'istanza,
# che appartiene all'utente di Fluxus: se quello potesse riscriverlo, la
# regola sudo NOPASSWD gli darebbe root completo (stesso motivo di
# fluxus-enable-volume.sh).
#
# ⚠️ A differenza del disco esterno, qui un comando sbagliato può tagliare
# fuori chi lo sta lanciando (cambio rete/IP dalla stessa rete che si sta
# cambiando). Per questo apply-wifi e apply-ip non si limitano ad applicare:
# salvano uno stato precedente e armano un rollback a tempo (systemd-run
# transiente) che, se nessuno conferma entro ROLLBACK_SECONDS, ripristina da
# solo la configurazione precedente. Il cambio di hostname non lo fa: non
# tocca la connettività.
#
# ── Hotspot di primo avvio (fase 4) ───────────────────────────────────────
#
# hotspot-check gira una volta sola al boot (invocato da
# fluxus-hotspot-check.service, root, MAI da PHP): se non trova nessuna rete
# nota entro una finestra di grazia, apre un hotspot proprio (hotspot-start)
# a cui collegarsi col telefono per configurare la rete vera. Non è un
# demone di sorveglianza: una caduta di rete durante il funzionamento
# normale non lo riattiva da sola.
#
# L'hotspot ha una vita autolimitata (hotspot-timeout, sul proprio timer
# transiente, separato da quello di apply-wifi/apply-ip): se nessuno lo
# configura si chiude e riprova da capo, all'infinito — ogni ciclo è
# innocuo, e così la macchina non resta mai muta a lungo. Quando invece
# l'utente sceglie la rete vera dalla pagina collegata all'hotspot, passa
# comunque da apply-wifi: il timer dell'hotspot va disarmato all'ingresso
# (altrimenti interferisce col rollback a 45s di apply-wifi) e riarmato solo
# se è proprio il rollback a riportare su l'hotspot.
#
# Convenzione di stampa: la prima riga è "OK" o "ERR <messaggio>". Dopo un OK
# possono seguire righe di dati, un formato diverso per ogni comando (vedi
# ciascuna funzione qui sotto).

set -u
export LC_ALL=C
PATH=/usr/sbin:/usr/bin:/sbin:/bin

ROLLBACK_DIR=/run/fluxus-network
SNAPSHOT="$ROLLBACK_DIR/pending.snapshot"
ROLLBACK_UNIT=fluxus-network-rollback
ROLLBACK_SECONDS=45

# Hotspot di primo avvio: nome del profilo nmcli, indirizzo fissato (non il
# default implicito di NetworkManager: la documentazione deve poter dire
# "vai su http://10.42.0.1" con certezza), password fissa e documentata
# (nessun canale per comunicarne una diversa per unità a chi non ha ancora
# rete — vedi NOTE-TECNICHE.md, sezione Rete). Stato separato dallo
# SNAPSHOT di apply-wifi/apply-ip: sono due cose concettualmente diverse.
HOTSPOT_CONN_NAME=fluxus-hotspot
HOTSPOT_ADDR=10.42.0.1/24
HOTSPOT_PASSWORD='FluxusSetup!'
HOTSPOT_STATE="$ROLLBACK_DIR/hotspot.state"
HOTSPOT_TIMEOUT_UNIT=fluxus-hotspot-timeout
HOTSPOT_TIMEOUT_SECONDS=900
HOTSPOT_GRACE_UNCONFIGURED=15
HOTSPOT_GRACE_KNOWN=150
HOTSPOT_POLL_INTERVAL=10

fail() { echo "ERR $*"; exit 1; }
# Come fail(), ma dopo che uno snapshot è già stato scritto: lo scarta, altrimenti
# resterebbe un rollback "in sospeso" per una modifica mai applicata davvero.
abort() { rm -f "$SNAPSHOT"; fail "$*"; }

# Interfaccia e profilo di connessione usati di default: quella della rotta
# predefinita, non "la prima interfaccia WiFi" — su una macchina con più
# schede è l'unica scelta che corrisponde a "come ci si è collegati ora".
default_device() {
    ip route show default 2>/dev/null | awk '/^default/ {print $5; exit}'
}

active_connection_for() {
    local dev="$1"
    nmcli -t -f NAME,DEVICE connection show --active 2>/dev/null \
        | awk -F: -v d="$dev" '$2==d{print $1; exit}'
}

# ── status ───────────────────────────────────────────────────────────────
# Stampa OK poi coppie chiave=valore, una per riga. Sola lettura.
cmd_status() {
    local dev conn state ip4 gw dns method ssid

    dev=$(default_device)
    echo "OK"
    echo "DEVICE=${dev:-}"
    echo "HOSTNAME=$(hostname 2>/dev/null)"

    if [[ -z "$dev" ]]; then
        echo "STATE=disconnected"
    else
        conn=$(active_connection_for "$dev")
        state=$(nmcli -t -f GENERAL.STATE device show "$dev" 2>/dev/null | cut -d: -f2-)
        ip4=$(nmcli -t -f IP4.ADDRESS device show "$dev" 2>/dev/null | head -n1 | cut -d: -f2-)
        gw=$(nmcli -t -f IP4.GATEWAY device show "$dev" 2>/dev/null | cut -d: -f2-)
        dns=$(nmcli -t -f IP4.DNS device show "$dev" 2>/dev/null | cut -d: -f2- | paste -sd, -)
        ssid=$(nmcli -t -f GENERAL.CONNECTION device show "$dev" 2>/dev/null | cut -d: -f2-)
        method=$(nmcli -t -g ipv4.method connection show "$conn" 2>/dev/null)

        echo "CONNECTION=${conn:-}"
        echo "STATE=${state:-unknown}"
        echo "SSID=${ssid:-}"
        echo "ADDRESS=${ip4:-}"
        echo "GATEWAY=${gw:-}"
        echo "DNS=${dns:-}"
        echo "METHOD=${method:-auto}"
    fi

    if [[ -f "$SNAPSHOT" ]]; then
        local rollback_at left
        rollback_at=$(awk -F= '$1=="ROLLBACK_AT"{print $2}' "$SNAPSHOT")
        left=$(( ${rollback_at:-0} - $(date +%s) ))
        (( left < 0 )) && left=0
        echo "PENDING=$left"
    fi

    if [[ -f "$HOTSPOT_STATE" ]]; then
        local h_ssid h_started h_left
        h_ssid=$(awk -F= '$1=="SSID"{print $2}' "$HOTSPOT_STATE")
        h_started=$(awk -F= '$1=="STARTED_AT"{print $2}' "$HOTSPOT_STATE")
        h_left=$(( ${h_started:-0} + HOTSPOT_TIMEOUT_SECONDS - $(date +%s) ))
        (( h_left < 0 )) && h_left=0
        echo "HOTSPOT=active"
        echo "HOTSPOT_SSID=${h_ssid:-}"
        echo "HOTSPOT_TIMEOUT=$h_left"
    fi
}

# ── scan ─────────────────────────────────────────────────────────────────
# Stampa OK poi una riga per rete: SSID:SEGNALE:SICUREZZA (già "terse" nmcli,
# col suo escaping dei ':' interni — il parsing lo fa il chiamante PHP).
cmd_scan() {
    nmcli device wifi rescan >/dev/null 2>&1
    sleep 2   # il rescan è asincrono: senza attesa la lista è quella vecchia
    echo "OK"
    nmcli -t -f SSID,SIGNAL,SECURITY device wifi list 2>/dev/null \
        | awk -F: '!seen[$1]++'   # una riga per SSID, tiene il segnale migliore (primo)
}

# ── apply-wifi <ssid> <psk> ──────────────────────────────────────────────
cmd_apply_wifi() {
    local ssid="${1:-}" psk="${2:-}"
    [[ -n "$ssid" && ${#ssid} -le 32 ]] || fail "SSID mancante o troppo lungo"
    [[ "$ssid" =~ ^[[:print:]]+$ ]] || fail "SSID con caratteri non validi"
    if [[ -n "$psk" ]]; then
        [[ ${#psk} -ge 8 && ${#psk} -le 63 && "$psk" =~ ^[[:print:]]+$ ]] || fail "Password non valida (8-63 caratteri)"
    fi

    save_snapshot wifi

    if [[ -n "$psk" ]]; then
        nmcli device wifi connect "$ssid" password "$psk" >/dev/null 2>&1 || abort "Connessione a «$ssid» non riuscita"
    else
        nmcli device wifi connect "$ssid" >/dev/null 2>&1 || abort "Connessione a «$ssid» non riuscita"
    fi

    arm_rollback_or_revert
    echo "OK"
}

# ── apply-ip <auto|manual> [indirizzo] [prefisso] [gateway] [dns] ────────
cmd_apply_ip() {
    local mode="${1:-}" addr="${2:-}" prefix="${3:-}" gw="${4:-}" dns="${5:-}"
    local ip_re='^([0-9]{1,3}\.){3}[0-9]{1,3}$'

    local dev conn
    dev=$(default_device)
    [[ -n "$dev" ]] || fail "Nessuna interfaccia di rete attiva"
    conn=$(active_connection_for "$dev")
    [[ -n "$conn" ]] || fail "Nessuna connessione attiva su $dev"

    # Convalida PRIMA di scrivere lo snapshot: un errore qui non deve lasciare
    # un rollback armato per una modifica che non è mai stata applicata.
    case "$mode" in
        auto) ;;
        manual)
            [[ "$addr" =~ $ip_re ]] || fail "Indirizzo IP non valido"
            [[ "$prefix" =~ ^[0-9]{1,2}$ && "$prefix" -ge 1 && "$prefix" -le 32 ]] || fail "Prefisso di rete non valido"
            [[ "$gw" =~ $ip_re ]] || fail "Gateway non valido"
            local d dns_ok=1
            IFS=',' read -ra DNS_LIST <<< "$dns"
            for d in "${DNS_LIST[@]}"; do
                [[ -z "$d" ]] && continue
                [[ "$d" =~ $ip_re ]] || dns_ok=0
            done
            [[ "$dns_ok" == 1 ]] || fail "DNS non valido"
            ;;
        *) fail "Modalità non valida" ;;
    esac

    save_snapshot ip "$conn"

    case "$mode" in
        auto)
            nmcli connection modify "$conn" ipv4.method auto ipv4.addresses '' ipv4.gateway '' ipv4.dns '' \
                || abort "Impostazione automatica fallita"
            ;;
        manual)
            nmcli connection modify "$conn" \
                ipv4.method manual \
                ipv4.addresses "$addr/$prefix" \
                ipv4.gateway "$gw" \
                ipv4.dns "$dns" \
                || abort "Impostazione IP fallita"
            ;;
    esac

    nmcli connection up "$conn" >/dev/null 2>&1 || abort "Riattivazione della connessione fallita"

    arm_rollback_or_revert
    echo "OK"
}

# Stato precedente, prima di applicare una modifica rischiosa. KIND distingue
# come rollback dovrà ripristinare (riconnettersi al profilo di prima, oppure
# riscrivere i parametri IPv4 di prima sullo stesso profilo).
save_snapshot() {
    local kind="$1" conn="${2:-}"
    mkdir -p "$ROLLBACK_DIR"
    {
        echo "KIND=$kind"
        echo "ROLLBACK_AT=$(( $(date +%s) + ROLLBACK_SECONDS ))"
        if [[ "$kind" == wifi ]]; then
            local dev prev
            dev=$(default_device)
            prev=$(active_connection_for "$dev")
            echo "PREV_CONNECTION=${prev:-}"
            # Da qui in poi il rollback a ROLLBACK_SECONDS di apply-wifi ha
            # la priorità sul timer di autoespirazione dell'hotspot: se
            # PREV_CONNECTION è proprio l'hotspot, cmd_rollback lo riarma.
            hotspot_timer_disarm
        else
            echo "CONNECTION=$conn"
            echo "PREV_METHOD=$(nmcli -t -g ipv4.method connection show "$conn" 2>/dev/null)"
            echo "PREV_ADDRESSES=$(nmcli -t -g ipv4.addresses connection show "$conn" 2>/dev/null)"
            echo "PREV_GATEWAY=$(nmcli -t -g ipv4.gateway connection show "$conn" 2>/dev/null)"
            echo "PREV_DNS=$(nmcli -t -g ipv4.dns connection show "$conn" 2>/dev/null)"
        fi
    } > "$SNAPSHOT"
    chmod 600 "$SNAPSHOT"
}

arm_rollback() {
    systemctl stop "$ROLLBACK_UNIT.timer" >/dev/null 2>&1 || true
    systemd-run --unit="$ROLLBACK_UNIT" --on-active="${ROLLBACK_SECONDS}s" \
        --description="Fluxus: ripristino rete se non confermata" \
        /usr/local/bin/fluxus-network.sh rollback >/dev/null 2>&1
}

# Se non si riesce ad armare il timer, la modifica appena fatta resterebbe
# senza rete di sicurezza: meglio disfarla subito davvero, non solo dirlo.
arm_rollback_or_revert() {
    arm_rollback && return 0
    cmd_rollback >/dev/null
    fail "Impossibile armare il ripristino automatico: modifica annullata per sicurezza"
}

# ── confirm ──────────────────────────────────────────────────────────────
# La modifica va bene: disarma il timer e getta lo snapshot.
cmd_confirm() {
    systemctl stop "$ROLLBACK_UNIT.timer" "$ROLLBACK_UNIT.service" >/dev/null 2>&1 || true
    rm -f "$SNAPSHOT"
    # La rete vera è confermata: l'hotspot, se esisteva, non serve più —
    # niente timer pendente, niente profilo residuo con priorità di
    # autoconnect che potrebbe interferire più avanti.
    hotspot_timer_disarm
    nmcli connection delete "$HOTSPOT_CONN_NAME" >/dev/null 2>&1 || true
    hotspot_state_clear
    echo "OK"
}

# ── rollback ─────────────────────────────────────────────────────────────
# Invocato SOLO dal timer di systemd (root), mai direttamente da PHP/www-data
# con argomenti: legge da sé lo snapshot, non ne riceve.
cmd_rollback() {
    [[ -f "$SNAPSHOT" ]] || { echo "OK niente da ripristinare"; exit 0; }

    local kind prev conn method addresses gateway dns
    kind=$(awk -F= '$1=="KIND"{print $2}' "$SNAPSHOT")

    if [[ "$kind" == wifi ]]; then
        prev=$(awk -F= '$1=="PREV_CONNECTION"{print $2}' "$SNAPSHOT")
        if [[ -n "$prev" ]]; then
            nmcli connection up "$prev" >/dev/null 2>&1
            # Si stava tornando all'hotspot: senza riarmare qui, resterebbe
            # attivo senza scadenza (il suo timer era stato disarmato
            # all'ingresso in apply-wifi, vedi save_snapshot).
            if [[ "$prev" == "$HOTSPOT_CONN_NAME" ]]; then
                local ssid
                ssid=$(nmcli -t -g 802-11-wireless.ssid connection show "$HOTSPOT_CONN_NAME" 2>/dev/null)
                hotspot_state_write "${ssid:-$HOTSPOT_CONN_NAME}"
                hotspot_timer_arm
            fi
        fi
    elif [[ "$kind" == ip ]]; then
        conn=$(awk -F= '$1=="CONNECTION"{print $2}' "$SNAPSHOT")
        method=$(awk -F= '$1=="PREV_METHOD"{print $2}' "$SNAPSHOT")
        addresses=$(awk -F= '$1=="PREV_ADDRESSES"{print $2}' "$SNAPSHOT")
        gateway=$(awk -F= '$1=="PREV_GATEWAY"{print $2}' "$SNAPSHOT")
        dns=$(awk -F= '$1=="PREV_DNS"{print $2}' "$SNAPSHOT")
        if [[ -n "$conn" ]]; then
            if [[ "$method" == manual ]]; then
                nmcli connection modify "$conn" ipv4.method manual \
                    ipv4.addresses "$addresses" ipv4.gateway "$gateway" ipv4.dns "$dns" >/dev/null 2>&1
            else
                nmcli connection modify "$conn" ipv4.method auto \
                    ipv4.addresses '' ipv4.gateway '' ipv4.dns '' >/dev/null 2>&1
            fi
            nmcli connection up "$conn" >/dev/null 2>&1
        fi
    fi

    rm -f "$SNAPSHOT"
    echo "OK ripristinato"
}

# ── hostname <nome> ──────────────────────────────────────────────────────
# Basso rischio, nessun rollback: non tocca la connettività.
cmd_hostname() {
    local name="${1:-}"
    [[ "$name" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]] || fail "Nome macchina non valido"
    hostnamectl set-hostname "$name" || fail "Impostazione del nome macchina fallita"
    echo "OK"
}

# ── hotspot: funzioni condivise ─────────────────────────────────────────

# Il device WiFi, non "quello della rotta predefinita" (default_device):
# qui può non esserci ancora nessuna rotta affatto, è proprio il caso che
# l'hotspot deve coprire.
wifi_device() {
    nmcli -t -f DEVICE,TYPE device status 2>/dev/null | awk -F: '$2=="wifi"{print $1; exit}'
}

# SSID deterministico dal MAC, non dall'hostname: stabile fra un riavvio e
# l'altro, e soprattutto unico per macchina fisica anche prima che
# l'hostname sia stato personalizzato (spesso ancora "raspberrypi" su più
# unità nella stessa stanza durante il provisioning — vedi ROADMAP.md,
# fase 6).
hotspot_ssid() {
    local dev="$1" mac suffix
    mac=$(cat "/sys/class/net/$dev/address" 2>/dev/null)
    suffix=$(tr -d ':' <<< "$mac" | tr 'a-z' 'A-Z' | tail -c 5)
    echo "Fluxus-${suffix:-0000}"
}

hotspot_state_write() {
    local ssid="$1"
    mkdir -p "$ROLLBACK_DIR"
    { echo "SSID=$ssid"; echo "STARTED_AT=$(date +%s)"; } > "$HOTSPOT_STATE"
}
hotspot_state_clear() { rm -f "$HOTSPOT_STATE"; }

hotspot_timer_arm() {
    systemctl stop "$HOTSPOT_TIMEOUT_UNIT.timer" >/dev/null 2>&1 || true
    systemd-run --unit="$HOTSPOT_TIMEOUT_UNIT" --on-active="${HOTSPOT_TIMEOUT_SECONDS}s" \
        --description="Fluxus: hotspot di configurazione, scadenza" \
        /usr/local/bin/fluxus-network.sh hotspot-timeout >/dev/null 2>&1
}
hotspot_timer_disarm() {
    systemctl stop "$HOTSPOT_TIMEOUT_UNIT.timer" "$HOTSPOT_TIMEOUT_UNIT.service" >/dev/null 2>&1 || true
}

# Indirizzo fissato esplicitamente (HOTSPOT_ADDR), non il default implicito
# di NetworkManager: la pagina/il manuale devono poter dire "vai su
# http://10.42.0.1" con certezza. Nessun captive portal: nginx risponde già
# su qualunque indirizzo (listen ... default_server; server_name _).
start_hotspot() {
    local dev ssid
    dev=$(wifi_device)
    [[ -n "$dev" ]] || return 1
    ssid=$(hotspot_ssid "$dev")

    nmcli connection delete "$HOTSPOT_CONN_NAME" >/dev/null 2>&1 || true
    nmcli device wifi hotspot ifname "$dev" con-name "$HOTSPOT_CONN_NAME" \
        ssid "$ssid" password "$HOTSPOT_PASSWORD" >/dev/null 2>&1 || return 1
    nmcli connection modify "$HOTSPOT_CONN_NAME" ipv4.addresses "$HOTSPOT_ADDR" ipv4.method shared >/dev/null 2>&1
    nmcli connection up "$HOTSPOT_CONN_NAME" >/dev/null 2>&1 || return 1

    hotspot_state_write "$ssid"
    hotspot_timer_arm
    return 0
}

stop_hotspot() {
    hotspot_timer_disarm
    nmcli connection down "$HOTSPOT_CONN_NAME" >/dev/null 2>&1 || true
    hotspot_state_clear
}

# Vero se il device è connesso (GENERAL.STATE inizia con "100").
wifi_is_connected() {
    local dev="$1" state
    state=$(nmcli -t -f GENERAL.STATE device show "$dev" 2>/dev/null | cut -d: -f2-)
    [[ "$state" == 100* ]]
}

# ── hotspot-check ────────────────────────────────────────────────────────
# SOLO al boot, invocato da fluxus-hotspot-check.service (root). Non è
# sorveglianza continua: gira una volta e finisce, non un demone. Come
# cmd_rollback, non usa fail(): un esito "niente da fare" o "non ci sono
# riuscito" sono entrambi normali qui, mai un'eccezione per chi legge i log.
cmd_hotspot_check() {
    local dev n_profiles waited=0

    dev=$(wifi_device)
    if [[ -z "$dev" ]]; then
        echo "OK nessuna scheda WiFi"
        return 0
    fi

    # Profili WiFi salvati, esclusa l'hotspot stessa (residuo di un boot
    # precedente non ancora ripulito).
    n_profiles=$(nmcli -t -f NAME,TYPE connection show 2>/dev/null \
        | awk -F: -v skip="$HOTSPOT_CONN_NAME" '$2=="802-11-wireless" && $1!=skip' | wc -l)

    if [[ "$n_profiles" -eq 0 ]]; then
        # Primo avvio inequivocabile: non serve aspettare oltre, la unit
        # parte già dopo NetworkManager-wait-online.
        sleep "$HOTSPOT_GRACE_UNCONFIGURED"
    else
        # C'è già una rete nota: un router lento a ripartire dopo un
        # blackout non deve far scattare l'hotspot al primo sguardo.
        while (( waited < HOTSPOT_GRACE_KNOWN )); do
            wifi_is_connected "$dev" && { echo "OK connesso"; return 0; }
            sleep "$HOTSPOT_POLL_INTERVAL"
            waited=$(( waited + HOTSPOT_POLL_INTERVAL ))
        done
    fi

    wifi_is_connected "$dev" && { echo "OK connesso"; return 0; }

    if start_hotspot; then
        echo "OK hotspot avviato"
    else
        echo "OK impossibile avviare l'hotspot"
    fi
}

# ── hotspot-start / hotspot-stop ────────────────────────────────────────
# Richiamabili anche da PHP via sudo (stessa regola wildcard di tutti gli
# altri comandi): trigger manuale da network.php, es. router cambiato senza
# accesso fisico alla macchina.
cmd_hotspot_start() {
    start_hotspot || fail "Impossibile avviare l'hotspot"
    echo "OK"
}
cmd_hotspot_stop() {
    stop_hotspot
    echo "OK"
}

# ── hotspot-timeout ──────────────────────────────────────────────────────
# Invocato SOLO dal proprio timer transiente (root), mai da PHP: chiude
# l'hotspot e, se nel frattempo non è comparsa una rete nota, lo riapre da
# capo. Nessun limite al numero di cicli: ogni ciclo è innocuo, e questo
# garantisce che la macchina non resti mai muta a lungo.
cmd_hotspot_timeout() {
    nmcli connection down "$HOTSPOT_CONN_NAME" >/dev/null 2>&1 || true
    hotspot_state_clear

    # Finestra breve per un eventuale autoconnect a una rete nota nel
    # frattempo, prima di riaprire l'hotspot da capo.
    sleep 5
    local dev
    dev=$(wifi_device)
    if [[ -n "$dev" ]] && wifi_is_connected "$dev"; then
        echo "OK connesso, hotspot non riaperto"
        return 0
    fi

    if start_hotspot; then
        echo "OK hotspot riaperto"
    else
        echo "OK impossibile riaprire l'hotspot"
    fi
}

COMANDO="${1:-}"
shift || true
case "$COMANDO" in
    status)      cmd_status ;;
    scan)        cmd_scan ;;
    apply-wifi)  cmd_apply_wifi "$@" ;;
    apply-ip)    cmd_apply_ip "$@" ;;
    confirm)     cmd_confirm ;;
    rollback)    cmd_rollback ;;
    hostname)    cmd_hostname "$@" ;;
    hotspot-check)   cmd_hotspot_check ;;
    hotspot-start)   cmd_hotspot_start ;;
    hotspot-stop)    cmd_hotspot_stop ;;
    hotspot-timeout) cmd_hotspot_timeout ;;
    *) fail "Comando sconosciuto" ;;
esac
