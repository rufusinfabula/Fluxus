<?php
// Fluxus Connect — connect_sync.php
// Eseguito ogni 2s da fm-connect-sync.timer. Polling 100% outbound verso
// Fluxus Connect (broker): pubblica tutte le registrazioni attive, legge la
// coda di marker/cue depositati dalle console esterne e li ricrea localmente
// usando fmCreateMarker() (helpers.php) — mai reimplementata qui.
//
// Stesso bootstrap di remote_sync.php: vive in <cartella dati>/scripts,
// risale di un livello per leggere fluxus.conf, e da lì carica l'applicazione.
$fmConfFile = getenv('FLUXUS_CONF');
if (!is_string($fmConfFile) || $fmConfFile === '') {
    $fmConfFile = dirname(__DIR__) . '/fluxus.conf';
}
if (!is_readable($fmConfFile)) {
    fwrite(STDERR, "connect_sync.php: configurazione non leggibile: $fmConfFile\n");
    exit(78);   // EX_CONFIG
}
$fmBoot = [];
foreach (file($fmConfFile, FILE_IGNORE_NEW_LINES) ?: [] as $fmLine) {
    if (preg_match('/^\s*(FLUXUS_WEB_DIR|FLUXUS_INSTANCE)\s*=\s*(.*)$/', $fmLine, $m)) {
        $fmBoot[$m[1]] = trim($m[2], " \t\"'");
    }
}
$fmWebDir = $fmBoot['FLUXUS_WEB_DIR'] ?? ('/var/www/' . ($fmBoot['FLUXUS_INSTANCE'] ?? ''));
putenv('FLUXUS_CONF=' . $fmConfFile);
require_once rtrim($fmWebDir, '/') . '/includes/db.php';

if (FM_CONNECT_URL === '' || FM_CONNECT_TOKEN === '') {
    exit(0); // feature non configurata
}

if (!is_dir(FM_LOGS)) mkdir(FM_LOGS, 0775, true);
$logFile = FM_LOGS . '/fm-connect-sync.log';

function fccsLog(string $line): void {
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
}

function fccsRequest(string $method, string $path, ?array $body = null) {
    $ch = curl_init(FM_CONNECT_URL . $path);
    $headers = ['Authorization: Bearer ' . FM_CONNECT_TOKEN];
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

    // 1) legge tutte le registrazioni attive — stessa query/calcolo di
    // app/api/status.php, non un altro: elapsed_seconds e marker_count vanno
    // calcolati allo stesso modo ovunque appaiano.
    $recordings = $db->query("SELECT id, source_name, media_type, start_time
        FROM recordings WHERE status = 'recording'")->fetchAll();

    $registrations = [];
    foreach ($recordings as $r) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM markers WHERE recording_id = ?');
        $stmt->execute([$r['id']]);
        $markerCount = (int)$stmt->fetchColumn();
        $elapsed = !empty($r['start_time']) ? max(0, time() - strtotime($r['start_time'] . ' UTC')) : 0;
        $startedAt = !empty($r['start_time'])
            ? (new DateTime($r['start_time'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
            : null;

        $registration = [
            'id'          => (string)$r['id'],
            'state'       => 'In corso',   // stessa dicitura di dashboard.php/recordings.php
            'source'      => $r['source_name'],
            'media_type'  => $r['media_type'],
            'elapsed_seconds' => $elapsed,
            'marker_count'    => $markerCount,
        ];
        if ($startedAt !== null) $registration['started_at'] = $startedAt;
        $registrations[] = $registration;
    }

    fccsRequest('POST', '/api/pi/status.php', ['registrations' => $registrations]);

    // Registrazioni attive in questo momento, per id — usato per validare il
    // target_id di ciascun comando (mai un'euristica: la scelta è sempre
    // della console che lo ha depositato).
    $activeIds = array_column($registrations, 'id');

    // 2) legge la coda pending
    $resp = fccsRequest('GET', '/api/pi/queue.php');
    $queue = $resp['commands'] ?? [];

    foreach ($queue as $item) {
        $qid = (string)($item['id'] ?? '');
        if ($qid === '') continue;

        $targetId = $item['target_id'] ?? null;
        if ($targetId !== null) {
            if (!in_array((string)$targetId, $activeIds, true)) {
                fccsLog("scartato queue#$qid: target_id {$targetId} non è (più) fra le registrazioni attive");
                fccsRequest('POST', '/api/pi/ack.php', ['id' => $qid]);
                continue;
            }
            $recordingId = (int)$targetId;
        } elseif (count($activeIds) === 1) {
            // Stessa condizione per cui Connect stesso assegna il bersaglio in
            // automatico: con una sola registrazione attiva non c'è nulla fra
            // cui scegliere.
            $recordingId = (int)$activeIds[0];
        } else {
            $motivo = $activeIds === [] ? 'nessuna registrazione attiva' : 'più di una registrazione attiva, bersaglio ambiguo';
            fccsLog("scartato queue#$qid: $motivo");
            fccsRequest('POST', '/api/pi/ack.php', ['id' => $qid]);
            continue;
        }

        try {
            $when = new DateTime($item['created_at'] ?? 'now', new DateTimeZone('UTC'));
            $type = ($item['type'] ?? 'cue') === 'marker' ? 'marker' : 'cue';
            $label = trim((string)($item['label'] ?? ''));
            $subkeyName = isset($item['subkey_name']) && is_string($item['subkey_name']) && $item['subkey_name'] !== ''
                ? $item['subkey_name']
                : null;
            $result = fmCreateMarker($recordingId, $type, $label, $when, 'connect', null, $subkeyName);
            fccsLog("queue#$qid -> marker #{$result['marker_id']} ($type, {$result['elapsed_hms']}, console: " . ($subkeyName ?? '?') . ')');
            fccsRequest('POST', '/api/pi/ack.php', ['id' => $qid]);
        } catch (Throwable $e) {
            fccsLog("errore creazione marker per queue#$qid: " . $e->getMessage());
            // non confermato: si ritenta al prossimo giro
        }
    }
} catch (Throwable $e) {
    fccsLog('errore: ' . $e->getMessage());
}
