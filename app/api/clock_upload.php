<?php
/**
 * Collega a una sessione CLOCK conclusa l'audio registrato nel frattempo da un
 * dispositivo esterno indipendente (continuo, senza buchi — vedi
 * docs/NOTE-TECNICHE.md).
 *
 * Il punto tecnico è come far vedere questo file a scripts/extract_clips.sh
 * come se fosse una registrazione live "partita in ritardo": quello script
 * posiziona già i marker guardando mtime_file - durata_ffprobe = inizio
 * reale del file (vedi content_position() in extract_clips.sh), quindi basta
 * scrivere il file al posto giusto con la mtime giusta — zero modifiche allo
 * script, zero modifiche ai marker già esistenti (il loro elapsed_seconds
 * resta quello vero, riferito all'avvio della sessione CLOCK).
 *
 * Un solo upload per registrazione: una volta collegato il file, media_type
 * passa da 'clock' ad 'audio' e un secondo tentativo viene rifiutato.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

// PHP svuota silenziosamente $_POST e $_FILES se il body supera post_max_size:
// nessun UPLOAD_ERR_* da leggere, perché non c'è alcun file. Senza questo
// controllo l'endpoint darebbe un fuorviante "recording_id mancante" invece di
// spiegare che il file è troppo grande (bug reale trovato testando in produzione).
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fmError('File troppo grande: superato il limite di caricamento del server.', 413);
}

$recordingId = (int)($_POST['recording_id'] ?? 0);
if ($recordingId <= 0) fmError('recording_id mancante');

$declaredStartRaw = trim((string)($_POST['start_time'] ?? ''));
if ($declaredStartRaw === '') fmError("Indica l'orario di inizio dichiarato del file.");

try {
    $declaredStart = new DateTime($declaredStartRaw, new DateTimeZone(fmTimezone()));
} catch (Exception $e) {
    fmError('Orario di inizio non valido.');
}

if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'] ?? '')) {
    $err = $_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE;
    fmError($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
        ? 'File troppo grande: superato il limite di caricamento del server.'
        : 'File audio mancante.');
}

$db = fmDB();
$stmt = $db->prepare('SELECT * FROM recordings WHERE id = ?');
$stmt->execute([$recordingId]);
$rec = $stmt->fetch();
if (!$rec) fmError('Registrazione non trovata', 404);

if ($rec['media_type'] === 'audio' || $rec['media_type'] === 'video') {
    fmError('Questa registrazione ha già un file collegato: non è possibile sostituirlo.');
}
if ($rec['media_type'] !== 'clock') {
    fmError('Disponibile solo per registrazioni CLOCK.');
}
if ($rec['status'] === 'recording') {
    fmError('La sessione CLOCK deve essere conclusa prima di caricare un file.');
}

$outputDir = $rec['output_dir'];
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fmError('Impossibile creare la cartella di destinazione.', 500);
}

$finalPath = $outputDir . '/' . $rec['filename_base'] . '.mp3';
$tmpPath = $_FILES['audio']['tmp_name'];

// Verifica se il file è già mp3 (via ffprobe, non per estensione): solo in
// quel caso si evita la transcodifica.
$probeCmd = 'timeout 5 ffprobe -v error -hide_banner -i ' . escapeshellarg($tmpPath)
    . ' -show_entries format=format_name -of default=noprint_wrappers=1:nokey=1 2>/dev/null';
$formatName = trim((string)@shell_exec($probeCmd));
$isMp3 = strpos($formatName, 'mp3') !== false;

if ($isMp3) {
    if (!move_uploaded_file($tmpPath, $finalPath)) {
        fmError('Impossibile salvare il file caricato.', 500);
    }
} else {
    // Qualità coerente con quella già configurata sulla sorgente, se ancora esiste.
    $audioQuality = '2';
    if ($rec['source_id']) {
        $sStmt = $db->prepare('SELECT audio_quality FROM sources WHERE id = ?');
        $sStmt->execute([$rec['source_id']]);
        $q = $sStmt->fetchColumn();
        if ($q !== false && $q !== null && $q !== '') $audioQuality = (string)$q;
    }
    $cmd = 'timeout 300 ffmpeg -y -i ' . escapeshellarg($tmpPath)
        . ' -vn -c:a libmp3lame -q:a ' . escapeshellarg($audioQuality) . ' '
        . escapeshellarg($finalPath) . ' 2>&1';
    @shell_exec($cmd);
    if (!is_file($finalPath)) {
        fmError('Transcodifica fallita: il file non sembra un audio valido.', 500);
    }
}

// Mai fidarsi della durata dichiarata o di quella letta prima della
// transcodifica: si rimisura sempre sul file finale su disco.
$realDuration = fmProbeDuration($finalPath);
if ($realDuration === null || $realDuration <= 0) {
    @unlink($finalPath);
    fmError('Impossibile leggere la durata del file caricato.', 500);
}
$realDurationInt = (int)round($realDuration);

// La mtime del file è l'unico punto di aggancio con extract_clips.sh: fissata a
// "inizio dichiarato + durata reale", lo script lo tratta come uno stream
// partito in ritardo, senza bisogno di alcuna modifica allo script stesso.
$targetMtime = $declaredStart->getTimestamp() + $realDurationInt;
@touch($finalPath, $targetMtime);

$upd = $db->prepare("UPDATE recordings
    SET media_type = 'audio', duration_seconds = ?, clock_origin = 1
    WHERE id = ? AND media_type = 'clock'");
$upd->execute([$realDurationInt, $recordingId]);

if ($upd->rowCount() === 0) {
    // Race: un altro upload è arrivato nel frattempo. Il file appena scritto
    // non va lasciato al posto del vincitore della corsa.
    @unlink($finalPath);
    fmError('Questa registrazione ha già un file collegato: non è possibile sostituirlo.');
}

fmJson([
    'ok'               => true,
    'duration_seconds' => $realDurationInt,
    'duration_hms'     => fmFormatDuration($realDurationInt),
]);
