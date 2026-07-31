<?php
/**
 * Abilita un disco esterno all'uso da parte di Fluxus: lo stacca dall'automount
 * del desktop, lo rimonta stabilmente sotto /mnt da /etc/fstab e crea la
 * cartella dati di proprietà di www-data.
 *
 * Il lavoro vero lo fa /usr/local/bin/fluxus-enable-volume.sh via sudo (regola
 * in /etc/sudoers.d/fluxus-media): PHP-FPM gira come www-data e non potrebbe né
 * montare né scrivere in /etc/fstab.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$mount = trim((string)($input['mount'] ?? ''));
if ($mount === '') {
    fmError('Volume mancante');
}

// L'UUID si ricava dal mount point rilevato, non dal client: così l'unico
// argomento che arriva allo script di root proviene da /proc/mounts.
$device = null;
foreach (fmDetectedVolumes() as $d) {
    if ($d['mount'] === $mount) { $device = $d['device']; break; }
}
if ($device === null) {
    fmError('Volume non trovato fra quelli montati');
}

// Da /dev/disk/by-uuid, non da blkid: quest'ultimo legge il device e da
// www-data tornerebbe vuoto senza segnalare nulla.
$uuid = fmDeviceUUID($device);
if ($uuid === '') {
    fmError('UUID del dispositivo non leggibile: il disco è ancora collegato?');
}

$out = trim((string)@shell_exec(
    'sudo -n /usr/local/bin/fluxus-enable-volume.sh ' . escapeshellarg($uuid) . ' 2>&1'
));

if (!str_starts_with($out, 'OK ')) {
    fmError($out !== '' ? preg_replace('/^ERR /', '', $out) : 'Abilitazione fallita');
}

$newMount = trim(substr($out, 3));
$label = '';
foreach (fmDetectedVolumes() as $d) {
    if ($d['mount'] === $newMount) { $label = $d['label']; break; }
}

// Registra subito il volume: compare in elenco e può ricevere i tag.
$volumeId = fmRegisterVolume($newMount . '/fluxus-media', $label !== '' ? $label : basename($newMount));

fmJson(['ok' => true, 'mount' => $newMount, 'volume_id' => $volumeId, 'label' => $label]);
