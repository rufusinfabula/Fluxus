<?php
/**
 * Cambia indirizzo IP (automatico/DHCP o manuale). Rischiosa quanto il cambio
 * di rete WiFi, stesso meccanismo di ripristino automatico — vedi
 * fluxus-network.sh, 'apply-ip'.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$mode = trim((string)($input['mode'] ?? ''));

if (!in_array($mode, ['auto', 'manual'], true)) {
    fmError('Modalità non valida');
}

$args = ['apply-ip', $mode];
if ($mode === 'manual') {
    $address = trim((string)($input['address'] ?? ''));
    $prefix = trim((string)($input['prefix'] ?? ''));
    $gateway = trim((string)($input['gateway'] ?? ''));
    $dns = trim((string)($input['dns'] ?? ''));

    $ipRe = '/^(\d{1,3}\.){3}\d{1,3}$/';
    if (!preg_match($ipRe, $address)) fmError('Indirizzo IP non valido');
    if (!ctype_digit($prefix) || (int)$prefix < 1 || (int)$prefix > 32) fmError('Prefisso di rete non valido');
    if (!preg_match($ipRe, $gateway)) fmError('Gateway non valido');
    foreach (array_filter(explode(',', $dns)) as $d) {
        if (!preg_match($ipRe, trim($d))) fmError('DNS non valido');
    }

    $args = array_merge($args, [$address, $prefix, $gateway, $dns]);
}

$res = fmNetworkHelper($args);
if (!$res['ok']) {
    fmError($res['error']);
}

fmJson(['ok' => true, 'pending' => true, 'rollback_seconds' => 45]);
