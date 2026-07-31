<?php
require_once __DIR__ . '/../includes/auth.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$url = trim((string)($input['url'] ?? ''));
$device = trim((string)($input['device'] ?? ''));
$type = (string)($input['type'] ?? 'http');

// Il form di sources.php passa i campi non ancora salvati; la dashboard passa
// solo l'id di una sorgente esistente e i parametri si leggono dal DB.
$sourceId = (int)($input['source_id'] ?? 0);
if ($sourceId > 0) {
    require_once __DIR__ . '/../includes/helpers.php';
    $stmt = fmDB()->prepare('SELECT url, device, type FROM sources WHERE id = ?');
    $stmt->execute([$sourceId]);
    $src = $stmt->fetch();
    if (!$src) {
        fmError('Sorgente non trovata');
    }
    $url    = trim((string)$src['url']);
    $device = trim((string)$src['device']);
    $type   = (string)$src['type'];
}

// rtmp-push non ha un URL da sondare: l'ingresso è una path di MediaMTX
// alimentata dall'encoder esterno, e "raggiungibile" qui vuol dire
// "qualcuno sta effettivamente pushando adesso".
if ($type === 'rtmp-push') {
    if ($sourceId <= 0) {
        fmError('Sorgente push non salvata: impossibile testarla');
    }
    require_once __DIR__ . '/../includes/helpers.php';
    // fmResolvePushPath accetta anche i percorsi a due segmenti prodotti dagli
    // encoder che concatenano indirizzo e stream (Wirecast): vedi helpers.php.
    $info = fmResolvePushPath($sourceId);
    if ($info !== null) {
        $tracks  = !empty($info['tracks']) ? implode(', ', $info['tracks']) : 'stream attivo';
        $name    = (string)($info['name'] ?? $sourceId);
        // Il percorso si mostra solo quando non è quello canonico: è l'unico
        // caso in cui all'utente serve sapere dove sta arrivando lo stream.
        $suffix  = $name === (string)$sourceId ? '' : ' · percorso ' . $name;
        fmJson(['ok' => true, 'summary' => 'push in ingresso · ' . $tracks . $suffix]);
    }
    fmJson(['ok' => false, 'error' => 'Nessuno stream in ingresso su questa sorgente push.']);
}

$target = $type === 'v4l2' ? $device : $url;
if ($target === '') {
    fmError('Nessun URL/device da testare');
}

if ($type === 'v4l2') {
    $cmd = 'timeout 6 ffprobe -v error -hide_banner -f v4l2 -i ' . escapeshellarg($target)
        . ' -show_entries stream=codec_name,width,height -of json 2>&1';
} else {
    $cmd = 'timeout 8 ffprobe -v error -hide_banner -rw_timeout 5000000 -i ' . escapeshellarg($target)
        . ' -show_entries format=duration:stream=codec_type,codec_name,sample_rate,channels,width,height -of json 2>&1';
}

$output = @shell_exec($cmd);
$json = null;
if ($output) {
    $trimmed = trim($output);
    $jsonStart = strpos($trimmed, '{');
    if ($jsonStart !== false) {
        $json = json_decode(substr($trimmed, $jsonStart), true);
    }
}

if ($json && !empty($json['streams'])) {
    $summary = [];
    foreach ($json['streams'] as $s) {
        if (($s['codec_type'] ?? '') === 'audio') {
            $summary[] = 'audio: ' . ($s['codec_name'] ?? '?') .
                (!empty($s['sample_rate']) ? ' ' . round($s['sample_rate'] / 1000) . 'kHz' : '');
        } elseif (($s['codec_type'] ?? '') === 'video') {
            $summary[] = 'video: ' . ($s['codec_name'] ?? '?') .
                (!empty($s['width']) ? ' ' . $s['width'] . 'x' . $s['height'] : '');
        }
    }
    fmJson(['ok' => true, 'summary' => implode(' · ', $summary) ?: 'stream raggiungibile']);
}

$errLine = trim((string)$output);
$errLine = $errLine !== '' ? substr($errLine, 0, 200) : 'stream non raggiungibile o timeout';
fmJson(['ok' => false, 'error' => $errLine]);
