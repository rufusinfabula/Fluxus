<?php
/**
 * Abilita un disco esterno all'uso da parte di Fluxus: lo stacca dall'automount
 * del desktop, lo rimonta stabilmente sotto /mnt da /etc/fstab e crea la
 * cartella dati di proprietà dell'utente di Fluxus.
 *
 * Due modi di arrivarci:
 * - `mount`: un disco appena rilevato (fmDetectedVolumes(), letto da
 *   /proc/mounts) che non è ancora abilitato — automontato dal desktop o non
 *   scrivibile da FM_USER.
 * - `volume_id`: un volume già registrato ma segnato offline. Capita quando
 *   un disco si scollega per un attimo (calo di tensione USB) e il kernel lo
 *   ripropone senza che nulla lo rimonti da solo: qui non c'è nulla da
 *   rilevare in /proc/mounts, l'UUID si recupera dalla riga che lo stesso
 *   script scrisse in /etc/fstab la prima volta.
 *
 * In entrambi i casi il lavoro vero lo fa /usr/local/bin/fluxus-enable-volume.sh
 * via sudo (regola in /etc/sudoers.d/<istanza>): PHP-FPM non gira come root e
 * non potrebbe né montare né scrivere in /etc/fstab.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$mount = trim((string)($input['mount'] ?? ''));
$volumeId = (int)($input['volume_id'] ?? 0);
if ($mount === '' && $volumeId === 0) {
    fmError('Volume mancante');
}

if ($mount !== '') {
    // L'UUID si ricava dal mount point rilevato, non dal client: così l'unico
    // argomento che arriva allo script di root proviene da /proc/mounts.
    $device = null;
    foreach (fmDetectedVolumes() as $d) {
        if ($d['mount'] === $mount) { $device = $d['device']; break; }
    }
    if ($device === null) {
        fmError('Volume non trovato fra quelli montati');
    }
    // Da /dev/disk/by-uuid, non da blkid: quest'ultimo legge il device e senza
    // privilegi tornerebbe vuoto senza segnalare nulla.
    $uuid = fmDeviceUUID($device);
    if ($uuid === '') {
        fmError('UUID del dispositivo non leggibile: il disco è ancora collegato?');
    }
} else {
    $vol = fmStorageVolume($volumeId);
    if ($vol === null) {
        fmError('Volume non trovato');
    }
    if (fmVolumeOnline($vol)) {
        fmError('Il volume risulta già collegato: ricarica la pagina');
    }
    $uuid = fmFstabUUIDForMount(dirname(rtrim($vol['path'], '/')));
    if ($uuid === '') {
        fmError('Nessuna voce in /etc/fstab per questo volume: va abilitato di nuovo dall\'elenco dei dischi collegati');
    }
}

// Lo script è unico per la macchina: cartella, utente e gruppo di questa
// istanza gli vanno passati. La regola sudo li fissa a questi stessi valori,
// quindi non c'è modo di chiedergli di lavorare per un'altra installazione.
$out = trim((string)@shell_exec(
    'sudo -n /usr/local/bin/fluxus-enable-volume.sh '
    . escapeshellarg($uuid) . ' '
    . escapeshellarg(FM_VOLUME_DIR) . ' '
    . escapeshellarg(FM_USER) . ' '
    . escapeshellarg(FM_GROUP) . ' 2>&1'
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
$volumeId = fmRegisterVolume($newMount . '/' . FM_VOLUME_DIR, $label !== '' ? $label : basename($newMount));

fmJson(['ok' => true, 'mount' => $newMount, 'volume_id' => $volumeId, 'label' => $label]);
