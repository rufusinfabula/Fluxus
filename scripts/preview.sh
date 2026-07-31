#!/usr/bin/env bash
#
# preview.sh — relay HLS on-demand per l'anteprima live di una sorgente.
#
# Perché non si usa il muxer HLS di MediaMTX (com'era fino alla 2.4.0):
# aborta all'istante sugli stream con timestamp non validi
#   "[HLS] [muxer X] destroyed: muxer error: unable to extract DTS: DTS is
#    greater than PTS"
# che è esattamente il caso della sorgente rtmp in produzione. La path
# MediaMTX diventava ready:true (quindi preview_start.php rispondeva ok)
# ma index.m3u8 restava 404/500 e il player non partiva mai.
# ffmpeg normalizza quei timestamp senza fare una piega.
#
# Il video viene copiato senza ricodifica (costo CPU ~0); l'audio viene
# sempre portato ad AAC, che è l'unico codec audio che ogni browser sa
# riprodurre dentro HLS, e costa pochissimo.
#
# Uso: preview.sh <source_id> <media_type> <type> <url> <device> <resolution> <fps> <ttl_sec>

set -uo pipefail

SOURCE_ID="${1:?source_id mancante}"
MEDIA_TYPE="${2:-audio}"
STYPE="${3:-http}"
URL="${4:-}"
DEVICE="${5:-}"
RESOLUTION="${6:-1280x720}"
FPS="${7:-25}"
TTL="${8:-900}"

FM_TMP="${FM_TMP:-/var/lib/fluxus-media/tmp}"
FM_LOGS="${FM_LOGS:-/var/lib/fluxus-media/logs}"

DIR="$FM_TMP/preview/$SOURCE_ID"
LOG="$FM_LOGS/fm-preview-${SOURCE_ID}.log"

mkdir -p "$DIR" "$FM_LOGS"
# Ripartenza pulita: i segmenti della sessione precedente farebbero credere
# al player che il flusso sia già pronto prima che ffmpeg si sia collegato.
rm -f "$DIR"/*.ts "$DIR"/*.m3u8 "$DIR"/pid "$DIR"/error 2>/dev/null

{
    echo "=== preview start $(date '+%F %T') src=$SOURCE_ID media=$MEDIA_TYPE type=$STYPE ttl=${TTL}s ==="
} >>"$LOG"

# Percorso MediaMTX realmente alimentato dall'encoder esterno. L'URL mostrato
# in UI è rtmp://<ip>:1935/{source_id}, ma diversi encoder (Wirecast fra questi)
# hanno due campi obbligatori, "indirizzo" e "stream", e li concatenano sempre:
# il percorso che arriva a MediaMTX diventa "{id}/{stream}". Si chiede quindi a
# MediaMTX quale percorso sta ricevendo, accettando sia "{id}" sia "{id}/...".
# ⚠️ Il prefisso include lo slash: senza, la sorgente 21 prenderebbe anche 210.
push_input_url() {
    local name
    name=$(curl -fsS --max-time 3 'http://127.0.0.1:9997/v3/paths/list?itemsPerPage=1000' 2>/dev/null \
        | jq -r --arg id "$SOURCE_ID" \
            '[.items[]? | select(.ready == true) | .name
              | select(. == $id or startswith($id + "/"))] | sort | first // empty' 2>/dev/null)
    printf 'rtmp://127.0.0.1:1935/%s' "${name:-$SOURCE_ID}"
}

# Ingresso. -reconnect* sono AVOption del solo http/https: su rtmp/rtsp/srt
# ffmpeg le accetta sulla riga di comando e poi aborta con "Option reconnect
# not found" (vincoli 1 e 7).
case "$STYPE" in
    http)
        IN=(-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 10 -i "$URL")
        ;;
    rtmp-push)
        IN=(-i "$(push_input_url)")
        ;;
    v4l2)
        IN=(-f v4l2 -framerate "$FPS" -video_size "$RESOLUTION" -i "$DEVICE")
        ;;
    *)
        IN=(-i "$URL")
        ;;
esac

# Uscita.
if [[ "$MEDIA_TYPE" == "video" ]]; then
    if [[ "$STYPE" == "v4l2" ]]; then
        # Un device raw non è copiabile. Preset ultrafast + crf alto: è
        # un'anteprima, non un master. Niente audio: il device video non ne ha
        # e agganciare alsa qui aggiungerebbe solo un motivo di fallimento.
        ENC=(-c:v libx264 -preset ultrafast -tune zerolatency -crf 30 -g 50 -an)
    else
        # Video copiato così com'è, audio riportato ad AAC per il browser.
        ENC=(-c:v copy -c:a aac -b:a 96k)
    fi
else
    ENC=(-vn -c:a aac -b:a 128k)
fi

HLS=(
    -f hls
    -hls_time 2
    -hls_list_size 5
    -hls_flags delete_segments+omit_endlist+independent_segments
    -hls_segment_type mpegts
    -hls_allow_cache 0
    -hls_segment_filename "$DIR/seg%05d.ts"
)

echo $$ >"$DIR/pid"

# exec: il pid scritto sopra resta valido e diventa quello di ffmpeg, così
# preview_stop.php uccide il processo giusto con un solo kill.
# -t $TTL è la rete di sicurezza: se il browser sparisce senza chiudere la
# preview (tab killata, crash, rete giù) il relay muore comunque da solo.
exec ffmpeg -nostdin -y -loglevel warning \
    "${IN[@]}" \
    "${ENC[@]}" \
    -t "$TTL" \
    "${HLS[@]}" \
    "$DIR/index.m3u8" >>"$LOG" 2>&1
