<?php
/**
 * Pulsante "Testa connessione" della card Fluxus Connect (Impostazioni):
 * verifica che URL e token raggiungano davvero il broker, senza aspettare il
 * prossimo giro di fm-connect-sync.timer.
 *
 * URL/token arrivano dal form così com'è in quel momento (non ancora
 * salvato): se il token è lasciato vuoto si usa quello già configurato,
 * stessa regola di "lascia vuoto per non modificare" del salvataggio.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$url = trim((string)($input['url'] ?? ''));
$token = trim((string)($input['token'] ?? ''));
if ($token === '') $token = FM_CONNECT_TOKEN;

if ($url === '' || $token === '') {
    fmError('Configura prima URL e token di Fluxus Connect.');
}

$r = fmConnectTest($url, $token);
if (!$r['ok']) {
    fmError($r['error']);
}

fmJson(['ok' => true, 'subkeys' => $r['subkeys']]);
