<?php
/**
 * Ferma l'anteprima live di una sorgente e ne cancella i file HLS effimeri.
 *
 * Chiamato alla chiusura del modale di anteprima (anche via sendBeacon).
 * Il relay ha comunque un TTL suo in preview.sh, quindi mancare questa
 * chiamata non lascia processi eterni — solo qualche minuto di banda sprecata.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmJson(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$sourceId = (int)($input['source_id'] ?? 0);
if ($sourceId <= 0) {
    fmJson(['ok' => false, 'error' => 'source_id mancante']);
}

$dir = FM_TMP . '/preview/' . $sourceId;
$pidFile = $dir . '/pid';

fmKillPreviewRelay($pidFile);

// Il path è costruito qui da un intero validato, non arriva mai dal client.
foreach (glob($dir . '/*') ?: [] as $f) {
    if (is_file($f)) @unlink($f);
}
@rmdir($dir);

fmJson(['ok' => true]);
