#!/bin/bash
# Fluxus-Media — run_schedule.sh <schedule_id>
# Invocato dal timer systemd fm-sched-{id}.timer. Crea la riga recording
# (status='pending') e lancia record.sh, che aggiorna status='recording'.
#
# 2026-07-27: aggiunto il log FM_LOGS/fm-schedule.log. Motivo: il 2026-07-27 alle
# 20:52:24 una registrazione (id 48, schedule 8) è partita nell'istante esatto di un
# salvataggio da schedules.php, senza che si potesse stabilire chi avesse avviato il
# service — l'output di questo script finiva solo nel journal, che su questo host è
# vuoto. Il log annota anche se l'ora dell'avvio è compatibile con l'on_calendar
# dello schedule, ma NON blocca nulla: è puramente diagnostico.

FM_BASE="/var/lib/fluxus-media"
FM_DB="$FM_BASE/db/fluxus_media.db"
FM_RECORDINGS="$FM_BASE/recordings"
FM_SCRIPTS="$FM_BASE/scripts"
FM_LOGS="$FM_BASE/logs"

mkdir -p "$FM_LOGS"
SCHED_LOG="$FM_LOGS/fm-schedule.log"
log() { printf '%s [sched %s] %s\n' "$(date '+%F %T')" "${SCHEDULE_ID:-?}" "$*" >> "$SCHED_LOG"; }

SCHEDULE_ID="$1"
[[ -z "$SCHEDULE_ID" ]] && { echo "Uso: run_schedule.sh <schedule_id>"; exit 1; }

# Chi ha invocato lo script: distingue il timer da un avvio a mano o da un altro
# processo. INVOCATION_ID è impostato solo quando si gira dentro un'unit systemd.
PARENT_CMD=$(tr '\0' ' ' < "/proc/$PPID/cmdline" 2>/dev/null | sed 's/ *$//' | cut -c1-120)
log "AVVIO pid=$$ ppid=$PPID (${PARENT_CMD:-ignoto}) invocation=${INVOCATION_ID:-nessuna} utente=$(id -un)"

IFS='|' read -r SOURCE_ID SLOT_DURATION SEGMENT_DURATION ACTIVE ON_CALENDAR <<EOF
$(sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "SELECT source_id, slot_duration, segment_duration, active, COALESCE(on_calendar,'') FROM schedules WHERE id=$SCHEDULE_ID;")
EOF

if [[ -z "$SOURCE_ID" ]]; then
    echo "Schedule $SCHEDULE_ID non trovato"
    log "ESITO: schedule non trovato nel DB, esco"
    exit 1
fi

# Annotazione diagnostica: l'ora di adesso è riconducibile all'on_calendar dello
# schedule? La finestra tiene conto dell'AccuracySec di systemd (1 min di default)
# più il ritardo di avvio. Nessun blocco: serve solo a rendere leggibile, la
# prossima volta, se un avvio è arrivato dal calendario o da qualcos'altro.
TIMING="non valutabile (on_calendar vuoto)"
if [[ -n "$ON_CALENDAR" ]]; then
    NOW_TS=$(date +%s)
    ELAPSE=$(systemd-analyze calendar --iterations=1 --base-time="@$(( NOW_TS - 180 ))" "$ON_CALENDAR" 2>/dev/null \
             | sed -n 's/^ *Next elapse: *//p')
    ELAPSE_TS=$(date -d "$ELAPSE" +%s 2>/dev/null)
    if [[ -z "$ELAPSE" || -z "$ELAPSE_TS" ]]; then
        TIMING="non valutabile (systemd-analyze non ha saputo leggere '$ON_CALENDAR')"
    elif [[ $ELAPSE_TS -le $NOW_TS ]]; then
        TIMING="ATTESO (elapse previsto $ELAPSE, scarto $(( NOW_TS - ELAPSE_TS ))s)"
    else
        TIMING="FUORI ORARIO (il prossimo elapse di '$ON_CALENDAR' è $ELAPSE): avvio non riconducibile al calendario"
    fi
fi
log "schedule: on_calendar='$ON_CALENDAR' slot=${SLOT_DURATION}s seg=${SEGMENT_DURATION}s active=$ACTIVE source=$SOURCE_ID"
log "orario: $TIMING"

if [[ "$ACTIVE" != "1" ]]; then
    echo "Schedule $SCHEDULE_ID non attivo, salto"
    log "ESITO: schedule non attivo, nessuna registrazione creata"
    exit 0
fi

IFS='|' read -r SOURCE_NAME MEDIA_TYPE ACTIVE_SRC FILE_PREFIX SRC_VOLUME_ID <<EOF
$(sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "SELECT name, media_type, active, COALESCE(file_prefix,''), COALESCE(storage_volume_id,0) FROM sources WHERE id=$SOURCE_ID;")
EOF

if [[ -z "$SOURCE_NAME" || "$ACTIVE_SRC" != "1" ]]; then
    echo "Sorgente $SOURCE_ID non trovata o non attiva, salto"
    log "ESITO: sorgente $SOURCE_ID non trovata o non attiva, nessuna registrazione creata"
    exit 0
fi

SLUG_SRC="$FILE_PREFIX"
[[ -z "$SLUG_SRC" ]] && SLUG_SRC="$SOURCE_NAME"
SLUG=$(echo "$SLUG_SRC" | iconv -t ascii//TRANSLIT 2>/dev/null | sed -E 's/[^A-Za-z0-9]+/_/g; s/^_+|_+$//g')
[[ -z "$SLUG" ]] && SLUG="source"
NOW=$(date '+%Y-%m-%d_%H-%M')
FBASE="${SLUG}_${NOW}"

# Volume di destinazione: override della sorgente, altrimenti il default del
# media_type. Un volume esterno vale solo se contiene il file sentinella: quando
# il disco si scollega resta la directory vuota del mount point, e senza questo
# controllo si scriverebbe sulla microSD credendo di scrivere sull'esterno.
# Se non è utilizzabile si ripiega sul volume interno (vincolo 21).
VOLUME_ID="$SRC_VOLUME_ID"
if [[ -z "$VOLUME_ID" || "$VOLUME_ID" == "0" ]]; then
    VOLUME_ID=$(sqlite3 -cmd ".timeout 5000" "$FM_DB" "SELECT COALESCE((SELECT value FROM settings WHERE key='storage_volume_${MEDIA_TYPE}'),'1');")
fi
IFS='|' read -r VOL_PATH VOL_LABEL VOL_DEFAULT <<EOF
$(sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "SELECT path, label, is_default FROM storage_volumes WHERE id=${VOLUME_ID:-1} AND active=1;")
EOF

STORAGE_NOTE=""
if [[ -z "$VOL_PATH" ]]; then
    VOL_PATH="$FM_BASE"
elif [[ "$VOL_DEFAULT" != "1" && ! -f "$VOL_PATH/.fluxus-volume" ]]; then
    STORAGE_NOTE="volume \"$VOL_LABEL\" non collegato: registrazione sul volume interno"
    VOL_PATH="$FM_BASE"
elif [[ ! -w "$VOL_PATH" ]]; then
    STORAGE_NOTE="volume \"$VOL_LABEL\" non scrivibile: registrazione sul volume interno"
    VOL_PATH="$FM_BASE"
fi

OUTPUT_DIR="${VOL_PATH%/}/recordings/$SOURCE_ID"
CLIPS_DIR="${VOL_PATH%/}/clips/$SOURCE_ID"
mkdir -p "$OUTPUT_DIR" "$CLIPS_DIR"

if [[ -n "$STORAGE_NOTE" ]]; then
    log "ATTENZIONE: $STORAGE_NOTE"
else
    log "archiviazione: volume ${VOL_LABEL:-interno} -> $OUTPUT_DIR"
fi

RECORDING_ID=$(sqlite3 -cmd ".timeout 5000" "$FM_DB" "INSERT INTO recordings
    (source_id, schedule_id, source_name, media_type, filename_base, output_dir, clips_dir, status, trigger_type, slot_duration, segment_duration, notes)
    VALUES ($SOURCE_ID, $SCHEDULE_ID, '$(echo "$SOURCE_NAME" | sed "s/'/''/g")', '$MEDIA_TYPE', '$FBASE', '$OUTPUT_DIR', '$CLIPS_DIR', 'pending', 'scheduled', $SLOT_DURATION, $SEGMENT_DURATION, '$(echo "$STORAGE_NOTE" | sed "s/'/''/g")');
    SELECT last_insert_rowid();")

if [[ -z "$RECORDING_ID" ]]; then
    echo "INSERT fallita: nessuna registrazione creata"
    log "ESITO: INSERT in recordings fallita (DB occupato?), nessuna registrazione creata"
    exit 1
fi

log "sorgente $SOURCE_ID '$SOURCE_NAME' ($MEDIA_TYPE) -> registrazione $RECORDING_ID, filename_base=$FBASE"

nohup "$FM_SCRIPTS/record.sh" "$RECORDING_ID" "$SOURCE_ID" "$SLOT_DURATION" "$SEGMENT_DURATION" >> "$FM_BASE/logs/fm-record-${RECORDING_ID}.log" 2>&1 &

log "ESITO: record.sh avviato (pid $!) per la registrazione $RECORDING_ID"
