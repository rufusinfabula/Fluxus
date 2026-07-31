<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();

/**
 * Elimina una registrazione: file su disco (registrazione, segmenti, export
 * marker), clip dei cue collegati, e infine la riga in `recordings`.
 * Ritorna false senza toccare nulla se la registrazione non esiste.
 */
function fmDeleteRecording(PDO $db, int $id): bool {
    $stmt = $db->prepare('SELECT * FROM recordings WHERE id = ?');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec) return false;

    $ext = $rec['media_type'] === 'video' ? '.mp4' : '.mp3';
    foreach (glob($rec['output_dir'] . '/' . $rec['filename_base'] . '.*') ?: [] as $f) { @unlink($f); }
    foreach (glob($rec['output_dir'] . '/' . $rec['filename_base'] . '_[0-9][0-9][0-9]' . $ext) ?: [] as $f) { @unlink($f); }
    // Gli export marker stanno sempre sul volume interno (vedi fmGenerateMarkersFiles),
    // anche quando i media sono su un disco esterno: vanno cercati lì.
    $exportDir = FM_RECORDINGS . '/' . $rec['source_id'];
    foreach ([$rec['output_dir'], $exportDir] as $dir) {
        foreach (glob($dir . '/' . $rec['filename_base'] . '_markers.*') ?: [] as $f) { @unlink($f); }
    }

    $clipDir = fmRecordingClipsDir($rec);
    $mStmt = $db->prepare('SELECT clip_filename, clip_trim_filename FROM markers WHERE recording_id = ?');
    $mStmt->execute([$id]);
    foreach ($mStmt->fetchAll() as $m) {
        if (!empty($m['clip_filename'])) @unlink($clipDir . '/' . $m['clip_filename']);
        if (!empty($m['clip_trim_filename'])) @unlink($clipDir . '/' . $m['clip_trim_filename']);
    }
    $cStmt = $db->prepare('SELECT clip_filename FROM manual_clips WHERE recording_id = ?');
    $cStmt->execute([$id]);
    foreach ($cStmt->fetchAll() as $c) {
        if (!empty($c['clip_filename'])) @unlink($clipDir . '/' . $c['clip_filename']);
    }

    // markers e manual_clips vengono rimossi in cascata (ON DELETE CASCADE)
    $db->prepare('DELETE FROM recordings WHERE id = ?')->execute([$id]);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);

    // Eliminazione multipla dalla lista registrazioni: ?ids=1,2,3
    // Le registrazioni in corso vengono saltate, non eliminate: i loro file sono
    // aperti da ffmpeg e la riga verrebbe comunque riscritta alla finalizzazione.
    if (!empty($q['ids'])) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string)$q['ids'])),
            fn($n) => $n > 0
        )));
        if (!$ids) fmError('Nessun id valido');
        if (count($ids) > 200) fmError('Troppe registrazioni in una sola richiesta (max 200)');

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sStmt = $db->prepare("SELECT id, status FROM recordings WHERE id IN ($in)");
        $sStmt->execute($ids);
        $status = [];
        foreach ($sStmt->fetchAll() as $row) { $status[(int)$row['id']] = $row['status']; }

        $deleted = $skippedRunning = $notFound = [];
        foreach ($ids as $id) {
            if (!isset($status[$id]))            { $notFound[] = $id; continue; }
            if ($status[$id] === 'recording')    { $skippedRunning[] = $id; continue; }
            if (fmDeleteRecording($db, $id))     { $deleted[] = $id; }
            else                                 { $notFound[] = $id; }
        }

        fmJson([
            'ok'              => true,
            'deleted'         => $deleted,
            'skipped_running' => $skippedRunning,
            'not_found'       => $notFound,
        ]);
    }

    $id = (int)($q['id'] ?? 0);
    if ($id <= 0) fmError('id mancante');
    if (!fmDeleteRecording($db, $id)) fmError('Registrazione non trovata', 404);

    fmJson(['ok' => true]);
}

$where = [];
$params = [];

if (!empty($_GET['source_id'])) {
    $where[] = 'r.source_id = ?';
    $params[] = (int)$_GET['source_id'];
}
if (!empty($_GET['media_type']) && in_array($_GET['media_type'], ['audio', 'video'], true)) {
    $where[] = 'r.media_type = ?';
    $params[] = $_GET['media_type'];
}
if (!empty($_GET['status']) && in_array($_GET['status'], ['recording', 'completed', 'failed'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $_GET['status'];
}
if (!empty($_GET['date'])) {
    $where[] = "date(r.start_time) = ?";
    $params[] = $_GET['date'];
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 25;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$countStmt = $db->prepare("SELECT COUNT(*) FROM recordings r $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT r.*, s.name AS source_name_current, s.media_type AS source_media_type
        FROM recordings r
        LEFT JOIN sources s ON s.id = r.source_id
        $whereSql
        ORDER BY r.id DESC
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $mStmt = $db->prepare("SELECT recording_id, COUNT(*) AS cnt FROM markers WHERE recording_id IN ($in) GROUP BY recording_id");
    $mStmt->execute($ids);
    $counts = [];
    foreach ($mStmt->fetchAll() as $c) {
        $counts[$c['recording_id']] = (int)$c['cnt'];
    }
    foreach ($rows as &$r) {
        $r['marker_count'] = $counts[$r['id']] ?? 0;
    }
    unset($r);
}

fmJson([
    'ok'          => true,
    'recordings'  => $rows,
    'total'       => $total,
    'limit'       => $limit,
    'offset'      => $offset,
]);
