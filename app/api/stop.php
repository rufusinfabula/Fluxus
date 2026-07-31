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
$stmt = $db->prepare('SELECT id FROM recordings WHERE id = ? AND status = ?');
$stmt->execute([$recordingId, 'recording']);
if (!$stmt->fetch()) {
    fmError('Registrazione non trovata o non attiva', 404);
}

$cmd = sprintf(
    '%s%s %d > /dev/null 2>&1 &',
    fmScriptEnv(),
    escapeshellarg(FM_SCRIPTS . '/stop_recording.sh'),
    $recordingId
);
shell_exec($cmd);

fmJson(['ok' => true]);
