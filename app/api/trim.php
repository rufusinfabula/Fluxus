<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmTimezone();
fmRequireAuth();

// TRIM/EDIT manuale in quarantena (vedi docs/NOTE-TECNICHE.md): endpoint disabilitato
// centralmente, sia per il trim dei cue (action=cue) sia per l'estrazione
// manuale (action=manual/manual_list). Nessuna azione viene eseguita.
fmError('Funzionalità TRIM/EDIT manuale temporaneamente disabilitata', 503);

$db = fmDB();
$method = $_SERVER['REQUEST_METHOD'];

function fm_trim_segment_files(string $outputDir, string $filenameBase, string $ext): array {
    $files = glob($outputDir . '/' . $filenameBase . '_[0-9][0-9][0-9]' . $ext);
    sort($files);
    if (empty($files)) {
        $single = $outputDir . '/' . $filenameBase . $ext;
        if (is_file($single)) $files = [$single];
    }
    return $files;
}

function fm_ffprobe_duration(string $path): float {
    $out = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path));
    return (float)trim((string)$out);
}

// Estrae [start,end] (secondi, timeline assoluta della registrazione) concatenando
// i file segmento coinvolti (o il file singolo) e applicando atrim.
function fm_extract_audio_range(array $files, float $start, float $end, string $outPath): bool {
    if (empty($files)) return false;

    if (count($files) === 1) {
        $cmd = sprintf(
            'ffmpeg -nostdin -y -i %s -af %s -c:a libmp3lame -q:a 2 %s 2>&1',
            escapeshellarg($files[0]),
            escapeshellarg(sprintf('atrim=start=%.3f:end=%.3f,asetpts=PTS-STARTPTS', $start, $end)),
            escapeshellarg($outPath)
        );
        shell_exec($cmd);
        return is_file($outPath);
    }

    $listFile = tempnam(FM_TMP, 'concat_') . '.txt';
    $lines = [];
    foreach ($files as $f) {
        $lines[] = "file '" . str_replace("'", "'\\''", $f) . "'";
    }
    file_put_contents($listFile, implode("\n", $lines) . "\n");

    $cmd = sprintf(
        'ffmpeg -nostdin -y -f concat -safe 0 -i %s -af %s -c:a libmp3lame -q:a 2 %s 2>&1',
        escapeshellarg($listFile),
        escapeshellarg(sprintf('atrim=start=%.3f:end=%.3f,asetpts=PTS-STARTPTS', $start, $end)),
        escapeshellarg($outPath)
    );
    shell_exec($cmd);
    @unlink($listFile);
    return is_file($outPath);
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'manual_list') {
    $recordingId = (int)($_GET['recording_id'] ?? 0);
    if ($recordingId <= 0) fmError('recording_id mancante');
    $stmt = $db->prepare('SELECT * FROM manual_clips WHERE recording_id = ? ORDER BY start_seconds ASC');
    $stmt->execute([$recordingId]);
    fmJson(['ok' => true, 'clips' => $stmt->fetchAll()]);
}

if ($method === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $clipId = (int)($q['clip_id'] ?? 0);
    if ($clipId <= 0) fmError('clip_id mancante');

    $stmt = $db->prepare('SELECT mc.*, r.source_id FROM manual_clips mc
        JOIN recordings r ON r.id = mc.recording_id WHERE mc.id = ?');
    $stmt->execute([$clipId]);
    $clip = $stmt->fetch();
    if (!$clip) fmError('Clip non trovata', 404);

    if (!empty($clip['clip_filename'])) {
        $path = FM_CLIPS . '/' . $clip['source_id'] . '/' . $clip['clip_filename'];
        if (is_file($path)) @unlink($path);
    }

    $stmt = $db->prepare('DELETE FROM manual_clips WHERE id = ?');
    $stmt->execute([$clipId]);
    fmJson(['ok' => true]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $action = $input['action'] ?? ($_GET['action'] ?? '');

    if ($action === 'cue') {
        $markerId = (int)($input['marker_id'] ?? 0);
        $start = (float)($input['start'] ?? $input['start_seconds'] ?? -1);
        $end = (float)($input['end'] ?? $input['end_seconds'] ?? -1);
        if ($markerId <= 0 || $start < 0 || $end <= $start) fmError('Parametri non validi');

        $stmt = $db->prepare('SELECT m.*, r.media_type, r.source_id, r.filename_base
            FROM markers m JOIN recordings r ON r.id = m.recording_id WHERE m.id = ?');
        $stmt->execute([$markerId]);
        $marker = $stmt->fetch();
        if (!$marker) fmError('Marker non trovato', 404);

        if ($marker['media_type'] !== 'audio') {
            fmError('Trim non disponibile per registrazioni video');
        }
        if ($marker['clip_status'] !== 'ready' || empty($marker['clip_filename'])) {
            fmError('Il cue non è ancora pronto per il trim');
        }

        $clipPath = FM_CLIPS . '/' . $marker['source_id'] . '/' . $marker['clip_filename'];
        if (!is_file($clipPath)) fmError('File clip non trovato', 404);

        $trimFilename = $marker['filename_base'] . '_m' . $markerId . '_trim.mp3';
        $trimPath = FM_CLIPS . '/' . $marker['source_id'] . '/' . $trimFilename;

        $ok = fm_extract_audio_range([$clipPath], $start, $end, $trimPath);
        if (!$ok) fmError('Estrazione ffmpeg fallita', 500);

        $stmt = $db->prepare('UPDATE markers SET clip_trim_filename = ? WHERE id = ?');
        $stmt->execute([$trimFilename, $markerId]);

        fmJson(['ok' => true, 'clip_trim_filename' => $trimFilename]);
    }

    if ($action === 'manual') {
        $recordingId = (int)($input['recording_id'] ?? 0);
        $start = (float)($input['start_seconds'] ?? -1);
        $end = (float)($input['end_seconds'] ?? -1);
        $label = trim((string)($input['label'] ?? ''));
        if ($recordingId <= 0 || $start < 0 || $end <= $start) fmError('Parametri non validi');

        $stmt = $db->prepare('SELECT * FROM recordings WHERE id = ?');
        $stmt->execute([$recordingId]);
        $rec = $stmt->fetch();
        if (!$rec) fmError('Registrazione non trovata', 404);

        if ($rec['media_type'] !== 'audio') {
            fmError('Trim non disponibile per registrazioni video');
        }

        $files = fm_trim_segment_files($rec['output_dir'], $rec['filename_base'], '.mp3');
        if (empty($files)) fmError('Nessun file audio trovato per questa registrazione', 404);

        $duration = $end - $start;
        $stmt = $db->prepare('INSERT INTO manual_clips
            (recording_id, start_seconds, end_seconds, duration_seconds, label)
            VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$recordingId, $start, $end, $duration, $label]);
        $clipId = (int)$db->lastInsertId();

        $clipDir = FM_CLIPS . '/' . $rec['source_id'];
        if (!is_dir($clipDir)) mkdir($clipDir, 0775, true);
        $clipFilename = $rec['filename_base'] . '_manual' . $clipId . '.mp3';
        $clipPath = $clipDir . '/' . $clipFilename;

        $ok = fm_extract_audio_range($files, $start, $end, $clipPath);
        if (!$ok) {
            $db->prepare('DELETE FROM manual_clips WHERE id = ?')->execute([$clipId]);
            fmError('Estrazione ffmpeg fallita', 500);
        }

        $stmt = $db->prepare('UPDATE manual_clips SET clip_filename = ? WHERE id = ?');
        $stmt->execute([$clipFilename, $clipId]);

        fmJson(['ok' => true, 'clip_id' => $clipId, 'clip_filename' => $clipFilename]);
    }

    fmError('Azione non riconosciuta');
}

fmError('Metodo non consentito', 405);
