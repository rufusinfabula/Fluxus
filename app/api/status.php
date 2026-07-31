<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();

$recording = $db->query("SELECT r.*, s.name AS current_source_name
    FROM recordings r
    LEFT JOIN sources s ON s.id = r.source_id
    WHERE r.status = 'recording'
    ORDER BY r.start_time DESC")->fetchAll();

foreach ($recording as &$r) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM markers WHERE recording_id = ?');
    $stmt->execute([$r['id']]);
    $r['marker_count'] = (int)$stmt->fetchColumn();
    if (!empty($r['start_time'])) {
        $r['elapsed_seconds'] = max(0, time() - strtotime($r['start_time'] . ' UTC'));
    } else {
        $r['elapsed_seconds'] = 0;
    }
}
unset($r);

$sourcesByType = [
    'audio' => (int)$db->query("SELECT COUNT(*) FROM sources WHERE media_type='audio' AND active=1")->fetchColumn(),
    'video' => (int)$db->query("SELECT COUNT(*) FROM sources WHERE media_type='video' AND active=1")->fetchColumn(),
];

$mediamtxActive = false;
$out = @shell_exec('systemctl is-active mediamtx 2>/dev/null');
if ($out !== null && trim($out) === 'active') {
    $mediamtxActive = true;
}

fmJson([
    'ok'          => true,
    'recordings'  => $recording,
    'sources'     => $sourcesByType,
    'mediamtx'    => $mediamtxActive,
    'server_time' => date('c'),
]);
