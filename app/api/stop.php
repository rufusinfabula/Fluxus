<?php
require_once __DIR__ . '/../includes/auth.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$recordingId = (int)($input['recording_id'] ?? 0);
if ($recordingId <= 0) {
    fmError('recording_id mancante');
}

$db = fmDB();
$stmt = $db->prepare('SELECT id, media_type FROM recordings WHERE id = ? AND status = ?');
$stmt->execute([$recordingId, 'recording']);
$rec = $stmt->fetch();
if (!$rec) {
    fmError('Registrazione non trovata o non attiva', 404);
}

// Le registrazioni CLOCK non hanno alcun processo ffmpeg da fermare né un
// record.sh che finalizzi: stop_recording.sh aspetterebbe inutilmente fino a
// 6s prima di ricadere sul suo fallback. Si finalizza subito qui.
if ($rec['media_type'] === 'clock') {
    $upd = $db->prepare("UPDATE recordings SET status='completed', end_time=datetime('now'),
        duration_seconds=CAST((julianday('now') - julianday(start_time)) * 86400 AS INTEGER)
        WHERE id = ? AND status = 'recording'");
    $upd->execute([$recordingId]);
    fmJson(['ok' => true]);
}

$cmd = sprintf(
    '%s%s %d > /dev/null 2>&1 &',
    fmScriptEnv(),
    escapeshellarg(FM_SCRIPTS . '/stop_recording.sh'),
    $recordingId
);
shell_exec($cmd);

fmJson(['ok' => true]);
