<?php
/**
 * Attiva/disattiva a mano l'hotspot di configurazione (fase 4). Utile
 * quando la macchina è già configurata ma il router è cambiato ed è
 * irraggiungibile: si può rientrare dal telefono senza accesso fisico.
 * Rischiosa quanto apply-wifi: se questa pagina è raggiunta proprio dalla
 * rete WiFi che l'hotspot sta per sostituire, la connessione potrebbe
 * interrompersi. Nessun conto alla rovescia con conferma qui — a
 * differenza di apply-wifi/apply-ip, l'hotspot ha già una propria vita
 * autolimitata (15 minuti, vedi fluxus-network.sh, 'hotspot-start').
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($input['action'] ?? '');

if ($action !== 'start' && $action !== 'stop') {
    fmError('Azione non valida');
}

$res = fmNetworkHelper([$action === 'start' ? 'hotspot-start' : 'hotspot-stop']);
if (!$res['ok']) {
    fmError($res['error']);
}

fmJson(['ok' => true]);
