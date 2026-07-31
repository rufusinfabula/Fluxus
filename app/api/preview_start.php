<?php
/**
 * Avvia l'anteprima live di una sorgente (audio o video).
 *
 * Fino alla 2.4.0 questo endpoint creava una path temporanea in MediaMTX e
 * lasciava a lui il muxing HLS. Non funzionava: il muxer HLS di MediaMTX
 * aborta sugli stream con timestamp non validi ("unable to extract DTS: DTS
 * is greater than PTS", il caso della sorgente rtmp in produzione). La path
 * diventava ready:true — quindi qui si rispondeva ok:true — ma index.m3u8
 * restava 404/500 e il player non partiva mai, senza alcun errore visibile.
 *
 * Ora il relay è un ffmpeg locale (scripts/preview.sh) che scrive HLS in
 * FM_TMP/preview/{source_id}/, servito da nginx sotto {FM_WEB_BASE}/preview/.
 * Vantaggi collaterali: funziona anche per le sorgenti audio e per v4l2, e
 * l'URL è relativo (niente porta 8888 da raggiungere, niente mixed content).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmJson(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}

// FM_PREVIEW_TTL (durata massima di una preview lasciata aperta, rete di
// sicurezza se il browser sparisce) sta fra le costanti, in config.php.

/** Quanto si attende che ffmpeg si colleghi e produca il primo segmento. */
const FM_PREVIEW_WAIT_SEC = 20;

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$sourceId = (int)($input['source_id'] ?? 0);
if ($sourceId <= 0) {
    fmJson(['ok' => false, 'error' => 'source_id mancante']);
}

$db = fmDB();
$stmt = $db->prepare('SELECT * FROM sources WHERE id = ?');
$stmt->execute([$sourceId]);
$source = $stmt->fetch();
if (!$source) {
    fmJson(['ok' => false, 'error' => 'Sorgente non trovata']);
}

$type = (string)$source['type'];
if ($type !== 'v4l2' && $type !== 'rtmp-push' && trim((string)$source['url']) === '') {
    fmJson(['ok' => false, 'error' => 'Sorgente senza URL: anteprima non disponibile.']);
}
if ($type === 'v4l2' && trim((string)$source['device']) === '') {
    fmJson(['ok' => false, 'error' => 'Sorgente v4l2 senza device: anteprima non disponibile.']);
}

$dir = FM_TMP . '/preview/' . $sourceId;
$pidFile = $dir . '/pid';

// Una preview già in corso su questa sorgente viene sempre riavviata: la
// playlist vecchia è a finestra scorrevole e ripartire da zero costa un paio
// di secondi, molto meno che diagnosticare un relay rimasto appeso.
fmKillPreviewRelay($pidFile);

$playlist = $dir . '/index.m3u8';
@unlink($playlist);

$cmd = fmScriptEnv() . 'setsid nohup ' . escapeshellarg(FM_SCRIPTS . '/preview.sh')
    . ' ' . escapeshellarg((string)$sourceId)
    . ' ' . escapeshellarg((string)$source['media_type'])
    . ' ' . escapeshellarg($type)
    . ' ' . escapeshellarg((string)($source['url'] ?? ''))
    . ' ' . escapeshellarg((string)($source['device'] ?? ''))
    . ' ' . escapeshellarg((string)($source['resolution'] ?: '1280x720'))
    . ' ' . escapeshellarg((string)((int)$source['fps'] ?: 25))
    . ' ' . escapeshellarg((string)FM_PREVIEW_TTL)
    . ' >/dev/null 2>&1 &';
@shell_exec($cmd);

// Si attende non la sola playlist ma anche il primo segmento: ffmpeg scrive
// index.m3u8 appena ha aperto l'output, e un player che la legge vuota molla
// subito con un errore di manifest.
$ready = false;
$deadline = microtime(true) + FM_PREVIEW_WAIT_SEC;
while (microtime(true) < $deadline) {
    usleep(400000);
    clearstatcache();
    if (is_file($playlist)) {
        $content = (string)@file_get_contents($playlist);
        if (strpos($content, '.ts') !== false) { $ready = true; break; }
    }
    // Se il relay è già morto inutile aspettare la scadenza: si legge il perché.
    if (is_file($pidFile)) {
        $pid = (int)trim((string)@file_get_contents($pidFile));
        if ($pid > 1 && !file_exists('/proc/' . $pid)) break;
    }
}

if (!$ready) {
    $err = 'Impossibile collegarsi alla sorgente entro ' . FM_PREVIEW_WAIT_SEC . 's.';
    if ($type === 'v4l2') {
        $err .= ' Se è in corso una registrazione, il device locale è già occupato.';
    }
    $log = FM_LOGS . '/fm-preview-' . $sourceId . '.log';
    $tail = @shell_exec('tail -n 3 ' . escapeshellarg($log) . ' 2>/dev/null');
    if ($tail) $err .= ' — ' . substr(trim(preg_replace('/\s+/', ' ', $tail)), -220);
    fmJson(['ok' => false, 'error' => $err]);
}

fmJson([
    'ok'         => true,
    'source_id'  => $sourceId,
    'media_type' => $source['media_type'],
    'hls_url'    => rtrim(FM_WEB_BASE, '/') . '/preview/' . $sourceId . '/index.m3u8?t=' . time(),
]);
