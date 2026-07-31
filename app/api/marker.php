<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $db->prepare('SELECT * FROM markers WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) fmError('Marker non trovato', 404);
        fmJson(['ok' => true, 'marker' => $row]);
    }

    $recordingId = (int)($_GET['recording_id'] ?? 0);
    if ($recordingId <= 0) fmError('recording_id mancante');

    $stmt = $db->prepare('SELECT * FROM markers WHERE recording_id = ? ORDER BY elapsed_seconds ASC');
    $stmt->execute([$recordingId]);
    fmJson(['ok' => true, 'markers' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $recordingId = (int)($input['recording_id'] ?? 0);
    $type = ($input['type'] ?? 'cue') === 'marker' ? 'marker' : 'cue';
    $label = trim((string)($input['label'] ?? ''));

    if ($recordingId <= 0) fmError('recording_id mancante');

    $stmt = $db->prepare('SELECT status, start_time, duration_seconds FROM recordings WHERE id = ?');
    $stmt->execute([$recordingId]);
    $rec = $stmt->fetch();
    if (!$rec) fmError('Registrazione non trovata', 404);

    // Posizione esplicita nella registrazione, in secondi dall'inizio. È la via
    // usata dal recupero a posteriori su una registrazione già conclusa.
    $elapsedOverride = null;
    if (isset($input['elapsed_seconds']) && $input['elapsed_seconds'] !== '') {
        $elapsedOverride = max(0, (int)$input['elapsed_seconds']);
        $dur = (int)($rec['duration_seconds'] ?? 0);
        if ($dur > 0 && $elapsedOverride > $dur) {
            fmError('Istante oltre la fine della registrazione (' . fmFormatDuration($dur) . ')');
        }
    }

    // Istante del click, mandato dal modale: rende la posizione indipendente da
    // quanto ci si mette a scrivere l'etichetta o a ritentare dopo un errore.
    $when = null;
    if ($elapsedOverride === null && !empty($input['clicked_at'])) {
        $clicked = (int)$input['clicked_at'];
        $startTs = !empty($rec['start_time']) ? strtotime($rec['start_time'] . ' UTC') : 0;
        // Si accetta solo un istante plausibile: dentro la registrazione e non
        // nel futuro. Un orologio del client sballato non deve poter spostare
        // il marker in un punto arbitrario.
        if ($clicked >= $startTs - 5 && $clicked <= time() + 60) {
            $when = (new DateTime('@' . $clicked))->setTimezone(new DateTimeZone('UTC'));
        }
    }

    // Su una registrazione conclusa "adesso" non vuol dire niente: darebbe una
    // posizione pari al tempo passato dall'inizio dello slot, cioè fuori scala.
    if ($rec['status'] !== 'recording' && $elapsedOverride === null && $when === null) {
        fmError('Registrazione conclusa: indica l\'istante del marker (elapsed_seconds).');
    }

    $result = fmCreateMarker($recordingId, $type, $label, $when, 'local', $elapsedOverride);

    fmJson([
        'ok'           => true,
        'marker_id'    => $result['marker_id'],
        'elapsed_hms'  => $result['elapsed_hms'],
        'recording_id' => $recordingId,
    ]);
}

if ($method === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $id = (int)($q['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) fmError('id mancante');

    $stmt = $db->prepare('SELECT m.*, r.media_type, r.source_id, r.filename_base, r.clips_dir
        FROM markers m JOIN recordings r ON r.id = m.recording_id WHERE m.id = ?');
    $stmt->execute([$id]);
    $marker = $stmt->fetch();
    if (!$marker) fmError('Marker non trovato', 404);

    $ext = $marker['media_type'] === 'video' ? '.mp4' : '.mp3';
    $clipDir = fmRecordingClipsDir($marker);
    $candidates = [
        $clipDir . '/' . $marker['filename_base'] . '_m' . $id . $ext,
        $clipDir . '/' . $marker['filename_base'] . '_m' . $id . '_trim.mp3',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) @unlink($f);
    }

    $stmt = $db->prepare('DELETE FROM markers WHERE id = ?');
    $stmt->execute([$id]);

    fmJson(['ok' => true]);
}

fmError('Metodo non consentito', 405);
