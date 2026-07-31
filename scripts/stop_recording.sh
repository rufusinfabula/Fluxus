#!/bin/bash
# Fluxus-Media — stop_recording.sh <recording_id>
#
# 2026-07-27: eliminata la doppia scrittura con record.sh. Prima questo script e
# record.sh aggiornavano status/end_time della STESSA riga a pochi millisecondi
# di distanza: uno dei due prendeva SQLITE_BUSY e perdeva la scrittura in
# silenzio, lasciando duration_seconds a NULL (registrazioni 18, 20, 23, 24).
# Ora record.sh è l'unico a finalizzare; qui si scrive solo come rete di
# sicurezza, se record.sh non è più vivo per farlo (vedi docs/NOTE-TECNICHE.md, vincolo 15).

FM_BASE="/var/lib/fluxus-media"
FM_DB="$FM_BASE/db/fluxus_media.db"
FM_TMP="$FM_BASE/tmp"

RECORDING_ID="$1"
[[ -z "$RECORDING_ID" ]] && { echo "Uso: stop_recording.sh <recording_id>"; exit 1; }

sq() { sqlite3 -cmd ".timeout 5000" "$FM_DB" "$1"; }

mkdir -p "$FM_TMP"

# Sentinella letta dal ciclo supervisore di record.sh: distingue uno stop voluto
# da una caduta dello stream, così il supervisore non riprende la registrazione.
# Va creata PRIMA del kill, altrimenti record.sh potrebbe ripartire nel frattempo.
touch "$FM_TMP/stop-${RECORDING_ID}"

PID=$(sq "SELECT ffmpeg_pid FROM recordings WHERE id=$RECORDING_ID;")

if [[ -n "$PID" ]]; then
    kill "$PID" 2>/dev/null || true
fi

# Diamo a record.sh il tempo di chiudere il file e scrivere lui lo stato finale
# (status, end_time e soprattutto duration_seconds, che solo lui sa calcolare).
for _ in $(seq 1 24); do
    ST=$(sq "SELECT status FROM recordings WHERE id=$RECORDING_ID;")
    if [[ "$ST" != "recording" ]]; then
        rm -f "$FM_TMP/stop-${RECORDING_ID}"
        exit 0
    fi
    sleep 0.25
done

# record.sh non ha finalizzato entro ~6s: probabilmente non è più vivo (riavvio,
# processo orfano). Chiudiamo noi la riga per non lasciarla eternamente in
# 'recording'. La condizione status='recording' evita di sovrascrivere un esito
# che record.sh potrebbe aver appena scritto.
sq "UPDATE recordings SET status='completed', end_time=datetime('now'),
    duration_seconds=COALESCE(duration_seconds,
        CAST((julianday('now') - julianday(start_time)) * 86400 AS INTEGER))
    WHERE id=$RECORDING_ID AND status='recording';"

rm -f "$FM_TMP/stop-${RECORDING_ID}"
