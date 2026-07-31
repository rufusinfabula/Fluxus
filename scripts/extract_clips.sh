#!/bin/bash
# Fluxus-Media — extract_clips.sh
# Estrae le clip (cue) pending: pre-roll prima + post-roll dopo il marker,
# configurabili in Impostazioni (settings.cue_pre_roll / cue_post_roll,
# default 30/90 se non impostati).
# Eseguito ogni 30s da fm-extract-clips.timer.

FM_BASE="/var/lib/fluxus-media"
FM_DB="$FM_BASE/db/fluxus_media.db"
FM_CLIPS="$FM_BASE/clips"
FM_TMP="$FM_BASE/tmp"
FM_LOGS="$FM_BASE/logs"

mkdir -p "$FM_TMP" "$FM_LOGS"

exec 200>"$FM_TMP/clip_queue.lock"
flock -n 200 || exit 0

LOG="$FM_LOGS/fm-extract-clips.log"
exec >> "$LOG" 2>&1

sq() { sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "$1"; }

PRE_ROLL=$(sqlite3 -cmd ".timeout 5000" "$FM_DB" "SELECT value FROM settings WHERE key='cue_pre_roll';")
POST_ROLL=$(sqlite3 -cmd ".timeout 5000" "$FM_DB" "SELECT value FROM settings WHERE key='cue_post_roll';")
[[ -z "$PRE_ROLL" ]] && PRE_ROLL=30
[[ -z "$POST_ROLL" ]] && POST_ROLL=90
CLIP_DURATION=$(( PRE_ROLL + POST_ROLL ))

# markers pending, creati almeno POST_ROLL+10s fa: prima di quel momento la coda
# del file (i secondi dopo il click) potrebbe non essere ancora scritta su disco.
WAIT_SEC=$(( POST_ROLL + 10 ))
PENDING=$(sq "SELECT m.id, m.recording_id, m.elapsed_seconds, r.media_type, r.output_dir, r.filename_base, r.source_id, r.segment_duration, COALESCE(strftime('%s', r.start_time), ''), COALESCE(r.clips_dir, '')
    FROM markers m
    JOIN recordings r ON r.id = m.recording_id
    WHERE m.type='cue' AND m.clip_status='pending'
      AND (strftime('%s','now') - strftime('%s', m.created_at)) >= $WAIT_SEC;")

[[ -z "$PENDING" ]] && exit 0

# Posizione del click DENTRO il contenuto registrato (2026-07-28).
# elapsed_seconds è tempo di parete dall'inizio dello slot, e coincide con la
# posizione nel file solo se la registrazione non ha buchi. Se lo stream è partito
# in ritardo, o è caduto e il supervisore l'ha ripresa, ogni buco sposta indietro
# tutto il contenuto successivo e i cue finivano fuori bersaglio del tempo perso.
# Qui si ricostruisce la linea del tempo reale dai file: ciascuno copre
# [mtime - durata, mtime], e l'offset nel contenuto è la somma delle durate
# precedenti. Tutto in epoch, quindi nessuna questione di fuso orario.
content_position() {
    local click_ts="$1" dir="$2" base="$3" ext="$4"
    local f dur mtime fstart cum=0 pos=""
    for f in $(ls "$dir/$base.$ext" "$dir/${base}"_[0-9][0-9][0-9]."$ext" 2>/dev/null | sort); do
        dur=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f" 2>/dev/null)
        dur=${dur%.*}
        [[ "$dur" =~ ^[0-9]+$ ]] || continue
        mtime=$(stat -c %Y "$f" 2>/dev/null) || continue
        fstart=$(( mtime - dur ))
        if [[ $click_ts -lt $fstart ]]; then
            pos=$cum          # il click cade in un buco: si riparte da questo file
            break
        fi
        if [[ $click_ts -le $mtime ]]; then
            pos=$(( cum + click_ts - fstart ))
            break
        fi
        cum=$(( cum + dur ))
    done
    [[ -z "$pos" ]] && pos=$cum   # click oltre l'ultimo file registrato
    echo "$pos"
}

while IFS='|' read -r MID RECORDING_ID ELAPSED MEDIA_TYPE OUTPUT_DIR FBASE SOURCE_ID SEGDUR REC_START_TS REC_CLIPS_DIR; do
    [[ -z "$MID" ]] && continue
    echo "--- $(date '+%F %T') marker=$MID recording=$RECORDING_ID media=$MEDIA_TYPE elapsed=$ELAPSED ---"

    EXT="mp3"
    [[ "$MEDIA_TYPE" == "video" ]] && EXT="mp4"

    # Sotto i 3s di scarto si resta sul valore storico: è il caso normale (nessun
    # buco), e non ha senso far dipendere il taglio da una misura di mtime.
    POS=$ELAPSED
    if [[ "$REC_START_TS" =~ ^[0-9]+$ ]]; then
        MAPPED=$(content_position $(( REC_START_TS + ELAPSED )) "$OUTPUT_DIR" "$FBASE" "$EXT")
        GAP=$(( ELAPSED - MAPPED ))
        if [[ ${GAP#-} -gt 3 ]]; then
            POS=$MAPPED
            echo "posizione corretta: ${ELAPSED}s di parete -> ${POS}s di contenuto (${GAP}s di buchi prima del click)"
        fi
    else
        echo "ATTENZIONE: start_time non leggibile, uso elapsed_seconds senza correzione"
    fi

    START_SEC=$(( POS - PRE_ROLL ))
    [[ $START_SEC -lt 0 ]] && START_SEC=0
    END_SEC=$(( START_SEC + CLIP_DURATION ))

    # La cartella dei cue segue la registrazione (può stare su un volume esterno).
    # Le righe create prima della v2.5.0 non hanno clips_dir: per quelle vale il
    # path storico FM_CLIPS/{source_id} (vincolo 21).
    CLIP_DIR="${REC_CLIPS_DIR:-}"
    [[ -z "$CLIP_DIR" ]] && CLIP_DIR="$FM_CLIPS/$SOURCE_ID"
    mkdir -p "$CLIP_DIR"

    CLIP_OUT="$CLIP_DIR/${FBASE}_m${MID}.${EXT}"

    OK=0

    # Multi-file o file singolo si decide da COSA C'È SU DISCO, non da
    # segment_duration: dal 2026-07-27 anche una registrazione non segmentata può
    # avere più file _NNN, se il supervisore di record.sh l'ha ripresa dopo una
    # caduta dello stream. Il ramo concat qui sotto misura comunque le durate reali
    # con ffprobe, quindi regge segmenti di lunghezza disomogenea.
    HAS_PARTS=0
    ls "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT" >/dev/null 2>&1 && HAS_PARTS=1

    if [[ $HAS_PARTS -eq 1 ]]; then
        # Registrazione segmentata: costruisci concat list dei segmenti coinvolti
        LIST_FILE=$(mktemp "$FM_TMP/concat_XXXXXX.txt")
        REL_START=""
        CUM=0
        FIRST_OFFSET=""
        for SEG in $(ls "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT" 2>/dev/null | sort); do
            DUR=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$SEG" 2>/dev/null)
            DUR=${DUR%.*}
            [[ -z "$DUR" ]] && DUR=0
            SEG_START=$CUM
            SEG_END=$(( CUM + DUR ))
            if [[ $SEG_END -gt $START_SEC && $SEG_START -lt $END_SEC ]]; then
                echo "file '$SEG'" >> "$LIST_FILE"
                [[ -z "$FIRST_OFFSET" ]] && FIRST_OFFSET=$SEG_START
            fi
            CUM=$SEG_END
        done

        if [[ -s "$LIST_FILE" && -n "$FIRST_OFFSET" ]]; then
            REL_START=$(( START_SEC - FIRST_OFFSET ))
            [[ $REL_START -lt 0 ]] && REL_START=0

            if [[ "$MEDIA_TYPE" == "audio" ]]; then
                ffmpeg -nostdin -y -f concat -safe 0 -i "$LIST_FILE" \
                    -af "atrim=start=${REL_START}:end=$((REL_START + CLIP_DURATION)),asetpts=PTS-STARTPTS" \
                    -c:a libmp3lame -q:a 2 "$CLIP_OUT" && OK=1
            else
                ffmpeg -nostdin -y -f concat -safe 0 -accurate_seek -ss "$REL_START" -i "$LIST_FILE" \
                    -t "$CLIP_DURATION" -c copy "$CLIP_OUT" && OK=1
            fi
        fi
        rm -f "$LIST_FILE"
    else
        SOURCE_FILE="$OUTPUT_DIR/${FBASE}.${EXT}"
        if [[ -f "$SOURCE_FILE" ]]; then
            if [[ "$MEDIA_TYPE" == "audio" ]]; then
                ffmpeg -nostdin -y -i "$SOURCE_FILE" \
                    -af "atrim=start=${START_SEC}:end=${END_SEC},asetpts=PTS-STARTPTS" \
                    -c:a libmp3lame -q:a 2 "$CLIP_OUT" && OK=1
            else
                ffmpeg -nostdin -y -accurate_seek -ss "$START_SEC" -i "$SOURCE_FILE" \
                    -t "$CLIP_DURATION" -c copy "$CLIP_OUT" && OK=1
            fi
        fi
    fi

    if [[ $OK -eq 1 && -s "$CLIP_OUT" ]]; then
        sqlite3 -cmd ".timeout 5000" "$FM_DB" "UPDATE markers SET clip_status='ready', clip_filename='$(basename "$CLIP_OUT")' WHERE id=$MID;"
        echo "OK marker=$MID -> $(basename "$CLIP_OUT")"
    else
        sqlite3 -cmd ".timeout 5000" "$FM_DB" "UPDATE markers SET clip_status='failed' WHERE id=$MID;"
        echo "FALLITA marker=$MID"
    fi

    # pulizia best-effort della coda informativa
    QUEUE_FILE="$FM_TMP/clip_queue.json"
    if [[ -f "$QUEUE_FILE" ]]; then
        python3 - "$QUEUE_FILE" "$MID" <<'PYEOF' 2>/dev/null || true
import json, sys
path, mid = sys.argv[1], int(sys.argv[2])
try:
    with open(path) as f:
        data = json.load(f)
    data = [e for e in data if e.get('marker_id') != mid]
    with open(path, 'w') as f:
        json.dump(data, f)
except Exception:
    pass
PYEOF
    fi

done <<< "$PENDING"
