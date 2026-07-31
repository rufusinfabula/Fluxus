<?php
require_once __DIR__ . '/../includes/db.php';

function fm_cpu_percent(): float {
    $read = function () {
        $line = @file('/proc/stat')[0] ?? '';
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts); // "cpu"
        $parts = array_map('floatval', $parts);
        $idle = ($parts[3] ?? 0) + ($parts[4] ?? 0);
        $total = array_sum($parts);
        return [$idle, $total];
    };
    [$idle1, $total1] = $read();
    usleep(150000);
    [$idle2, $total2] = $read();
    $dTotal = $total2 - $total1;
    $dIdle = $idle2 - $idle1;
    if ($dTotal <= 0) return 0.0;
    return round((1 - $dIdle / $dTotal) * 100, 1);
}

function fm_mem_info(): array {
    $meminfo = [];
    foreach (@file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
            $meminfo[$m[1]] = (int)$m[2]; // kB
        }
    }
    $total = $meminfo['MemTotal'] ?? 0;
    $avail = $meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0);
    $used = max(0, $total - $avail);
    $swapTotal = $meminfo['SwapTotal'] ?? 0;
    $swapFree = $meminfo['SwapFree'] ?? 0;
    $swapUsed = max(0, $swapTotal - $swapFree);
    return [
        'mem_total_mb'    => round($total / 1024, 1),
        'mem_used_mb'     => round($used / 1024, 1),
        'mem_used_pct'    => $total > 0 ? round($used / $total * 100, 1) : 0,
        'swap_total_mb'   => round($swapTotal / 1024, 1),
        'swap_used_mb'    => round($swapUsed / 1024, 1),
        'swap_used_pct'   => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 1) : 0,
    ];
}

/**
 * Volumi di archiviazione, ordinati dal più occupato al più libero (i volumi
 * scollegati chiudono l'elenco, non avendo percentuale da mostrare).
 *
 * I campi piatti disk_* restano nella risposta, riferiti al volume in testa:
 * sono quelli letti dalla barra di stato prima della v2.5.0 e da eventuali altri
 * consumatori.
 */
function fm_volumes_info(): array {
    // Stesso elenco e stesso ordine della pagina Impostazioni: l'ordine lo decide
    // l'utente trascinando le righe (setting storage_volume_order).
    $volumes = [];
    foreach (fmVolumesForDisplay() as $v) {
        $volumes[] = [
            'id'        => $v['volume_id'],
            'label'     => $v['label'],
            'path'      => $v['path'],
            'is_default'=> $v['is_root'] ? 1 : 0,
            'online'    => $v['online'],
            'total_gb'  => $v['total_gb'],
            'used_gb'   => $v['used_gb'],
            'free_gb'   => $v['free_gb'],
            'used_pct'  => $v['used_pct'],
        ];
    }

    $top = $volumes[0] ?? ['total_gb' => 0, 'used_gb' => 0, 'free_gb' => 0, 'used_pct' => 0];
    return [
        'volumes'       => $volumes,
        'disk_total_gb' => $top['total_gb'],
        'disk_used_gb'  => $top['used_gb'],
        'disk_free_gb'  => $top['free_gb'],
        'disk_used_pct' => $top['used_pct'],
    ];
}

$data = array_merge(
    ['cpu_percent' => fm_cpu_percent()],
    fm_mem_info(),
    fm_volumes_info(),
    ['uptime_sec' => (int)explode(' ', @file_get_contents('/proc/uptime') ?: '0')[0]]
);

fmJson($data);
