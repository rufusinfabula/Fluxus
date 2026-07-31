<?php
/**
 * Box Archiviazione: ogni movimento salva subito, senza pulsante.
 *
 * Accetta l'ordine dei volumi (righe trascinate) e/o la destinazione di audio e
 * video (tag AUDIO/VIDEO trascinati da un volume all'altro). L'ordine vale anche
 * per la barra di stato — è lo stesso elenco (vedi fmVolumesForDisplay).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fmError('Richiesta non valida');
}

$done = [];

// Toglie un volume dall'elenco: resta montato e i suoi file non si toccano,
// semplicemente non viene più mostrato. Il volume interno e quelli che sono
// destinazione corrente di audio/video non si possono nascondere.
if (!empty($input['hide'])) {
    $key = trim((string)$input['hide']);
    if ($key === '' || $key === '/') {
        fmError('Il volume interno non può essere tolto dall\'elenco');
    }
    $target = null;
    foreach (fmVolumesForDisplay() as $v) {
        if ($v['key'] === $key) { $target = $v; break; }
    }
    if (!$target) {
        fmError('Volume non trovato in elenco');
    }
    foreach (['audio', 'video'] as $mt) {
        $cur = fmStorageVolume((int)fmSetting('storage_volume_' . $mt, '1'));
        if ($cur && rtrim($cur['path'], '/') === rtrim($target['path'], '/')) {
            fmError('Questo volume riceve le registrazioni ' . $mt . ': spostane prima il tag.');
        }
    }
    // La riga in storage_volumes NON viene disattivata: le registrazioni fatte su
    // quel disco la usano per costruire l'URL dei player (fmMediaBaseUrl) e per il
    // controllo anti-traversal dei download (fmClipRoots). Disattivandola, al
    // ritorno del disco i file sarebbero irraggiungibili. Si nasconde e basta.
    // Si nascondono tutte le forme con cui quel disco può comparire — il mount
    // point quando è collegato, la radice dati quando è solo una riga in DB —
    // altrimenti riapparirebbe cambiando stato.
    foreach (array_unique([$key, $target['mount'], $target['path']]) as $k) {
        if ($k !== '' && $k !== '/') fmHideVolume($k);
    }
    $done['hidden'] = $key;
}

// "Rileva dischi": rilegge i dischi presenti e rimette in elenco quelli che ci
// sono davvero. Un volume scollegato NON torna: se non è attaccato, non c'è
// nulla da rilevare — tornerà da sé quando lo si ricollega, perché a quel punto
// compare in /proc/mounts con il suo mount point.
if (!empty($input['rescan'])) {
    $present = [];
    foreach (fmDetectedVolumes() as $d) {
        $present[] = $d['mount'];
        $present[] = rtrim($d['fluxus_path'], '/');
    }
    $hidden = array_values(array_filter(fmHiddenVolumes(), fn($k) => !in_array($k, $present, true)));
    fmSetSetting('storage_volume_hidden', implode("\n", $hidden));
    $done['rescan'] = count(fmVolumesForDisplay());
}

if (isset($input['order']) && is_array($input['order'])) {
    $clean = [];
    foreach ($input['order'] as $key) {
        $key = trim((string)$key);
        // Le chiavi sono mount point: niente a capo, che è il separatore.
        if ($key !== '' && !str_contains($key, "\n")) $clean[] = $key;
    }
    if ($clean) {
        fmSetSetting('storage_volume_order', implode("\n", $clean));
        $done['order'] = count($clean);
    }
}

foreach (['audio', 'video'] as $mediaType) {
    if (!isset($input[$mediaType])) continue;
    [$ok, $msg] = fmAssignStorage(trim((string)$input[$mediaType]), $mediaType);
    if (!$ok) {
        fmError($msg);
    }
    $done[$mediaType] = $msg;
}

if (!$done) {
    fmError('Niente da salvare');
}

fmJson(['ok' => true] + $done);
