<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmTimezone();
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sourceId = (int)($input['source_id'] ?? 0);
if ($sourceId <= 0) {
    fmError('source_id mancante');
}

$db = fmDB();
$stmt = $db->prepare('SELECT * FROM sources WHERE id = ? AND active = 1');
$stmt->execute([$sourceId]);
$source = $stmt->fetch();
if (!$source) {
    fmError('Sorgente non trovata o non attiva', 404);
}

$duration = isset($input['duration']) ? (int)$input['duration'] : 3600;
if ($duration <= 0) $duration = 3600;
$segmentDuration = isset($input['segment_duration']) ? (int)$input['segment_duration'] : 0;
$label = trim((string)($input['label'] ?? ''));
$isClock = $source['media_type'] === 'clock';

if ($isClock) {
    // Nessun file da scrivere: path storico del volume interno, nessuna
    // cartella creata su disco (non ci scrive mai nessuno). Con questo path
    // il resto del codice (fmRecordingVolume/fmRecordingSize/retention...)
    // tratta la registrazione CLOCK come una normale sul volume interno,
    // senza bisogno di alcuna eccezione dedicata.
    $outputDir = FM_RECORDINGS . '/' . $sourceId;
    $clipsDir  = null;
} else {
    // Volume di destinazione: override della sorgente o default del media_type,
    // con ripiego sul volume interno se quello scelto non è collegato (vincolo 21).
    $storage   = fmResolveStorage($source);
    $outputDir = $storage['recordings_dir'];
    $clipsDir  = $storage['clips_dir'];
    foreach ([$outputDir, $clipsDir] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    if ($storage['fallback']) {
        $label = trim($label . ' [' . $storage['fallback_reason'] . ': registrazione sul volume interno]');
    }
}

$now = new DateTime('now', new DateTimeZone(fmTimezone()));
$filenameBase = fmFilenamePrefix($source) . '_' . $now->format('Y-m-d_H-i');

$stmt = $db->prepare('INSERT INTO recordings
    (source_id, source_name, media_type, filename_base, output_dir, clips_dir, status,
     trigger_type, slot_duration, segment_duration, notes, start_time)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))');
$stmt->execute([
    $sourceId,
    $source['name'],
    $source['media_type'],
    $filenameBase,
    $outputDir,
    $clipsDir,
    'recording',
    'manual',
    $duration,
    $segmentDuration,
    $label,
]);
$recordingId = (int)$db->lastInsertId();

// Le sorgenti CLOCK non hanno alcun flusso da registrare: nessun record.sh,
// nessun processo ffmpeg, mai. La riga in recordings basta da sola.
if (!$isClock) {
    $cmd = sprintf(
        '%snohup %s %d %d %d %d > %s 2>&1 &',
        fmScriptEnv(),
        escapeshellarg(FM_SCRIPTS . '/record.sh'),
        $recordingId,
        $sourceId,
        $duration,
        $segmentDuration,
        escapeshellarg(FM_LOGS . '/fm-record-' . $recordingId . '.log')
    );
    shell_exec($cmd);
}

fmJson(['ok' => true, 'recording_id' => $recordingId, 'recording_code' => fmRecCode($recordingId, $source['media_type'])]);
