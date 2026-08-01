#!/bin/bash
# Fluxus-Media — retention_cleanup.sh
# Applica i limiti di retention per sorgente: max_recordings, max_days_recordings,
# max_clips_per_marker, max_days_clips. Eseguito periodicamente da systemd timer.

source "$(dirname "${BASH_SOURCE[0]}")/fluxus-env.sh"

mkdir -p "$FM_LOGS" "$FM_TMP"

exec 201>"$FM_TMP/retention.lock"
flock -n 201 || exit 0

LOG="$FM_LOGS/fm-retention.log"
exec >> "$LOG" 2>&1
echo "=== retention_cleanup.sh $(date '+%F %T') ==="

sq() { sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "$1"; }

# Un path su volume esterno è utilizzabile solo se quel volume è collegato (file
# sentinella nella sua radice). Senza questo controllo la retention cancellerebbe
# le righe DB di file che stanno su un disco staccato, lasciandoli orfani al suo
# ritorno. I path sotto FM_BASE sono sempre raggiungibili (vincolo 21).
path_available() {
    local P="$1"
    [[ -z "$P" ]] && return 1
    case "$P" in
        "$FM_BASE"/*) return 0 ;;
    esac
    local VPATH DEFAULT
    while IFS='|' read -r VPATH DEFAULT; do
        [[ -z "$VPATH" ]] && continue
        [[ "$DEFAULT" == "1" ]] && continue
        case "$P" in
            "${VPATH%/}"/*)
                [[ -f "${VPATH%/}/.fluxus-volume" ]] && return 0 || return 1
                ;;
        esac
    done < <(sq "SELECT path, is_default FROM storage_volumes WHERE active=1;")
    return 0
}

# Cartella dei cue di una registrazione: clips_dir se valorizzata (v2.5.0+),
# altrimenti il path storico FM_CLIPS/{source_id}.
clips_dir_for() {
    local CDIR="$1" SRC_ID="$2"
    [[ -n "$CDIR" ]] && { echo "$CDIR"; return; }
    echo "$FM_CLIPS/$SRC_ID"
}

delete_recording() {
    local RID="$1"
    local OUTDIR FBASE CDIR
    IFS='|' read -r OUTDIR FBASE CDIR <<< "$(sq "SELECT output_dir, filename_base, COALESCE(clips_dir,'') FROM recordings WHERE id=$RID;")"
    [[ -z "$OUTDIR" ]] && return
    if ! path_available "$OUTDIR"; then
        echo "  saltata registrazione #$RID ($FBASE): volume non collegato ($OUTDIR)"
        return
    fi
    rm -f "$OUTDIR/${FBASE}".* "$OUTDIR/${FBASE}"_[0-9][0-9][0-9].* 2>/dev/null
    while IFS='|' read -r SRC_ID CLIPF TRIMF; do
        [[ -z "$SRC_ID" ]] && continue
        local CLIP_DIR
        CLIP_DIR=$(clips_dir_for "$CDIR" "$SRC_ID")
        [[ -n "$CLIPF" ]] && rm -f "$CLIP_DIR/$CLIPF"
        [[ -n "$TRIMF" ]] && rm -f "$CLIP_DIR/$TRIMF"
    done < <(sq "SELECT r.source_id, m.clip_filename, m.clip_trim_filename FROM markers m JOIN recordings r ON r.id=m.recording_id WHERE m.recording_id=$RID;")
    sqlite3 -cmd ".timeout 5000" "$FM_DB" "DELETE FROM recordings WHERE id=$RID;"
    # Il suo log non sparisce con lei: resta ancora leggibile per un mese, un
    # margine per capire cos'è successo a una registrazione che non c'è più. Il
    # tocco segna l'istante della cancellazione — non quello, molto più vecchio,
    # dell'ultima riga scritta da record.sh — perché è da lì che deve contare il
    # mese di grazia. cleanup_orphaned_record_logs() lo cancella davvero quando
    # scade, e solo per le registrazioni che non esistono più (vedi sotto: non
    # deve toccare il log di una registrazione ancora nel database, per quanto
    # vecchio sia il suo ultimo aggiornamento).
    touch "$FM_LOGS/fm-record-${RID}.log" 2>/dev/null
    echo "  eliminata registrazione #$RID ($FBASE)"
}

# Un mese dopo la cancellazione (vedi delete_recording), il log smette anche
# lui di esistere — ma solo se la registrazione a cui appartiene è DAVVERO
# sparita dal database: un log "vecchio" di una registrazione ancora attiva
# (magari conservata a lungo su un volume esterno capiente) non va toccato,
# quale che sia la sua età per mtime.
cleanup_orphaned_record_logs() {
    local F ID ESISTE
    while IFS= read -r -d '' F; do
        ID=$(basename "$F"); ID="${ID#fm-record-}"; ID="${ID%.log}"
        [[ "$ID" =~ ^[0-9]+$ ]] || continue
        ESISTE=$(sq "SELECT 1 FROM recordings WHERE id=$ID;")
        if [[ -z "$ESISTE" ]]; then
            rm -f "$F"
            echo "  rimosso log orfano (registrazione #$ID cancellata da oltre 30 giorni): $(basename "$F")"
        fi
    done < <(find "$FM_LOGS" -maxdepth 1 -name 'fm-record-*.log' -mtime +30 -print0 2>/dev/null)
}

delete_cue() {
    local MID="$1" SRC_ID="$2" CLIPF="$3" TRIMF="$4" CDIR="$5"
    local CLIP_DIR
    CLIP_DIR=$(clips_dir_for "$CDIR" "$SRC_ID")
    if ! path_available "$CLIP_DIR"; then
        echo "  saltato cue #$MID: volume non collegato ($CLIP_DIR)"
        return
    fi
    [[ -n "$CLIPF" ]] && rm -f "$CLIP_DIR/$CLIPF"
    [[ -n "$TRIMF" ]] && rm -f "$CLIP_DIR/$TRIMF"
    sqlite3 -cmd ".timeout 5000" "$FM_DB" "DELETE FROM markers WHERE id=$MID;"
    echo "  eliminato cue #$MID"
}

SOURCES=$(sq "SELECT id, COALESCE(max_recordings,0), COALESCE(max_days_recordings,0), COALESCE(max_clips_per_marker,0), COALESCE(max_days_clips,0) FROM sources WHERE active=1;")
[[ -z "$SOURCES" ]] && { echo "nessuna sorgente attiva"; exit 0; }

while IFS='|' read -r SRC_ID MAXREC MAXDAYSREC MAXCLIPS MAXDAYSCLIPS; do
    [[ -z "$SRC_ID" ]] && continue

    if [[ "$MAXDAYSREC" -gt 0 ]]; then
        for RID in $(sq "SELECT id FROM recordings WHERE source_id=$SRC_ID AND status!='recording' AND start_time IS NOT NULL AND julianday('now') - julianday(start_time) > $MAXDAYSREC;"); do
            delete_recording "$RID"
        done
    fi

    if [[ "$MAXREC" -gt 0 ]]; then
        for RID in $(sq "SELECT id FROM recordings WHERE source_id=$SRC_ID AND status!='recording' ORDER BY start_time DESC LIMIT -1 OFFSET $MAXREC;"); do
            delete_recording "$RID"
        done
    fi

    if [[ "$MAXDAYSCLIPS" -gt 0 ]]; then
        while IFS='|' read -r MID CLIPF TRIMF CDIR; do
            [[ -z "$MID" ]] && continue
            delete_cue "$MID" "$SRC_ID" "$CLIPF" "$TRIMF" "$CDIR"
        done < <(sq "SELECT m.id, m.clip_filename, m.clip_trim_filename, COALESCE(r.clips_dir,'') FROM markers m JOIN recordings r ON r.id=m.recording_id WHERE r.source_id=$SRC_ID AND m.type='cue' AND m.clip_status='ready' AND julianday('now') - julianday(m.created_at) > $MAXDAYSCLIPS;")
    fi

    if [[ "$MAXCLIPS" -gt 0 ]]; then
        while IFS='|' read -r MID CLIPF TRIMF CDIR; do
            [[ -z "$MID" ]] && continue
            delete_cue "$MID" "$SRC_ID" "$CLIPF" "$TRIMF" "$CDIR"
        done < <(sq "SELECT m.id, m.clip_filename, m.clip_trim_filename, COALESCE(r.clips_dir,'') FROM markers m JOIN recordings r ON r.id=m.recording_id WHERE r.source_id=$SRC_ID AND m.type='cue' AND m.clip_status='ready' ORDER BY m.created_at DESC LIMIT -1 OFFSET $MAXCLIPS;")
    fi
done <<< "$SOURCES"

cleanup_orphaned_record_logs

echo "=== fine $(date '+%F %T') ==="
