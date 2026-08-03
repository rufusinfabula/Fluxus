<?php
/**
 * Conferma la rete/IP appena applicati: disarma il ripristino automatico
 * armato da apply-wifi/apply-ip. Vedi fluxus-network.sh, 'confirm'.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$res = fmNetworkHelper(['confirm']);
if (!$res['ok']) {
    fmError($res['error']);
}

fmJson(['ok' => true]);
