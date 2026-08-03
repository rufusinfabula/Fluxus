<?php
/**
 * Scansione delle reti WiFi visibili. Può durare qualche secondo (il
 * ripristino di fluxus-network.sh aspetta il rescan prima di elencare) —
 * lato frontend il pulsante va disabilitato durante l'attesa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fmError('Metodo non consentito', 405);
}

$res = fmNetworkHelper(['scan']);
if (!$res['ok']) {
    fmError($res['error']);
}

$networks = [];
$seen = [];
foreach ($res['lines'] as $line) {
    if (trim($line) === '') continue;
    [$ssid, $signal, $security] = array_pad(fmNmcliFields($line), 3, '');
    if ($ssid === '' || isset($seen[$ssid])) continue;
    $seen[$ssid] = true;
    $networks[] = [
        'ssid'     => $ssid,
        'signal'   => (int)$signal,
        'open'     => $security === '' || $security === '--',
    ];
}
usort($networks, fn($a, $b) => $b['signal'] <=> $a['signal']);

fmJson(['ok' => true, 'networks' => $networks]);
