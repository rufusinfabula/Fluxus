#!/bin/bash
# Fluxus-Media — record.sh <recording_id> <source_id> <duration_sec> [segment_sec]
# NON MODIFICARE dopo la creazione iniziale.
# Eccezione 2026-07-26: fix -reconnect su rtmp/rtsp/srt (vedi docs/NOTE-TECNICHE.md, vincolo 1).
# Eccezione 2026-07-26 (2): -movflags frag_keyframe+empty_moov+default_base_moof sui
# 3 output mp4 video, per rendere i cue video funzionanti come quelli audio
# (vedi docs/NOTE-TECNICHE.md, vincolo 12).
# Eccezione 2026-07-27 (3): rimozione del segmento finale vuoto (0s) che ffmpeg crea
# quando DURATION è un multiplo esatto di SEGMENT (vedi docs/NOTE-TECNICHE.md, vincolo 13).
# Eccezione 2026-07-27 (4): profili di qualità video per sorgente (sources.video_quality)
# e onore di extra_opts, per ridurre il peso delle registrazioni video
# (vedi docs/NOTE-TECNICHE.md, vincolo 14).
# Eccezione 2026-07-27 (5): loop supervisore che riprende la registrazione se lo stream
# cade prima della fine dello slot, busy_timeout su tutte le query e calcolo della
# durata blindato (vedi docs/NOTE-TECNICHE.md, vincoli 15 e 16).
# Eccezione 2026-07-27 (6): watchdog di stallo (uno stream che si congela senza chiudere
# la connessione lasciava ffmpeg appeso all'infinito) e -movflags propagati al muxer mp4
# figlio in modalità segmentata (vedi docs/NOTE-TECNICHE.md, vincoli 18 e 19).
# Eccezione 2026-07-28 (7): duration_seconds è la durata del CONTENUTO registrato, non
# il tempo trascorso: con uno stream partito in ritardo o caduto a metà i due valori
# divergono e in UI compariva la durata dello slot (vedi docs/NOTE-TECNICHE.md, vincolo 20).
# Eccezione 2026-07-29 (8): profilo 'hd' e forbice di qualità allargata (media crf 25->28,
# bassa crf 28->32), per differenziare davvero i profili fra loro
# (vedi docs/NOTE-TECNICHE.md, vincolo 14).

FM_BASE="/var/lib/fluxus-media"
FM_DB="$FM_BASE/db/fluxus_media.db"
FM_LOGS="$FM_BASE/logs"
FM_TMP="$FM_BASE/tmp"

RECORDING_ID="$1"
SOURCE_ID="$2"
DURATION="$3"
SEGMENT="${4:-0}"

mkdir -p "$FM_LOGS"
LOG="$FM_LOGS/fm-record-${RECORDING_ID}.log"
exec >> "$LOG" 2>&1

echo "=== record.sh start $(date '+%F %T') rec=$RECORDING_ID src=$SOURCE_ID dur=$DURATION seg=$SEGMENT ==="

sq() { sqlite3 -cmd ".timeout 5000" "$FM_DB" "$1"; }

IFS='|' read -r MEDIA_TYPE URL TYPE DEVICE AUDIO_QUALITY RESOLUTION FPS VIDEO_QUALITY EXTRA_OPTS <<EOF
$(sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "SELECT media_type,COALESCE(url,''),type,COALESCE(device,''),COALESCE(audio_quality,'2'),COALESCE(resolution,'1920x1080'),COALESCE(fps,25),COALESCE(video_quality,'copy'),COALESCE(extra_opts,'') FROM sources WHERE id=$SOURCE_ID;")
EOF

# TRIGGER_TYPE decide cosa fare quando lo stream si congela (vedi watchdog più sotto):
# 'manual' → la registrazione si chiude, 'scheduled' → si riprende fino all'ora prevista.
IFS='|' read -r FBASE OUTPUT_DIR TRIGGER_TYPE <<EOF
$(sqlite3 -cmd ".timeout 5000" -separator '|' "$FM_DB" "SELECT filename_base,output_dir,COALESCE(trigger_type,'scheduled') FROM recordings WHERE id=$RECORDING_ID;")
EOF

if [[ -z "$FBASE" || -z "$OUTPUT_DIR" ]]; then
    echo "ERRORE: registrazione $RECORDING_ID non trovata nel DB"
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

# NB: /etc/fluxus-media.env (FM_HW_ENCODER / FM_HW_ENCODER_OPTS) non viene più letto qui:
# l'encoder è determinato dal profilo qualità della sorgente (vedi sotto). Il Pi 5 non ha
# encoder H.264 hardware, quindi quel meccanismo puntava comunque a un device inesistente.

# Profili di qualità video per sorgente (sources.video_quality, 2026-07-27;
# forbice allargata + profilo 'hd' il 2026-07-29).
# 'copy' = comportamento storico: stream copy puro, il peso lo decide l'encoder a monte.
# Gli altri profili ricodificano in H.264 software (il Pi 5 NON ha encoder H.264
# hardware: h264_v4l2m2m esiste come wrapper ffmpeg ma non ha device dietro).
# La risoluzione e gli fps NON vengono mai toccati: restano quelli dello stream in ingresso.
# TRANSCODE=1 solo sui profili che ricodificano davvero: qualunque valore non
# riconosciuto (incluso vuoto/NULL) ricade su copy, cioè sul comportamento storico.
#
# Il preset resta 'veryfast' su TUTTI i profili: è l'unico che regge il tempo reale
# su questo host. Misurato su 1080p30 reale, senza throttling (2026-07-29):
#   veryfast 1,78x — faster 1,21x — fast 0,96x — medium 0,77x   (a crf 21)
# Sotto 1,0x ffmpeg non sta dietro alla diretta e perde frame.
#
# NB: a qualità PARI il preset lento è più efficiente, come da teoria — fast/crf21
# fa SSIM 0,99427 con 3,07 Mbps contro veryfast/crf17 a 0,99444 con 4,24 Mbps, cioè
# il 28% di byte in meno. Non lo usiamo solo perché a 0,96x non sta in tempo reale.
# Se un giorno si libera CPU su questo host (vedi la nota sull'indexer del progetto
# 'recorder' in docs/NOTE-TECNICHE.md), 'fast' diventa la scelta migliore per alta/hd.
#
# libx265/HEVC è fuori discussione: 0,15-0,27x, e non si riproduce in modo
# affidabile nel browser (i player di recording.php e del modale cue).
#
# Da NON adottare: la config "high quality" del whitepaper RPi (superfast + bf 1 +
# partitions=i8x8,i4x4 + me=dia:subme=0). È tarata per ABR a bitrate fisso e per
# lasciare CPU alla pipeline camera; con CRF la ricerca di movimento al minimo
# costa carissimo in byte. Misurato: crf 21 con quei parametri = 6,33 Mbps contro
# i nostri 2,63 Mbps, per un SSIM quasi identico (0,99313 vs 0,99230).
TRANSCODE=0
case "$VIDEO_QUALITY" in
    hd)    VENC=(-vcodec libx264 -preset veryfast -crf 17 -pix_fmt yuv420p -acodec aac -b:a 128k); TRANSCODE=1 ;;
    alta)  VENC=(-vcodec libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -acodec aac -b:a 128k); TRANSCODE=1 ;;
    media) VENC=(-vcodec libx264 -preset veryfast -crf 28 -pix_fmt yuv420p -acodec aac -b:a 128k); TRANSCODE=1 ;;
    bassa) VENC=(-vcodec libx264 -preset veryfast -crf 32 -pix_fmt yuv420p -acodec aac -b:a 96k);  TRANSCODE=1 ;;
    *)     VENC=(-vcodec copy -acodec copy) ;;
esac

# GOP forzato a 2s indipendente dagli fps della sorgente: mantiene la precisione del
# taglio dei cue (extract_clips.sh usa -accurate_seek + -c copy, che parte comunque dal
# keyframe) e fa cadere i confini dei segmenti su keyframe. Solo se si ricodifica:
# con -vcodec copy ffmpeg ignorerebbe l'opzione con un warning.
if [[ "$TRANSCODE" -eq 1 ]]; then
    VENC+=(-force_key_frames "expr:gte(t,n_forced*2)")
fi

# Opzioni extra ffmpeg definite sulla sorgente. Word-splitting voluto (sono più flag).
AEXTRA=()
if [[ -n "$EXTRA_OPTS" ]]; then
    VENC+=($EXTRA_OPTS)
    AEXTRA=($EXTRA_OPTS)
fi

EXT="mp3"
[[ "$MEDIA_TYPE" != "audio" ]] && EXT="mp4"

# ── Supervisore: funzioni di supporto (2026-07-27) ────────────────────────────
# Se lo stream cade prima della fine dello slot, ffmpeg esce e la registrazione
# finiva presto marcata 'completed', indistinguibile da uno stop volontario.
# Ora si riprende automaticamente; i file dei tentativi successivi proseguono
# la numerazione _NNN, che extract_clips.sh e recording.php sanno già leggere.

# Indice del prossimo file _NNN da scrivere (0 se non ce n'è ancora nessuno).
next_segment_index() {
    local last idx
    last=$(ls -1 "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT" 2>/dev/null | sort | tail -n 1)
    [[ -z "$last" ]] && { echo 0; return; }
    idx=${last##*_}; idx=${idx%.*}
    echo $(( 10#$idx + 1 ))
}

# Il primo tentativo di una registrazione non segmentata ha scritto FBASE.ext:
# rinominalo in _000 così i tentativi successivi ne sono la continuazione e
# cue/UI vedono una registrazione multi-file coerente. Sotto il lock dei cue,
# perché extract_clips.sh potrebbe star leggendo proprio quel file.
promote_single_to_segments() {
    [[ -f "$OUTPUT_DIR/${FBASE}.${EXT}" ]] || return 0
    (
        flock -w 20 202
        mv -f "$OUTPUT_DIR/${FBASE}.${EXT}" "$OUTPUT_DIR/${FBASE}_000.${EXT}"
    ) 202>"$FM_TMP/clip_queue.lock"
    echo "Ripresa: ${FBASE}.${EXT} rinominato in ${FBASE}_000.${EXT}"
}

# Vincolo 13: il muxer segment apre un ultimo file vuoto quando -t coincide con
# un confine di segmento. Ora va fatto dopo OGNI tentativo, non solo alla fine.
drop_empty_last_segment() {
    [[ "$SEGMENT" -gt 0 ]] || return 0
    local last dur
    last=$(ls -1 "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT" 2>/dev/null | sort | tail -n 1)
    [[ -n "$last" ]] || return 0
    dur=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$last" 2>/dev/null)
    dur=${dur%.*}
    if [[ -z "$dur" || "$dur" -lt 1 ]]; then
        echo "Segmento finale vuoto (durata=${dur:-n/a}), rimosso: $last"
        rm -f "$last"
    fi
}

# Argomenti di output del tentativo corrente. Il primo tentativo di una
# registrazione non segmentata scrive FBASE.ext esattamente come prima.
add_output_args() {
    local remain="$1"
    CMD+=(-t "$remain")
    if [[ "$SEGMENT" -gt 0 ]]; then
        CMD+=(-f segment -segment_time "$SEGMENT" -segment_start_number "$(next_segment_index)")
        # In modalità segmentata l'output è il muxer 'segment', non mp4: i -movflags
        # passati all'output finivano a lui, che NON li propaga al muxer mp4 figlio.
        # Risultato: ogni segmento restava senza moov finché non veniva chiuso, e un
        # segmento interrotto (stream congelato, kill, crash) era irrecuperabile —
        # è quanto successo alla registrazione 47 il 2026-07-27 (vincolo 19).
        # La forma corretta è -segment_format_options, verificata sul campo.
        [[ "$MEDIA_TYPE" != "audio" ]] && CMD+=(-segment_format mp4 \
            -segment_format_options movflags=frag_keyframe+empty_moov+default_base_moof \
            -reset_timestamps 1)
        CMD+=("$OUTPUT_DIR/${FBASE}_%03d.${EXT}")
    elif [[ "$ATTEMPT" -eq 0 ]]; then
        [[ "$MEDIA_TYPE" != "audio" ]] && CMD+=(-movflags frag_keyframe+empty_moov+default_base_moof)
        CMD+=("$OUTPUT_DIR/${FBASE}.${EXT}")
    else
        [[ "$MEDIA_TYPE" != "audio" ]] && CMD+=(-movflags frag_keyframe+empty_moov+default_base_moof)
        CMD+=("$(printf '%s/%s_%03d.%s' "$OUTPUT_DIR" "$FBASE" "$(next_segment_index)" "$EXT")")
    fi
}

# Percorso MediaMTX realmente alimentato da una sorgente rtmp-push.
# L'URL mostrato in UI è rtmp://<ip>:1935/{source_id}, ma diversi encoder
# (Wirecast fra questi) hanno due campi obbligatori, "indirizzo" e "stream", e
# li concatenano sempre: il percorso che arriva a MediaMTX è "{id}/{stream}".
# Si chiede quindi a MediaMTX quale percorso sta ricevendo, accettando sia
# "{id}" sia "{id}/<qualunque cosa>"; il nome esatto vince (l'ordinamento mette
# "21" prima di "21/qualcosa"). Se non pusha nessuno si torna al nome canonico:
# ffmpeg fallirà e sarà il ciclo supervisore a riprovare più tardi, quando
# l'encoder si sarà collegato. La risoluzione è dentro build_cmd, quindi viene
# rifatta a ogni tentativo.
# ⚠️ Il prefisso include lo slash: senza, la sorgente 21 prenderebbe anche 210.
push_input_url() {
    local name
    name=$(curl -fsS --max-time 3 'http://127.0.0.1:9997/v3/paths/list?itemsPerPage=1000' 2>/dev/null \
        | jq -r --arg id "$SOURCE_ID" \
            '[.items[]? | select(.ready == true) | .name
              | select(. == $id or startswith($id + "/"))] | sort | first // empty' 2>/dev/null)
    if [[ -n "$name" && "$name" != "$SOURCE_ID" ]]; then
        echo "Sorgente push su percorso non canonico: $name" >&2
    fi
    printf 'rtmp://127.0.0.1:1935/%s' "${name:-$SOURCE_ID}"
}

# Costruisce CMD per il tentativo corrente, con -t pari al tempo che resta.
build_cmd() {
    local remain="$1"
    CMD=(ffmpeg -nostdin -y)

if [[ "$MEDIA_TYPE" == "audio" ]]; then
    # -reconnect* sono AVOption del protocollo http/https: rtmp/rtsp non le riconoscono
    # e ffmpeg abortisce subito con "Option reconnect not found" senza scrivere output.
    if [[ "$TYPE" == "http" ]]; then
        CMD+=(-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 30 -i "$URL" -vn -c:a libmp3lame -q:a "$AUDIO_QUALITY")
    else
        CMD+=(-i "$URL" -vn -c:a libmp3lame -q:a "$AUDIO_QUALITY")
    fi
    [[ ${#AEXTRA[@]} -gt 0 ]] && CMD+=("${AEXTRA[@]}")
    add_output_args "$remain"
else
    case "$TYPE" in
        v4l2)
            # Un device raw non è copiabile: va sempre codificato. Se la sorgente è su
            # 'copy' si ricade sul profilo 'alta' (equivalente al vecchio comportamento
            # FM_HW_ENCODER, che su Pi 5 puntava a un encoder hardware inesistente).
            if [[ "$TRANSCODE" -eq 0 ]]; then
                VENC=(-vcodec libx264 -preset veryfast -crf 21 -pix_fmt yuv420p -acodec aac -b:a 128k \
                      -force_key_frames "expr:gte(t,n_forced*2)")
                [[ -n "$EXTRA_OPTS" ]] && VENC+=($EXTRA_OPTS)
            fi
            # RESOLUTION e FPS qui sono parametri di CATTURA del device, non di ricodifica.
            CMD+=(-f v4l2 -framerate "$FPS" -video_size "$RESOLUTION" -i "$DEVICE" \
                  -f alsa -i default \
                  "${VENC[@]}")
            # -movflags frag_keyframe+empty_moov+default_base_moof: scrive il moov atom
            # subito (vuoto) e poi a frammenti a ogni keyframe, cosi' il file .mp4 e'
            # leggibile anche mentre la registrazione e' ancora in corso (necessario per
            # i cue video estratti da extract_clips.sh su file non ancora finalizzati;
            # altrimenti ffmpeg fallisce con "moov atom not found" — bug 2026-07-26).
            add_output_args "$remain"
            ;;
        rtmp-push)
            # rtmp non ha AVOption -reconnect* (solo http/https): niente flag qui.
            # Il percorso si chiede a MediaMTX (push_input_url): non tutti gli
            # encoder pubblicano esattamente su "{source_id}".
            CMD+=(-i "$(push_input_url)" "${VENC[@]}")
            # -movflags frag_keyframe+...: vedi commento nel ramo v4l2 sopra.
            add_output_args "$remain"
            ;;
        *)
            # -reconnect* sono AVOption del protocollo http/https: rtmp/rtsp/srt non le
            # riconoscono e ffmpeg abortisce subito con "Option reconnect not found"
            # senza scrivere output (bug osservato su sorgenti rtmp: 2026-07-26).
            if [[ "$TYPE" == "http" ]]; then
                CMD+=(-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 30 -i "$URL" "${VENC[@]}")
            else
                CMD+=(-i "$URL" "${VENC[@]}")
            fi
            # -movflags frag_keyframe+...: vedi commento nel ramo v4l2 sopra.
            add_output_args "$remain"
            ;;
    esac
fi
}

# ── Watchdog di stallo (2026-07-27) ───────────────────────────────────────────
# Il ciclo supervisore da solo non basta: copre il caso in cui ffmpeg ESCE, non
# quello in cui lo stream si congela senza chiudere la connessione TCP. In quel
# caso ffmpeg resta a leggere zero byte per sempre e -t non scatta mai, perché
# conta i timestamp del media, non l'orologio. La registrazione 47 del 2026-07-27
# è rimasta appesa 1h21m oltre la fine dello slot per questo motivo, e nemmeno lo
# stop dall'UI la chiudeva: ffmpeg bloccato in read() cattura il SIGTERM ma non
# arriva mai a processarlo (vincolo 18).

STALL_SEC=60       # secondi senza un byte scritto oltre i quali lo stream è fermo
POLL_SEC=2         # frequenza di campionamento del watchdog. Tenuto basso perché è
                   # anche la latenza con cui si reagisce allo stop dall'UI: sommata
                   # alla grazia del SIGTERM deve restare sotto l'attesa di
                   # stop_recording.sh, altrimenti scatta il suo fallback e si torna
                   # ad avere due scrittori sulla stessa riga (vincolo 15).
DEADLINE_GRACE=30  # margine oltre la deadline prima di forzare la chiusura: ffmpeg
                   # sta legittimamente finalizzando il file quando -t scade

# Secondi di contenuto effettivamente registrati, sommando le durate reali dei file.
# Diverso dal tempo trascorso ogni volta che c'è un buco: stream partito in ritardo,
# caduta a metà slot, blocco chiuso dal watchdog. È questo il numero che l'utente si
# aspetta di leggere in elenco, non la lunghezza dello slot.
content_duration() {
    local total=0 f d
    shopt -s nullglob
    for f in "$OUTPUT_DIR/${FBASE}.${EXT}" "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT"; do
        d=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$f" 2>/dev/null)
        d=${d%.*}
        [[ "$d" =~ ^[0-9]+$ ]] && total=$(( total + d ))
    done
    shopt -u nullglob
    echo "$total"
}

# Byte totali prodotti finora dalla registrazione (tutti i tentativi).
output_size() {
    local total=0 f sz
    shopt -s nullglob
    for f in "$OUTPUT_DIR/${FBASE}.${EXT}" "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT"; do
        sz=$(stat -c %s "$f" 2>/dev/null) || sz=0
        total=$(( total + sz ))
    done
    shopt -u nullglob
    echo "$total"
}

# SIGTERM e, se non basta, SIGKILL. Su uno stream congelato solo il secondo funziona.
kill_ffmpeg() {
    KILLED_BY_WATCHDOG=1
    kill "$FFMPEG_PID" 2>/dev/null
    local i
    for i in $(seq 1 6); do
        kill -0 "$FFMPEG_PID" 2>/dev/null || return 0
        sleep 0.5
    done
    echo "ffmpeg non risponde a SIGTERM (bloccato in read): SIGKILL."
    kill -9 "$FFMPEG_PID" 2>/dev/null
}

# Sostituisce il vecchio 'wait $FFMPEG_PID': stessa semantica (a ritorno, LAST_RC
# contiene il codice di uscita di ffmpeg) più la sorveglianza dello stallo.
wait_with_watchdog() {
    local last_size cur_size last_change now
    STALL_HIT=0
    KILLED_BY_WATCHDOG=0
    last_size=$(output_size)
    last_change=$(date +%s)

    while kill -0 "$FFMPEG_PID" 2>/dev/null; do
        sleep "$POLL_SEC"
        now=$(date +%s)
        cur_size=$(output_size)
        if [[ "$cur_size" != "$last_size" ]]; then
            last_size=$cur_size
            last_change=$now
        fi

        # Stop voluto: stop_recording.sh ha già mandato il SIGTERM, ma se ffmpeg è
        # bloccato non lo processa. Qui lo chiudiamo per davvero.
        if [[ -f "$STOP_FLAG" ]]; then
            echo "Stop richiesto: chiusura di ffmpeg."
            kill_ffmpeg
            break
        fi

        if [[ $(( now - last_change )) -ge $STALL_SEC ]]; then
            STALL_HIT=1
            echo "STALLO: nessun dato scritto da $(( now - last_change ))s (trigger=$TRIGGER_TYPE)."
            kill_ffmpeg
            break
        fi

        # Rete di sicurezza: se ffmpeg è ancora vivo ben oltre la fine dello slot,
        # -t non è scattato (stream fermo o timestamp fermi). Lo slot finisce qui.
        if [[ $now -ge $(( DEADLINE + DEADLINE_GRACE )) ]]; then
            echo "Deadline dello slot superata con ffmpeg ancora vivo: chiusura."
            kill_ffmpeg
            break
        fi
    done

    wait "$FFMPEG_PID"
    LAST_RC=$?
}

# ── Ciclo supervisore ─────────────────────────────────────────────────────────
# Resta in piedi finché lo slot non è esaurito, rilanciando ffmpeg se lo stream
# cade. Per rtmp/rtsp/srt non esiste alcuna riconnessione lato ffmpeg
# (-reconnect* sono AVOption del solo http/https, vincolo 1): senza questo ciclo
# una caduta a metà slot chiudeva la registrazione in anticipo, marcandola
# 'completed' e quindi indistinguibile da uno stop volontario.

mkdir -p "$FM_TMP"
STOP_FLAG="$FM_TMP/stop-${RECORDING_ID}"
rm -f "$STOP_FLAG"

DEADLINE=$(( $(date +%s) + DURATION ))
RETRY_DELAY=10   # pausa fra un tentativo e il successivo
MIN_REMAIN=15    # sotto questa soglia non vale la pena ripartire
ATTEMPT=0
INTERRUPTIONS=0
STALLS=0
LAST_RC=0
STOPPED=0
STALLED_END=0
STALL_HIT=0
KILLED_BY_WATCHDOG=0

while :; do
    REMAIN=$(( DEADLINE - $(date +%s) ))
    if [[ $REMAIN -lt $MIN_REMAIN ]]; then
        echo "Slot esaurito (restano ${REMAIN}s), fine."
        break
    fi

    build_cmd "$REMAIN"
    echo "CMD[tentativo $ATTEMPT, restano ${REMAIN}s]: ${CMD[*]}"

    "${CMD[@]}" &
    FFMPEG_PID=$!

    if [[ $ATTEMPT -eq 0 ]]; then
        sq "UPDATE recordings SET status='recording', start_time=datetime('now'), ffmpeg_pid=$FFMPEG_PID WHERE id=$RECORDING_ID;"
    else
        # start_time NON va toccato sulle riprese: resta l'inizio reale dello slot.
        sq "UPDATE recordings SET ffmpeg_pid=$FFMPEG_PID WHERE id=$RECORDING_ID;"
    fi

    wait_with_watchdog

    drop_empty_last_segment

    # rc 255 = ffmpeg terminato da segnale: è uno stop voluto, non si riprova.
    # Se però il segnale l'ha mandato il watchdog, non è uno stop: si decide sotto.
    if [[ -f "$STOP_FLAG" || ( $LAST_RC -eq 255 && $KILLED_BY_WATCHDOG -eq 0 ) ]]; then
        STOPPED=1
        echo "Stop richiesto (rc=$LAST_RC): nessuna ripresa."
        break
    fi

    # Stream congelato. Una registrazione manuale finisce qui: non c'è un'ora di fine
    # da rispettare e non ha senso tenere occupata la sorgente. Una programmata invece
    # deve arrivare all'ora prevista, quindi si riprende come da una caduta qualunque.
    if [[ $STALL_HIT -eq 1 ]]; then
        STALLS=$(( STALLS + 1 ))
        if [[ "$TRIGGER_TYPE" == "manual" ]]; then
            STALLED_END=1
            echo "Registrazione manuale con stream fermo da ${STALL_SEC}s: chiusura."
            break
        fi
    fi

    REMAIN=$(( DEADLINE - $(date +%s) ))
    if [[ $REMAIN -lt $MIN_REMAIN ]]; then
        echo "ffmpeg uscito (rc=$LAST_RC) a slot ormai esaurito (${REMAIN}s), fine."
        break
    fi

    # Se siamo qui lo stream è caduto (o si è congelato) prima della fine dello
    # slot: si riprende.
    INTERRUPTIONS=$(( INTERRUPTIONS + 1 ))
    CAUSE="ffmpeg uscito con rc=$LAST_RC"
    [[ $STALL_HIT -eq 1 ]] && CAUSE="stream fermo da ${STALL_SEC}s, ffmpeg chiuso dal watchdog"
    echo "INTERRUZIONE $INTERRUPTIONS: $CAUSE, restano ${REMAIN}s. Riprovo fra ${RETRY_DELAY}s."

    if [[ $ATTEMPT -eq 0 && "$SEGMENT" -le 0 ]]; then
        promote_single_to_segments
    fi

    sleep "$RETRY_DELAY"
    if [[ -f "$STOP_FLAG" ]]; then
        STOPPED=1
        echo "Stop richiesto durante l'attesa."
        break
    fi
    ATTEMPT=$(( ATTEMPT + 1 ))
done

# Lo stato dipende da cosa è finito su disco, non dal codice di uscita: con le
# riprese l'rc dell'ultimo tentativo non descrive più l'esito complessivo.
shopt -s nullglob
PRODUCED=( "$OUTPUT_DIR/${FBASE}.${EXT}" "$OUTPUT_DIR/${FBASE}"_[0-9][0-9][0-9]."$EXT" )
shopt -u nullglob

STATUS="completed"
if [[ ${#PRODUCED[@]} -eq 0 ]]; then
    STATUS="failed"
    echo "Nessun file prodotto: registrazione fallita."
fi

START_TS=$(sq "SELECT strftime('%s', start_time) FROM recordings WHERE id=$RECORDING_ID;")
END_TS=$(date +%s)

# Blindatura del calcolo durata: se la SELECT fallisce (DB occupato) START_TS è
# vuoto e l'aritmetica bash lo tratta come 0, scrivendo l'epoch Unix come durata
# (1.785.066.816s = 56 anni, osservato sulle registrazioni 20 e 23). $SECONDS è
# il cronometro di bash dall'avvio dello script: non richiede il DB.
if [[ "$START_TS" =~ ^[0-9]+$ && "$START_TS" -gt 0 ]]; then
    DUR=$(( END_TS - START_TS ))
else
    echo "ATTENZIONE: start_time non leggibile dal DB, uso il cronometro dello script."
    DUR=$SECONDS
fi
if [[ $DUR -lt 0 || $DUR -gt $(( DURATION + 3600 )) ]]; then
    echo "ATTENZIONE: durata anomala (${DUR}s), sostituita con ${SECONDS}s."
    DUR=$SECONDS
fi
ELAPSED_DUR=$DUR

# La durata da mostrare è quella del contenuto: il tempo trascorso comprende anche i
# buchi (attesa di uno stream partito in ritardo, riprese dopo una caduta), che nel
# file non ci sono. Si ripiega sul tempo trascorso solo se ffprobe non sa rispondere.
CONTENT_DUR=$(content_duration)
if [[ "$CONTENT_DUR" -gt 0 ]]; then
    if [[ $(( ELAPSED_DUR - CONTENT_DUR )) -gt 5 ]]; then
        echo "Durata: ${CONTENT_DUR}s di contenuto su ${ELAPSED_DUR}s trascorsi (buchi: $(( ELAPSED_DUR - CONTENT_DUR ))s)."
    fi
    DUR=$CONTENT_DUR
else
    echo "ATTENZIONE: durata del contenuto non misurabile, uso il tempo trascorso (${DUR}s)."
fi

NOTES_SQL=""
NOTES=""
if [[ $STALLED_END -eq 1 ]]; then
    NOTES="Stream fermo da oltre ${STALL_SEC}s: registrazione manuale chiusa automaticamente."
fi
if [[ $INTERRUPTIONS -gt 0 ]]; then
    NOTES="${NOTES:+$NOTES }Stream interrotto ${INTERRUPTIONS} volta/e, registrazione ripresa automaticamente."
fi
# STALLS e INTERRUPTIONS non coincidono: un blocco rilevato a slot ormai esaurito, o
# che chiude una registrazione manuale, non produce alcuna ripresa. Il conteggio si
# aggiunge solo quando dice qualcosa in più di quanto già scritto sopra.
if [[ $STALLS -gt 1 || ( $STALLS -eq 1 && $STALLED_END -eq 0 ) ]]; then
    NOTES="${NOTES:+$NOTES }Blocchi dello stream rilevati: ${STALLS} (nessun dato per ${STALL_SEC}s)."
fi
[[ -n "$NOTES" ]] && NOTES_SQL=", notes='$NOTES'"

if ! sq "UPDATE recordings SET status='$STATUS', end_time=datetime('now'), duration_seconds=$DUR$NOTES_SQL WHERE id=$RECORDING_ID;"; then
    echo "ERRORE: aggiornamento finale del DB fallito."
fi

rm -f "$STOP_FLAG"

echo "=== record.sh end $(date '+%F %T') status=$STATUS rc=$LAST_RC dur=${DUR}s tentativi=$((ATTEMPT+1)) interruzioni=$INTERRUPTIONS stalli=$STALLS ==="
