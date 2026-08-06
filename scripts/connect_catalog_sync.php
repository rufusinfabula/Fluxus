<?php
// Fluxus Connect — connect_catalog_sync.php
// Eseguito ogni 30s da fm-connect-catalog-sync.timer. Polling 100% outbound
// verso Fluxus Connect (broker), separato da connect_sync.php (2s, marker/cue:
// percorso critico, non va rallentato né riusato per altro). Pubblica uno
// specchio pieno — non una coda, non un diff — di registrazioni, marker/cue,
// sorgenti e orari: stessa logica "manda tutto, senza euristiche" già usata
// da connect_sync.php per le registrazioni attive.
//
// Whitelist di sicurezza (stessa già in vigore per l'API pubblica di Connect,
// vedi NOTE-TECNICHE.md): mai percorsi filesystem, PID, note interne,
// configurazione delle sorgenti. Ogni query qui sotto seleziona solo le
// colonne da esportare, non "SELECT *".
$fmConfFile = getenv('FLUXUS_CONF');
if (!is_string($fmConfFile) || $fmConfFile === '') {
    $fmConfFile = dirname(__DIR__) . '/fluxus.conf';
}
if (!is_readable($fmConfFile)) {
    fwrite(STDERR, "connect_catalog_sync.php: configurazione non leggibile: $fmConfFile\n");
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
$logFile = FM_LOGS . '/fm-connect-catalog-sync.log';

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

    // 1) Registrazioni — tutto lo storico, non solo quelle attive: copre sia
    // "lista" sia "dettaglio" lato Connect (che specchia la riga intera e la
    // serve sia in elenco che per singolo id, non essendo il Pi mai
    // raggiungibile per una query on-demand). Stessa espressione di
    // duration_seconds già usata in app/recordings.php, non un'altra.
    $recordings = $db->query("SELECT r.id, r.source_id, r.source_name, r.media_type,
        r.status, r.start_time, r.end_time,
        CASE
            WHEN r.duration_seconds IS NOT NULL AND r.duration_seconds > 0 THEN r.duration_seconds
            WHEN r.start_time IS NOT NULL AND r.end_time IS NOT NULL
                THEN CAST((julianday(r.end_time) - julianday(r.start_time)) * 86400 AS INTEGER)
            ELSE r.duration_seconds
        END AS duration_seconds
        FROM recordings r ORDER BY r.id DESC")->fetchAll();

    $counts = [];
    if ($recordings) {
        // Stessa aggregazione già usata in app/recordings.php: clip pronte
        // (cue con clip_status='ready') e marker totali per registrazione.
        $mStmt = $db->query("SELECT recording_id,
            SUM(CASE WHEN type='cue' AND clip_status='ready' THEN 1 ELSE 0 END) AS clips,
            COUNT(*) AS markers
            FROM markers GROUP BY recording_id");
        foreach ($mStmt->fetchAll() as $c) {
            $counts[$c['recording_id']] = ['clips' => (int)$c['clips'], 'markers' => (int)$c['markers']];
        }
    }

    $recordingsOut = [];
    foreach ($recordings as $r) {
        $recordingsOut[] = [
            'id'               => (string)$r['id'],
            'source_id'        => $r['source_id'] !== null ? (int)$r['source_id'] : null,
            'source_name'      => $r['source_name'],
            'media_type'       => $r['media_type'],
            'status'           => $r['status'],
            'start_time'       => $r['start_time'],
            'end_time'         => $r['end_time'],
            'duration_seconds' => $r['duration_seconds'] !== null ? (int)$r['duration_seconds'] : null,
            'marker_count'     => $counts[$r['id']]['markers'] ?? 0,
            'clip_count'       => $counts[$r['id']]['clips'] ?? 0,
        ];
    }
    fccsRequest('POST', '/api/pi/recordings.php', ['recordings' => $recordingsOut]);

    // 2) Marker e cue — dump piatto di tutta la tabella, filtrabile lato
    // Connect per recording_id e per type (marker/cue), stessa divisione di
    // responsabilità già in vigore per il filtro media_type. Esclusi
    // clip_filename/clip_trim_filename: sono percorsi filesystem, vietati
    // dalla whitelist di sicurezza.
    $markers = $db->query("SELECT id, recording_id, elapsed_seconds, elapsed_hms,
        absolute_time, label, type, clip_status, origin, origin_label, created_at
        FROM markers ORDER BY id ASC")->fetchAll();

    $markersOut = [];
    foreach ($markers as $m) {
        $markersOut[] = [
            'id'              => (string)$m['id'],
            'recording_id'    => (string)$m['recording_id'],
            'elapsed_seconds' => (int)$m['elapsed_seconds'],
            'elapsed_hms'     => $m['elapsed_hms'],
            'absolute_time'   => $m['absolute_time'],
            'label'           => $m['label'],
            'type'            => $m['type'],
            'clip_status'     => $m['clip_status'],
            'origin'          => $m['origin'],
            'origin_label'    => $m['origin_label'],
            'created_at'      => $m['created_at'],
        ];
    }
    fccsRequest('POST', '/api/pi/markers.php', ['markers' => $markersOut]);

    // 3) Sorgenti — solo identità e tipo: url/device/extra_opts/profili
    // qualità/soglie di retention sono configurazione della sorgente, vietata
    // dalla stessa whitelist.
    $sources = $db->query('SELECT id, name, media_type, active FROM sources ORDER BY name ASC')->fetchAll();
    $sourcesOut = [];
    foreach ($sources as $s) {
        $sourcesOut[] = [
            'id'         => (string)$s['id'],
            'name'       => $s['name'],
            'media_type' => $s['media_type'],
            'active'     => (bool)$s['active'],
        ];
    }
    fccsRequest('POST', '/api/pi/sources.php', ['sources' => $sourcesOut]);

    // 4) Orari programmati.
    $schedules = $db->query("SELECT sch.id, sch.source_id, src.name AS source_name,
        sch.label, sch.on_calendar, sch.slot_duration, sch.active
        FROM schedules sch LEFT JOIN sources src ON src.id = sch.source_id
        ORDER BY sch.id ASC")->fetchAll();
    $schedulesOut = [];
    foreach ($schedules as $s) {
        $schedulesOut[] = [
            'id'            => (string)$s['id'],
            'source_id'     => (string)$s['source_id'],
            'source_name'   => $s['source_name'],
            'label'         => $s['label'],
            'on_calendar'   => $s['on_calendar'],
            'slot_duration' => (int)$s['slot_duration'],
            'active'        => (bool)$s['active'],
        ];
    }
    fccsRequest('POST', '/api/pi/schedules.php', ['schedules' => $schedulesOut]);
} catch (Throwable $e) {
    fccsLog('errore: ' . $e->getMessage());
}
