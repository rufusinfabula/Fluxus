#!/bin/bash
# Fluxus — fluxus-enable-volume.sh <UUID> <cartella> <utente> <gruppo>
#
# Prepara un disco perché Fluxus possa scriverci: lo stacca dall'automount del
# desktop, lo monta stabilmente sotto /mnt da /etc/fstab (per UUID, con nofail)
# e crea la cartella dati, di proprietà dell'utente di Fluxus.
#
# Invocato da api/volume_enable.php via sudo (regola in /etc/sudoers.d/<istanza>).
#
# ⚠️ Questo script è UNICO per tutta la macchina, mentre le installazioni
# possono essere più d'una: cartella, utente e gruppo arrivano quindi come
# argomenti. Non li legge da una configurazione, e non deve farlo: gira come
# root su richiesta di un processo che root non è. È la regola sudo, che li
# fissa ai valori di quell'istanza, l'unica autorità su quali valori siano
# leciti — qui si controlla solo che siano sensati.
#
# ⚠️ Deve restare root:root 0755 e FUORI dalla cartella scripts dell'istanza,
# che appartiene all'utente di Fluxus: se quello potesse riscriverlo, la regola
# sudo NOPASSWD gli darebbe root completo.
#
# Stampa una riga "OK <mountpoint>" oppure "ERR <messaggio>".

set -u
export LC_ALL=C
PATH=/usr/sbin:/usr/bin:/sbin:/bin

fail() { echo "ERR $*"; exit 1; }

# --info <UUID>: dimensioni del filesystem lette da root, per i dischi che
# www-data non può nemmeno attraversare (automount del desktop sotto /media).
# Non modifica nulla. Stampa "INFO <total_bytes> <free_bytes>".
if [[ "${1:-}" == "--info" ]]; then
    U="${2:-}"
    [[ "$U" =~ ^[A-Fa-f0-9-]{4,36}$ ]] || fail "UUID non valido"
    D=$(blkid -U "$U" 2>/dev/null) || true
    [[ -n "$D" && -b "$D" ]] || fail "Dispositivo non trovato"
    T=$(findmnt -nlo TARGET -S "$D" 2>/dev/null | head -n 1)
    if [[ -n "$T" ]]; then
        read -r TOT FREE < <(df -B1 --output=size,avail "$T" 2>/dev/null | tail -n 1)
    fi
    [[ -n "${TOT:-}" ]] || TOT=$(lsblk -bno SIZE "$D" 2>/dev/null | head -n 1)
    echo "INFO ${TOT:-0} ${FREE:-0}"
    exit 0
fi

UUID="${1:-}"
[[ -n "$UUID" ]] || fail "UUID mancante"
# Copre UUID ext4 (8-4-4-4-12) e le forme corte di vfat/exfat/ntfs (FC06-F2C2).
[[ "$UUID" =~ ^[A-Fa-f0-9-]{4,36}$ ]] || fail "UUID non valido"

# Cartella che Fluxus crea sulla radice del disco: porta il nome dell'istanza,
# così due installazioni sulla stessa macchina possono usare lo stesso disco
# senza sovrascriversi le registrazioni.
DATA_NAME="${2:-}"
[[ "$DATA_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || fail "Nome cartella non valido"

FLUXUS_USER="${3:-}"
FLUXUS_GROUP="${4:-}"
FLUXUS_UID=$(id -u "$FLUXUS_USER" 2>/dev/null) || fail "Utente $FLUXUS_USER inesistente"
FLUXUS_GID=$(getent group "$FLUXUS_GROUP" 2>/dev/null | cut -d: -f3)
[[ -n "$FLUXUS_GID" ]] || fail "Gruppo $FLUXUS_GROUP inesistente"

DEV=$(blkid -U "$UUID" 2>/dev/null) || true
[[ -n "$DEV" && -b "$DEV" ]] || fail "Nessun dispositivo con UUID $UUID"

# Mai toccare la scheda di sistema: né la root né la partizione di boot.
ROOT_SRC=$(findmnt -no SOURCE / 2>/dev/null)
BOOT_SRC=$(findmnt -no SOURCE /boot/firmware 2>/dev/null)
[[ "$DEV" == "$ROOT_SRC" || "$DEV" == "$BOOT_SRC" ]] && fail "Il disco di sistema non si tocca"

# Solo dischi rimovibili o comunque diversi da quello che ospita la root.
ROOT_DISK=$(lsblk -no PKNAME "$ROOT_SRC" 2>/dev/null)
DEV_DISK=$(lsblk -no PKNAME "$DEV" 2>/dev/null)
[[ -n "$DEV_DISK" && "$DEV_DISK" == "$ROOT_DISK" ]] && fail "La partizione sta sul disco di sistema"

FSTYPE=$(blkid -o value -s TYPE "$DEV" 2>/dev/null)
[[ -n "$FSTYPE" ]] || fail "Filesystem non riconosciuto"
case "$FSTYPE" in
    ext2|ext3|ext4|xfs|btrfs|f2fs|vfat|exfat|ntfs|ntfs3|fuseblk) ;;
    *) fail "Filesystem $FSTYPE non supportato" ;;
esac

LABEL=$(blkid -o value -s LABEL "$DEV" 2>/dev/null)
[[ -n "$LABEL" ]] || LABEL=$(basename "$DEV")
# Il mount point non deve poter uscire da /mnt: si tengono solo caratteri sicuri.
SLUG=$(printf '%s' "$LABEL" | iconv -t ascii//TRANSLIT 2>/dev/null | tr -c 'A-Za-z0-9_-' '_' | sed -E 's/_+/_/g; s/^_+|_+$//g')
[[ -n "$SLUG" ]] || SLUG=$(basename "$DEV")
MOUNT="/mnt/$SLUG"
[[ "$MOUNT" == /mnt/* && "$MOUNT" != "/mnt/" && "$MOUNT" != *".."* ]] || fail "Punto di mount non valido"

# Se un ALTRO dispositivo è già montato lì, non si sovrascrive.
CUR=$(findmnt -no SOURCE "$MOUNT" 2>/dev/null || true)
[[ -n "$CUR" && "$CUR" != "$DEV" ]] && fail "$MOUNT è già occupato da $CUR"

# Stacca l'automount del desktop (/media/<utente>/<label>), dove www-data non entra.
while read -r CURMOUNT; do
    [[ -z "$CURMOUNT" || "$CURMOUNT" == "$MOUNT" ]] && continue
    umount "$CURMOUNT" 2>/dev/null || udisksctl unmount -b "$DEV" --no-user-interaction >/dev/null 2>&1 || true
done < <(findmnt -nro TARGET -S "$DEV" 2>/dev/null)

mkdir -p "$MOUNT" || fail "Impossibile creare $MOUNT"

# Opzioni: i filesystem senza permessi POSIX vanno montati assegnandoli
# all'utente di Fluxus, gli altri usano i permessi sul filesystem.
case "$FSTYPE" in
    vfat|exfat|ntfs|ntfs3|fuseblk) OPTS="defaults,nofail,x-systemd.device-timeout=10,uid=$FLUXUS_UID,gid=$FLUXUS_GID,umask=002" ;;
    *)                             OPTS="defaults,nofail,x-systemd.device-timeout=10" ;;
esac
[[ "$FSTYPE" == "ntfs" || "$FSTYPE" == "fuseblk" ]] && FSTYPE="ntfs3"

# fstab: una sola riga per UUID, aggiornata se già presente.
cp -a /etc/fstab "/etc/fstab.bak-fluxus-$(date +%Y%m%d%H%M%S)"
if grep -q "^UUID=$UUID[[:space:]]" /etc/fstab; then
    sed -i "s#^UUID=$UUID[[:space:]].*#UUID=$UUID  $MOUNT  $FSTYPE  $OPTS  0  2#" /etc/fstab
else
    printf 'UUID=%s  %s  %s  %s  0  2\n' "$UUID" "$MOUNT" "$FSTYPE" "$OPTS" >> /etc/fstab
fi

systemctl daemon-reload >/dev/null 2>&1 || true
if ! findmnt -no TARGET "$MOUNT" >/dev/null 2>&1; then
    mount "$MOUNT" 2>/dev/null || fail "Montaggio di $MOUNT fallito (vedi dmesg)"
fi

DATA="$MOUNT/$DATA_NAME"
mkdir -p "$DATA/recordings" "$DATA/clips" || fail "Impossibile creare $DATA"
# Su vfat/exfat chown non ha effetto (l'owner lo impone il mount): non è un errore.
chown -R "$FLUXUS_UID:$FLUXUS_GID" "$DATA" 2>/dev/null || true
chmod 2775 "$DATA" "$DATA/recordings" "$DATA/clips" 2>/dev/null || true
touch "$DATA/.fluxus-volume" 2>/dev/null || fail "$DATA non è scrivibile"
chown "$FLUXUS_UID:$FLUXUS_GID" "$DATA/.fluxus-volume" 2>/dev/null || true

# Prova finale: deve poterci scrivere davvero l'utente di Fluxus, non root.
if ! su -s /bin/sh -c "test -w '$DATA/recordings'" "$FLUXUS_USER"; then
    fail "$DATA/recordings non risulta scrivibile da $FLUXUS_USER"
fi

echo "OK $MOUNT"
