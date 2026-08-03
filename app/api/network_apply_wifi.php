<?php
/**
 * Cambia rete WiFi. Rischiosa: se la macchina si collegava proprio da questa
 * rete, la pagina potrebbe non rispondere più subito dopo. fluxus-network.sh
 * arma da sé un ripristino automatico (vedi lì, 'apply-wifi'): questo
 * endpoint si limita a passare la richiesta e a dire al frontend che una
 * conferma è attesa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ssid = trim((string)($input['ssid'] ?? ''));
$psk = (string)($input['psk'] ?? '');

if ($ssid === '') {
    fmError('SSID mancante');
}
if ($psk !== '' && (strlen($psk) < 8 || strlen($psk) > 63)) {
    fmError('La password deve avere fra 8 e 63 caratteri');
}

$res = fmNetworkHelper(['apply-wifi', $ssid, $psk]);
if (!$res['ok']) {
    fmError($res['error']);
}

fmJson(['ok' => true, 'pending' => true, 'rollback_seconds' => 45]);
