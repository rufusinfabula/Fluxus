<?php
// Fluxus Remote — remote_sync.php
// Eseguito ogni 5s da fm-remote-sync.timer. Polling 100% outbound verso il
// relay (Fluxus Remote): invia le registrazioni attive, legge la coda di
// marker/cue creati dalla pulsantiera remota e li ricrea localmente usando
// il timestamp del click (non l'istante di elaborazione).

require_once '/var/www/fluxus-media/includes/db.php';

if (FM_REMOTE_URL === '' || FM_REMOTE_API_KEY === '') {
    exit(0); // feature non configurata
}

if (!is_dir(FM_LOGS)) mkdir(FM_LOGS, 0775, true);
$logFile = FM_LOGS . '/fm-remote-sync.log';

function fmrsLog(string $line): void {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

function fmrsRequest(string $method, string $path, ?array $body = null) {
    $ch = curl_init(FM_REMOTE_URL . $path);
    $headers = ['Authorization: Bearer ' . FM_REMOTE_API_KEY];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException("curl $method $path: $err");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("$method $path -> HTTP $code: $raw");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("$method $path: risposta non JSON");
    }
    return $data;
}

try {
    $db = fmDB();

    // 1) invia al relay l'elenco delle registrazioni attive + nome nodo
    $recordings = $db->query("SELECT id, source_name, media_type, start_time
        FROM recordings WHERE status = 'recording'")->fetchAll();
    fmrsRequest('POST', '/api/sync', ['node_name' => FM_NODE_NAME, 'recordings' => $recordings]);

    // 2) legge la coda pending dal relay
    $resp = fmrsRequest('GET', '/api/queue?status=pending');
    $queue = $resp['queue'] ?? [];

    $processedIds = [];
    foreach ($queue as $item) {
        $qid = (int)($item['id'] ?? 0);
        $recordingId = (int)($item['recording_id'] ?? 0);
        if ($qid <= 0 || $recordingId <= 0) continue;

        $stmt = $db->prepare("SELECT status FROM recordings WHERE id = ?");
        $stmt->execute([$recordingId]);
        $status = $stmt->fetchColumn();

        if ($status !== 'recording') {
            fmrsLog("scartato queue#$qid: registrazione $recordingId non più attiva");
            $processedIds[] = $qid;
            continue;
        }

        try {
            $when = new DateTime($item['created_at'] ?? 'now', new DateTimeZone('UTC'));
            $type = ($item['type'] ?? 'cue') === 'marker' ? 'marker' : 'cue';
            $label = trim((string)($item['label'] ?? ''));
            $result = fmCreateMarker($recordingId, $type, $label, $when, 'remote');
            fmrsLog("queue#$qid -> marker #{$result['marker_id']} ($type, {$result['elapsed_hms']})");
            $processedIds[] = $qid;
        } catch (Throwable $e) {
            fmrsLog("errore creazione marker per queue#$qid: " . $e->getMessage());
            // non lo segno come processato: si ritenta al prossimo giro
        }
    }

    // 3) conferma al relay gli item processati
    if (!empty($processedIds)) {
        fmrsRequest('POST', '/api/queue/ack', ['ids' => $processedIds]);
    }
} catch (Throwable $e) {
    fmrsLog('errore: ' . $e->getMessage());
}
