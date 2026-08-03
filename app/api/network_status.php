<?php
/**
 * Stato della rete: interfaccia, SSID/connessione, IP, gateway, DNS, metodo
 * IPv4, ed eventuale conto alla rovescia se una modifica è in attesa di
 * conferma (vedi network_apply_wifi.php / network_apply_ip.php).
 *
 * Sola lettura, ma passa comunque da fluxus-network.sh via sudo: PHP-FPM
 * (www-data) non ha i permessi per interrogare NetworkManager direttamente.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

$res = fmNetworkHelper(['status']);
if (!$res['ok']) {
    fmError($res['error']);
}

$kv = fmNetworkParseKV($res['lines']);

fmJson([
    'ok'         => true,
    'device'     => $kv['DEVICE'] ?? '',
    'hostname'   => $kv['HOSTNAME'] ?? '',
    'state'      => $kv['STATE'] ?? 'unknown',
    'connection' => $kv['CONNECTION'] ?? '',
    'ssid'       => $kv['SSID'] ?? '',
    'address'    => $kv['ADDRESS'] ?? '',
    'gateway'    => $kv['GATEWAY'] ?? '',
    'dns'        => $kv['DNS'] ?? '',
    'method'     => $kv['METHOD'] ?? 'auto',
    'pending'    => isset($kv['PENDING']) ? (int)$kv['PENDING'] : null,
    'hotspot'         => ($kv['HOTSPOT'] ?? '') === 'active',
    'hotspot_ssid'    => $kv['HOTSPOT_SSID'] ?? '',
    'hotspot_timeout' => isset($kv['HOTSPOT_TIMEOUT']) ? (int)$kv['HOTSPOT_TIMEOUT'] : null,
]);
