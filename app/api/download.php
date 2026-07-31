<?php
require_once __DIR__ . '/../includes/auth.php';
fmRequireAuth();

$db = fmDB();

$path = null;
$downloadName = null;

if (!empty($_GET['cue_id'])) {
    $id = (int)$_GET['cue_id'];
    $trim = !empty($_GET['trim']);

    $stmt = $db->prepare('SELECT m.*, r.media_type, r.source_id, r.clips_dir
        FROM markers m JOIN recordings r ON r.id = m.recording_id WHERE m.id = ?');
    $stmt->execute([$id]);
    $marker = $stmt->fetch();
    if (!$marker) fmError('Cue non trovato', 404);

    if ($trim) {
        if (empty($marker['clip_trim_filename'])) fmError('Trim non disponibile', 404);
        $filename = $marker['clip_trim_filename'];
    } else {
        if (empty($marker['clip_filename'])) fmError('Clip non disponibile', 404);
        $filename = $marker['clip_filename'];
    }

    $path = fmRecordingClipsDir($marker) . '/' . $filename;
    $downloadName = $filename;
} elseif (!empty($_GET['manual_id'])) {
    $id = (int)$_GET['manual_id'];

    $stmt = $db->prepare('SELECT mc.*, r.source_id, r.clips_dir
        FROM manual_clips mc JOIN recordings r ON r.id = mc.recording_id WHERE mc.id = ?');
    $stmt->execute([$id]);
    $clip = $stmt->fetch();
    if (!$clip) fmError('Clip non trovata', 404);
    if (empty($clip['clip_filename'])) fmError('Clip non disponibile', 404);

    $path = fmRecordingClipsDir($clip) . '/' . $clip['clip_filename'];
    $downloadName = $clip['clip_filename'];
} else {
    fmError('cue_id o manual_id mancante');
}

// Il file deve stare sotto la radice clip di UNO dei volumi configurati: con
// l'archiviazione multi-volume non basta più confrontare con FM_CLIPS, o ogni
// download da disco esterno risponderebbe 404 (vincolo 21).
$realPath = realpath($path);
$inClipRoot = false;
foreach (fmClipRoots() as $root) {
    $realRoot = realpath($root);
    if ($realRoot !== false && $realPath !== false
        && strpos($realPath, $realRoot . DIRECTORY_SEPARATOR) === 0) {
        $inClipRoot = true;
        break;
    }
}
if ($realPath === false || !$inClipRoot) {
    fmError('File non trovato', 404);
}
if (!is_file($realPath)) {
    fmError('File non trovato', 404);
}

$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mime = $ext === 'mp4' ? 'video/mp4' : 'audio/mpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Accept-Ranges: bytes');
readfile($realPath);
exit;
