<?php
/**
 * Cambia il nome host di sistema. A basso rischio (non tocca la
 * connettività): nessun meccanismo di ripristino, a differenza di WiFi e IP.
 *
 * Distinto dal "Nodo" di Impostazioni (settings.node_name, usato da Fluxus
 * Remote per identificarsi al relay): qui è l'hostname del sistema
 * operativo, un valore diverso.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim((string)($input['hostname'] ?? ''));

if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $name)) {
    fmError('Nome macchina non valido (lettere, cifre e trattino, senza iniziare o finire con un trattino)');
}

$res = fmNetworkHelper(['hostname', $name]);
if (!$res['ok']) {
    fmError($res['error']);
}

fmJson(['ok' => true, 'hostname' => $name]);
