<?php
require_once __DIR__ . '/../includes/auth.php';
fmRequireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$expr = trim((string)($input['expr'] ?? ($_GET['expr'] ?? '')));
if ($expr === '') {
    fmJson(['ok' => false, 'error' => 'Espressione vuota']);
}

$cmd = 'systemd-analyze calendar ' . escapeshellarg($expr) . ' 2>&1';
$out = @shell_exec($cmd);
$out = (string)$out;

if (strpos($out, 'Failed to parse') !== false || strpos($out, 'Next elapse') === false) {
    $errLine = trim(preg_replace('/^Failed to parse calendar specification.*?: /', '', $out));
    fmJson(['ok' => false, 'error' => $errLine ?: 'Espressione non valida']);
}

$normalized = null;
$next = null;
foreach (explode("\n", $out) as $line) {
    if (preg_match('/^\s*Normalized form:\s*(.+)$/', $line, $m)) $normalized = trim($m[1]);
    if (preg_match('/^\s*Next elapse:\s*(.+)$/', $line, $m)) $next = trim($m[1]);
}

fmJson(['ok' => true, 'normalized' => $normalized, 'next' => $next]);
