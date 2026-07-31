<?php

function fmTimezone(): string {
    static $tz = null;
    if ($tz === null) {
        if (function_exists('fmDB')) {
            $tz = fmSetting('timezone', 'Europe/Rome');
        } else {
            $tz = 'Europe/Rome';
        }
        date_default_timezone_set($tz);
    }
    return $tz;
}

function fmFormatDuration(?int $sec): string {
    $sec = (int)$sec;
    if ($sec < 0) $sec = 0;
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/** Formatta una data salvata in UTC (formato SQLite datetime('now')) come d/m/Y H:i nel fuso locale. */
function fmFormatDateTime(?string $utcDateTime): string {
    if (!$utcDateTime) return '—';
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(fmTimezone()));
        return $dt->format('d/m/Y H:i');
    } catch (Exception $e) {
        return fmH($utcDateTime);
    }
}

function fmFormatBytes(?int $bytes): string {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    $val = $bytes / (1024 ** $i);
    return sprintf('%s %s', $i === 0 ? $val : number_format($val, 1), $units[$i]);
}

// Somma su disco dei file di una registrazione: file singolo ({base}.mp3|mp4) oppure
// tutti i segmenti ({base}_NNN.mp3|mp4). Ritorna 0 se non c'è nulla su disco.
function fmRecordingSize(array $rec): int {
    $dir  = $rec['output_dir'] ?? '';
    $base = $rec['filename_base'] ?? '';
    if ($dir === '' || $base === '') return 0;
    $ext = ($rec['media_type'] ?? 'audio') === 'video' ? 'mp4' : 'mp3';
    $total = 0;
    foreach (glob($dir . '/' . $base . '.' . $ext) ?: [] as $f) {
        $total += (int)@filesize($f);
    }
    foreach (glob($dir . '/' . $base . '_[0-9][0-9][0-9].' . $ext) ?: [] as $f) {
        $total += (int)@filesize($f);
    }
    return $total;
}

function fmGenerateUUID(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Genera i file di export marker (CSV/TXT/JSON) nella output_dir della
 * registrazione, stesso formato di Audio Recorder 2.5.
 */
function fmGenerateMarkersFiles(int $recordingId): array {
    $db = fmDB();

    $stmt = $db->prepare('SELECT * FROM recordings WHERE id = ?');
    $stmt->execute([$recordingId]);
    $rec = $stmt->fetch();
    if (!$rec) {
        return [];
    }

    $stmt = $db->prepare('SELECT * FROM markers WHERE recording_id = ? ORDER BY elapsed_seconds ASC');
    $stmt->execute([$recordingId]);
    $markers = $stmt->fetchAll();

    // Gli export marker restano SEMPRE sul volume interno, anche quando i media
    // vanno su un disco esterno: sono informazioni, non registrazioni, e devono
    // restare leggibili con il disco scollegato (scelta esplicita dell'utente).
    $dir = FM_RECORDINGS . '/' . $rec['source_id'];
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $base = $dir . '/' . $rec['filename_base'] . '_markers';

    // CSV
    $csvPath = $base . '.csv';
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['type', 'elapsed_seconds', 'elapsed_hms', 'absolute_time', 'label']);
    foreach ($markers as $m) {
        fputcsv($fh, [$m['type'], $m['elapsed_seconds'], $m['elapsed_hms'], $m['absolute_time'], $m['label']]);
    }
    fclose($fh);

    // TXT
    $txtPath = $base . '.txt';
    $lines = [];
    foreach ($markers as $m) {
        $tag = strtoupper($m['type']);
        $lines[] = sprintf('[%s] %s (%s) - %s', $tag, $m['elapsed_hms'], $m['absolute_time'], $m['label'] ?: '');
    }
    file_put_contents($txtPath, implode("\n", $lines) . "\n");

    // JSON
    $jsonPath = $base . '.json';
    file_put_contents($jsonPath, json_encode([
        'recording_id'   => $rec['id'],
        'source_name'    => $rec['source_name'],
        'media_type'     => $rec['media_type'],
        'filename_base'  => $rec['filename_base'],
        'markers'        => $markers,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return ['csv' => $csvPath, 'txt' => $txtPath, 'json' => $jsonPath];
}

/** Prefisso usato per i nomi file: file_prefix esplicito se impostato, altrimenti slug del nome sorgente. */
function fmFilenamePrefix(array $source): string {
    $prefix = trim((string)($source['file_prefix'] ?? ''));
    return $prefix !== '' ? fmSlug($prefix) : fmSlug($source['name']);
}

/** Stato di un timer systemd fm-sched-{id}.timer: ultimo/prossimo scatto (timestamp unix o null). */
function fmGetTimerStatus(int $scheduleId): array {
    $unit = escapeshellarg('fm-sched-' . $scheduleId . '.timer');
    $parse = function ($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        $ts = strtotime($s);
        return $ts !== false ? $ts : null;
    };
    // Chiamate separate: con --value, una proprietà vuota non stampa riga,
    // disallineando il parsing posizionale se richieste insieme.
    $last = @shell_exec("systemctl show $unit --property=LastTriggerUSec --value 2>/dev/null");
    $next = @shell_exec("systemctl show $unit --property=NextElapseUSecRealtime --value 2>/dev/null");
    return [
        'last' => $parse($last),
        'next' => $parse($next),
    ];
}

function fmFormatTs(?int $ts): string {
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

/** Formato breve usato nelle card sorgente: "24/07/26, 15:03". */
function fmFormatDateTimeShort(?string $utcDateTime): string {
    if (!$utcDateTime) return '—';
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(fmTimezone()));
        return $dt->format('d/m/y, H:i');
    } catch (Exception $e) {
        return fmH($utcDateTime);
    }
}

function fmFormatSlotDuration(?int $sec): string {
    $sec = (int)$sec;
    if ($sec <= 0) return '—';
    if ($sec % 3600 === 0) return ($sec / 3600) . 'h';
    return round($sec / 60) . ' min';
}

function fmSlug(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return $s !== '' ? $s : 'source';
}

/**
 * Ispeziona un file video locale con ffprobe: durata, risoluzione, fps, frame.
 * Ritorna [] se il file non esiste o ffprobe fallisce.
 */
function fmProbeVideoFile(string $path): array {
    if (!is_file($path)) return [];
    $cmd = 'timeout 5 ffprobe -v error -hide_banner -i ' . escapeshellarg($path)
        . ' -show_entries format=duration:stream=codec_type,width,height,r_frame_rate,nb_frames -of json 2>&1';
    $output = @shell_exec($cmd);
    if (!$output) return [];
    $jsonStart = strpos($output, '{');
    if ($jsonStart === false) return [];
    $json = json_decode(substr($output, $jsonStart), true);
    if (!$json) return [];

    $info = [
        'duration' => isset($json['format']['duration']) ? (float)$json['format']['duration'] : null,
        'width'    => null,
        'height'   => null,
        'fps'      => null,
        'frames'   => null,
    ];
    foreach ($json['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') !== 'video') continue;
        $info['width'] = $s['width'] ?? null;
        $info['height'] = $s['height'] ?? null;
        if (!empty($s['r_frame_rate']) && str_contains($s['r_frame_rate'], '/')) {
            [$num, $den] = explode('/', $s['r_frame_rate']);
            if ((float)$den > 0) $info['fps'] = round((float)$num / (float)$den, 2);
        }
        $info['frames'] = isset($s['nb_frames']) ? (int)$s['nb_frames'] : null;
        break;
    }
    if (!$info['frames'] && $info['duration'] && $info['fps']) {
        $info['frames'] = (int)round($info['duration'] * $info['fps']);
    }
    return $info;
}

/**
 * Sola durata di un file media locale, in secondi (null se illeggibile).
 * Versione leggera di fmProbeVideoFile() per i file audio, dove risoluzione e
 * fps non esistono: si chiede a ffprobe il solo campo che serve.
 */
function fmProbeDuration(string $path): ?float {
    if (!is_file($path)) return null;
    $cmd = 'timeout 5 ffprobe -v error -hide_banner -i ' . escapeshellarg($path)
        . ' -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 2>/dev/null';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $out = trim((string)$out);
    return is_numeric($out) ? (float)$out : null;
}

function fmH(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------------------------------------------------------------------------
 * Archiviazione su più volumi (v2.5.0)
 *
 * Ogni volume replica la struttura recordings/{source_id} e clips/{source_id}
 * sotto la propria radice. Il volume interno (is_default=1) è FM_BASE, quindi i
 * path storici restano identici. Vedi vincolo 21 in docs/NOTE-TECNICHE.md.
 * ------------------------------------------------------------------------- */

/** Nome del file sentinella che marca la radice di un volume esterno montato. */
const FM_VOLUME_SENTINEL = '.fluxus-volume';

/**
 * Un volume esterno è considerato collegato SOLO se contiene il file sentinella.
 *
 * ⚠️ Non basta is_dir(): quando un disco USB viene scollegato resta la directory
 * vuota del mount point, e Fluxus scriverebbe in silenzio sulla microSD credendo
 * di scrivere sul disco esterno. Il volume interno è per definizione sempre
 * collegato (è il filesystem di root).
 */
function fmVolumeOnline(array $volume): bool {
    if (!empty($volume['is_default'])) {
        return is_dir($volume['path']);
    }
    return is_file(rtrim($volume['path'], '/') . '/' . FM_VOLUME_SENTINEL);
}

/**
 * Elenco dei volumi attivi. Con $withUsage aggiunge lo spazio occupato/libero
 * (letto solo per i volumi collegati: su uno scollegato darebbe i numeri della
 * microSD sottostante il mount point).
 */
function fmStorageVolumes(bool $withUsage = false): array {
    $rows = fmDB()->query('SELECT * FROM storage_volumes WHERE active = 1 ORDER BY is_default DESC, id ASC')->fetchAll();
    foreach ($rows as &$v) {
        $v['id']         = (int)$v['id'];
        $v['is_default'] = (int)$v['is_default'];
        $v['online']     = fmVolumeOnline($v);
        if (!$withUsage) continue;

        $total = $v['online'] ? (@disk_total_space($v['path']) ?: 0) : 0;
        $free  = $v['online'] ? (@disk_free_space($v['path']) ?: 0) : 0;
        $used  = max(0, $total - $free);
        $v['total_gb'] = round($total / 1073741824, 1);
        $v['used_gb']  = round($used / 1073741824, 1);
        $v['free_gb']  = round($free / 1073741824, 1);
        $v['used_pct'] = $total > 0 ? round($used / $total * 100, 1) : 0;
    }
    unset($v);
    return $rows;
}

/** Un singolo volume per id, o null se inesistente/disattivato. */
function fmStorageVolume(?int $id): ?array {
    if (!$id) return null;
    $stmt = fmDB()->prepare('SELECT * FROM storage_volumes WHERE id = ? AND active = 1');
    $stmt->execute([$id]);
    $v = $stmt->fetch();
    if (!$v) return null;
    $v['id']         = (int)$v['id'];
    $v['is_default'] = (int)$v['is_default'];
    $v['online']     = fmVolumeOnline($v);
    return $v;
}

/** Volume interno (FM_BASE): esiste sempre, è il fallback di ogni risoluzione. */
function fmDefaultVolume(): array {
    $v = fmDB()->query('SELECT * FROM storage_volumes WHERE is_default = 1 LIMIT 1')->fetch();
    if (!$v) {
        $v = ['id' => 1, 'label' => 'Interno (microSD)', 'path' => FM_BASE, 'is_default' => 1, 'active' => 1];
    }
    $v['id']         = (int)$v['id'];
    $v['is_default'] = (int)$v['is_default'];
    $v['online']     = true;
    return $v;
}

/**
 * Volume di destinazione di una sorgente: override della sorgente, altrimenti
 * il default del suo media_type. Ritorna anche le due directory di lavoro.
 *
 * Se il volume risolto è scollegato o non scrivibile si ripiega sul volume
 * interno con fallback=true: una diretta programmata non va persa perché
 * qualcuno ha urtato il cavo USB (scelta esplicita dell'utente).
 */
function fmResolveStorage(array $source): array {
    $mediaType = ($source['media_type'] ?? 'audio') === 'video' ? 'video' : 'audio';
    $sourceId  = (int)($source['id'] ?? 0);

    $wanted = fmStorageVolume((int)($source['storage_volume_id'] ?? 0))
        ?: fmStorageVolume((int)fmSetting('storage_volume_' . $mediaType, '1'));

    $fallback = false;
    $reason   = '';
    if (!$wanted) {
        $wanted = fmDefaultVolume();
    } elseif (!$wanted['online']) {
        $reason   = sprintf('volume "%s" non collegato', $wanted['label']);
        $wanted   = fmDefaultVolume();
        $fallback = true;
    } elseif (!is_writable($wanted['path'])) {
        $reason   = sprintf('volume "%s" non scrivibile', $wanted['label']);
        $wanted   = fmDefaultVolume();
        $fallback = true;
    }

    $root = rtrim($wanted['path'], '/');
    return [
        'volume_id'      => $wanted['id'],
        'volume_label'   => $wanted['label'],
        'recordings_dir' => $root . '/recordings/' . $sourceId,
        'clips_dir'      => $root . '/clips/' . $sourceId,
        'fallback'       => $fallback,
        'fallback_reason'=> $reason,
    ];
}

/**
 * Cartella dei cue di una registrazione. Le righe create prima della v2.5.0 non
 * hanno clips_dir: per quelle vale il path storico FM_CLIPS/{source_id}.
 */
function fmRecordingClipsDir(array $rec): string {
    $dir = trim((string)($rec['clips_dir'] ?? ''));
    return $dir !== '' ? $dir : FM_CLIPS . '/' . ($rec['source_id'] ?? 0);
}

/**
 * Volumi realmente montati sulla macchina, con il nome che il disco porta scritto
 * addosso (LABEL del filesystem). Serve a far scegliere il volume da un elenco
 * invece che digitando un percorso.
 *
 * Sono esclusi gli pseudo-filesystem (tmpfs, proc, sys…) e le partizioni di
 * servizio (/boot/firmware). La radice / è sempre presente: è il volume interno.
 */
function fmDetectedVolumes(): array {
    $realFs = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'f2fs', 'vfat', 'exfat',
               'ntfs', 'ntfs3', 'fuseblk', 'hfsplus', 'apfs', 'zfs'];
    $skipMounts = ['/boot', '/boot/firmware'];

    // LABEL per device, da /dev/disk/by-label (leggibile senza privilegi).
    $labels = [];
    foreach (glob('/dev/disk/by-label/*') ?: [] as $link) {
        $dev = realpath($link);
        if ($dev) $labels[$dev] = str_replace('\x20', ' ', basename($link));
    }

    $out = [];
    foreach (@file('/proc/mounts') ?: [] as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 3) continue;
        [$dev, $mount, $fs] = [$p[0], str_replace('\\040', ' ', $p[1]), $p[2]];
        if (!in_array($fs, $realFs, true)) continue;
        if (in_array($mount, $skipMounts, true)) continue;
        if (isset($out[$mount])) continue;

        $isRoot = $mount === '/';
        $total  = @disk_total_space($mount) ?: 0;
        $free   = @disk_free_space($mount) ?: 0;
        // Sui dischi che www-data non può attraversare (automount del desktop)
        // statfs fallisce e si vedrebbero 0 GB: le dimensioni vere si prendono
        // da lsblk, e in ultima istanza da root tramite lo script di sistema.
        if ($total <= 0) {
            [$total, $free] = fmVolumeSizeFallback($dev, $mount);
        }
        $used   = max(0, $total - $free);

        $out[$mount] = [
            'mount'      => $mount,
            'device'     => $dev,
            'fs'         => $fs,
            // La radice dei dati sul volume interno resta FM_BASE, così i path
            // storici non cambiano; sugli altri dischi una cartella dedicata.
            'fluxus_path'=> $isRoot ? FM_BASE : rtrim($mount, '/') . '/fluxus-media',
            'label'      => $isRoot ? 'Interno (microSD)' : ($labels[realpath($dev) ?: $dev] ?? basename($mount)),
            'is_root'    => $isRoot,
            'total_gb'   => round($total / 1073741824, 1),
            'used_gb'    => round($used / 1073741824, 1),
            'free_gb'    => round($free / 1073741824, 1),
            'used_pct'   => $total > 0 ? round($used / $total * 100, 1) : 0,
        ];
        // Scrivibile se lo è la cartella dati (quando esiste già) o la radice del
        // disco, dove andrebbe creata: la radice di un mount è spesso root:root
        // mentre la cartella dati appartiene a www-data.
        $fluxusPath = $out[$mount]['fluxus_path'];
        $out[$mount]['writable'] = $isRoot
            || (is_dir($fluxusPath) ? is_writable($fluxusPath) : is_writable($mount));

        // I dischi automontati dal desktop finiscono sotto /media/<utente>/, una
        // cartella che www-data non può nemmeno attraversare: lo spazio risulta 0
        // e nessuna scrittura è possibile. Va detto, altrimenti sembra un disco rotto.
        $out[$mount]['desktop_mount'] = !$isRoot
            && preg_match('#^/media/[^/]+/#', $mount . '/')
            && !is_readable($mount);
    }
    uasort($out, fn($a, $b) => [$b['is_root'], $b['used_pct']] <=> [$a['is_root'], $a['used_pct']]);
    return array_values($out);
}

/**
 * UUID di un dispositivo a blocchi, letto dai symlink di /dev/disk/by-uuid.
 *
 * ⚠️ Non usare `blkid`: legge direttamente il device (brw-rw---- root:disk) e
 * da www-data restituisce vuoto senza errore, facendo fallire in silenzio tutto
 * ciò che ne dipende. I symlink invece sono leggibili da chiunque.
 */
function fmDeviceUUID(string $device): string {
    $real = realpath($device) ?: $device;
    foreach (glob('/dev/disk/by-uuid/*') ?: [] as $link) {
        if ((realpath($link) ?: '') === $real) {
            $uuid = basename($link);
            return preg_match('/^[A-Fa-f0-9-]{4,36}$/', $uuid) ? $uuid : '';
        }
    }
    return '';
}

/**
 * Dimensione e spazio libero di un disco che PHP non riesce a interrogare
 * direttamente. Prima si prova `lsblk` (legge /sys, non serve attraversare il
 * mount), poi lo script di root, che vede il filesystem comunque.
 * Ritorna [total_bytes, free_bytes].
 */
function fmVolumeSizeFallback(string $device, string $mount): array {
    $out = trim((string)@shell_exec(
        'lsblk -bno SIZE,FSAVAIL ' . escapeshellarg($device) . ' 2>/dev/null | head -n 1'
    ));
    $parts = preg_split('/\s+/', $out, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $total = isset($parts[0]) ? (int)$parts[0] : 0;
    $free  = isset($parts[1]) ? (int)$parts[1] : 0;
    if ($total > 0 && $free > 0) {
        return [$total, $free];
    }

    $uuid = fmDeviceUUID($device);
    if ($uuid !== '') {
        $info = trim((string)@shell_exec(
            'sudo -n /usr/local/bin/fluxus-enable-volume.sh --info ' . escapeshellarg($uuid) . ' 2>/dev/null'
        ));
        if (preg_match('/^INFO (\d+) (\d+)$/', $info, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
    }
    return [$total, $free];
}

/**
 * Elenco dei volumi mostrato in Impostazioni e nella barra di stato: i dischi
 * realmente montati, più i volumi già usati in passato e ora scollegati (le loro
 * registrazioni esistono ancora). L'ordine è quello scelto a mano dall'utente
 * (setting storage_volume_order, lista di mount point); chi non vi compare va in
 * fondo, i volumi scollegati per ultimi.
 */
function fmVolumesForDisplay(): array {
    $list = [];
    foreach (fmDetectedVolumes() as $d) {
        $vol = null;
        foreach (fmStorageVolumes() as $v) {
            if (rtrim($v['path'], '/') === rtrim($d['fluxus_path'], '/')) { $vol = $v; break; }
        }
        $list[] = [
            'key'        => $d['mount'],
            'label'      => $vol['label'] ?? $d['label'],
            'mount'      => $d['mount'],
            'device'     => $d['device'],
            'fs'         => $d['fs'],
            'path'       => $d['fluxus_path'],
            'is_root'       => $d['is_root'],
            'writable'      => $d['writable'],
            'desktop_mount' => $d['desktop_mount'] ?? false,
            'online'        => true,
            'volume_id'  => $vol['id'] ?? ($d['is_root'] ? fmDefaultVolume()['id'] : 0),
            'total_gb'   => $d['total_gb'],
            'used_gb'    => $d['used_gb'],
            'free_gb'    => $d['free_gb'],
            'used_pct'   => $d['used_pct'],
        ];
    }

    $mounted = array_column($list, 'path');
    foreach (fmStorageVolumes() as $v) {
        if ($v['online'] || in_array(rtrim($v['path'], '/'), $mounted, true)) continue;
        $list[] = [
            'key'       => $v['path'],
            'label'     => $v['label'],
            'mount'     => $v['path'],
            'device'    => '—',
            'fs'        => '—',
            'path'      => $v['path'],
            'is_root'       => false,
            'writable'      => false,
            'desktop_mount' => false,
            'online'        => false,
            'volume_id' => $v['id'],
            'total_gb'  => 0, 'used_gb' => 0, 'free_gb' => 0, 'used_pct' => 0,
        ];
    }

    // Volumi tolti dall'elenco a mano: si nascondono, ma mai quello interno né
    // quelli che sono destinazione corrente di audio o video.
    $hidden = fmHiddenVolumes();
    if ($hidden) {
        $inUse = [];
        foreach (['audio', 'video'] as $mt) {
            $v = fmStorageVolume((int)fmSetting('storage_volume_' . $mt, '1'));
            if ($v) $inUse[] = rtrim($v['path'], '/');
        }
        $list = array_values(array_filter($list, function ($v) use ($hidden, $inUse) {
            if ($v['is_root'] || in_array(rtrim($v['path'], '/'), $inUse, true)) return true;
            return !in_array($v['key'], $hidden, true);
        }));
    }

    $order = fmVolumeOrder();
    usort($list, function ($a, $b) use ($order) {
        if ($a['online'] !== $b['online']) return $a['online'] ? -1 : 1;
        $ia = array_search($a['key'], $order, true);
        $ib = array_search($b['key'], $order, true);
        if ($ia === false && $ib === false) return $b['used_pct'] <=> $a['used_pct'];
        if ($ia === false) return 1;
        if ($ib === false) return -1;
        return $ia <=> $ib;
    });
    return $list;
}

/**
 * Assegna le registrazioni di un tipo media al volume montato su $mount,
 * registrandolo se è la prima volta. Ritorna [ok, messaggio].
 */
function fmAssignStorage(string $mount, string $mediaType): array {
    $mediaType = $mediaType === 'video' ? 'video' : 'audio';
    $target = null;
    foreach (fmDetectedVolumes() as $d) {
        if ($d['mount'] === $mount) { $target = $d; break; }
    }
    if (!$target) {
        return [false, 'Volume non riconosciuto: è stato scollegato?'];
    }
    if (!$target['is_root'] && !$target['writable']) {
        return [false, sprintf('Il volume «%s» non è scrivibile da www-data.', $target['label'])];
    }
    $id = $target['is_root']
        ? fmDefaultVolume()['id']
        : fmRegisterVolume($target['fluxus_path'], $target['label']);
    fmSetSetting('storage_volume_' . $mediaType, (string)$id);
    return [true, $target['label']];
}

/** Ordine dei volumi scelto a mano (lista di chiavi/mount point). */
function fmVolumeOrder(): array {
    $raw = trim((string)fmSetting('storage_volume_order', ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
}

/**
 * Volumi tolti a mano dall'elenco: restano montati e i loro file restano dove
 * sono, semplicemente non si vedono più in Impostazioni né in barra di stato.
 * Il pulsante "Rileva dischi" svuota questa lista.
 */
function fmHiddenVolumes(): array {
    $raw = trim((string)fmSetting('storage_volume_hidden', ''));
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
}

function fmHideVolume(string $key): void {
    $hidden = fmHiddenVolumes();
    if (!in_array($key, $hidden, true)) {
        $hidden[] = $key;
        fmSetSetting('storage_volume_hidden', implode("\n", $hidden));
    }
}

/**
 * Registra (o riusa) il volume che corrisponde a una radice dati, creandone la
 * struttura e la sentinella. Ritorna l'id in storage_volumes.
 */
function fmRegisterVolume(string $fluxusPath, string $label): int {
    $db = fmDB();
    $path = rtrim($fluxusPath, '/');

    $stmt = $db->prepare('SELECT id, active FROM storage_volumes WHERE path = ?');
    $stmt->execute([$path]);
    if ($row = $stmt->fetch()) {
        $db->prepare('UPDATE storage_volumes SET label = ?, active = 1 WHERE id = ?')
           ->execute([$label, (int)$row['id']]);
        $id = (int)$row['id'];
    } else {
        $db->prepare('INSERT INTO storage_volumes (label, path, is_default, active) VALUES (?, ?, 0, 1)')
           ->execute([$label, $path]);
        $id = (int)$db->lastInsertId();
    }

    foreach ([$path, $path . '/recordings', $path . '/clips'] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    @touch($path . '/' . FM_VOLUME_SENTINEL);
    fmEnsureVolumeLink($id, $path);
    return $id;
}

/**
 * Symlink FM_BASE/volumes/{id} -> radice del volume, servito da nginx sotto
 * /fluxus-media/volumes/. È ciò che permette ai player di leggere i file su un
 * disco esterno senza aggiungere una location per ogni volume.
 */
function fmEnsureVolumeLink(int $volumeId, string $path): void {
    $dir = FM_BASE . '/volumes';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $link = $dir . '/' . $volumeId;
    if (is_link($link)) {
        if (readlink($link) === $path) return;
        @unlink($link);
    }
    @symlink($path, $link);
}

/**
 * URL base da cui il browser legge i file di una registrazione: la cartella
 * storica se sta sul volume interno, il symlink del volume altrimenti.
 */
function fmMediaBaseUrl(array $rec): string {
    $webBase = rtrim(FM_WEB_BASE, '/');
    $dir = rtrim((string)($rec['output_dir'] ?? ''), '/');
    $sourceId = (int)($rec['source_id'] ?? 0);

    if ($dir === '' || str_starts_with($dir . '/', rtrim(FM_RECORDINGS, '/') . '/')) {
        return $webBase . '/recordings/' . $sourceId . '/';
    }
    foreach (fmStorageVolumes() as $v) {
        if (!empty($v['is_default'])) continue;
        $root = rtrim($v['path'], '/');
        if (str_starts_with($dir . '/', $root . '/')) {
            fmEnsureVolumeLink($v['id'], $root);
            return $webBase . '/volumes/' . $v['id'] . '/recordings/' . $sourceId . '/';
        }
    }
    return $webBase . '/recordings/' . $sourceId . '/';
}

/**
 * Volume su cui stanno i file di una registrazione, per messaggi come "il disco
 * <etichetta> non è collegato". Null se sta sul volume interno.
 */
function fmRecordingVolume(array $rec): ?array {
    $dir = rtrim((string)($rec['output_dir'] ?? ''), '/');
    if ($dir === '' || str_starts_with($dir . '/', rtrim(FM_BASE, '/') . '/')) return null;
    foreach (fmStorageVolumes() as $v) {
        if (!empty($v['is_default'])) continue;
        if (str_starts_with($dir . '/', rtrim($v['path'], '/') . '/')) return $v;
    }
    return null;
}

/** Radici clip valide (una per volume attivo), usate per il controllo anti-traversal. */
function fmClipRoots(): array {
    $roots = [FM_CLIPS];
    foreach (fmStorageVolumes() as $v) {
        $roots[] = rtrim($v['path'], '/') . '/clips';
    }
    return array_values(array_unique($roots));
}

/**
 * Quante registrazioni hanno i file sotto la radice di un volume.
 *
 * Confronto per prefisso con substr, non con LIKE: nei path ci sono underscore
 * (`/mnt/DISCO_USB`) che in LIKE sono jolly e farebbero corrispondere volumi
 * diversi.
 */
function fmVolumeRecordingCount(string $path): int {
    $prefix = rtrim($path, '/') . '/';
    $stmt = fmDB()->prepare(
        'SELECT COUNT(*) FROM recordings WHERE substr(output_dir, 1, ?) = ?'
    );
    $stmt->execute([strlen($prefix), $prefix]);
    return (int)$stmt->fetchColumn();
}

/** True se la registrazione ha i file su un volume attualmente non raggiungibile. */
function fmRecordingVolumeOffline(array $rec): bool {
    $dir = trim((string)($rec['output_dir'] ?? ''));
    if ($dir === '') return false;
    if (str_starts_with($dir . '/', rtrim(FM_BASE, '/') . '/')) return false;
    $v = fmRecordingVolume($rec);
    // Volume non più in elenco: si guarda direttamente se la cartella c'è.
    return $v ? !$v['online'] : !is_dir($dir);
}

function fmRecCode(int $id, string $mediaType): string {
    return ($mediaType === 'video' ? 'V' : 'A') . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function fmParseRecId($raw): int {
    return (int)preg_replace('/[^0-9]/', '', (string)$raw);
}

/**
 * Crea un marker/cue per una registrazione. $when, se passato, è l'istante
 * (UTC) da usare per calcolare elapsed_seconds al posto di "adesso" — usato
 * da scripts/remote_sync.php per ricreare marker/cue con il timestamp del
 * click sulla pulsantiera remota, indipendentemente da quando il Pi lo
 * processa. $origin distingue i marker creati da Fluxus Remote nella UI.
 */
function fmCreateMarker(int $recordingId, string $type, string $label, ?DateTime $when = null, string $origin = 'local', ?int $elapsedOverride = null): array {
    $db = fmDB();
    $type = $type === 'marker' ? 'marker' : 'cue';
    $label = trim($label);

    $stmt = $db->prepare('SELECT * FROM recordings WHERE id = ?');
    $stmt->execute([$recordingId]);
    $recording = $stmt->fetch();
    if (!$recording) fmError('Registrazione non trovata', 404);

    if ($when !== null) {
        $now = clone $when;
        $now->setTimezone(new DateTimeZone(fmTimezone()));
    } else {
        $now = new DateTime('now', new DateTimeZone(fmTimezone()));
    }

    $start = !empty($recording['start_time'])
        ? new DateTime($recording['start_time'], new DateTimeZone('UTC'))
        : null;
    if ($start) $start->setTimezone(new DateTimeZone(fmTimezone()));

    if ($elapsedOverride !== null) {
        // Posizione indicata a mano (recupero a posteriori su una registrazione
        // conclusa): comanda lei, e l'ora assoluta si ricalcola di conseguenza.
        $elapsed = max(0, $elapsedOverride);
        if ($start) {
            $now = (clone $start)->modify('+' . $elapsed . ' seconds');
        }
    } elseif ($start) {
        $elapsed = max(0, $now->getTimestamp() - $start->getTimestamp());
    } else {
        $elapsed = 0;
    }
    $elapsedHms = fmFormatDuration($elapsed);
    $clipStatus = $type === 'cue' ? 'pending' : 'n/a';

    $stmt = $db->prepare('INSERT INTO markers
        (recording_id, elapsed_seconds, elapsed_hms, absolute_time, label, type, clip_status, origin)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $recordingId, $elapsed, $elapsedHms, $now->format('Y-m-d H:i:s'),
        $label, $type, $clipStatus, $origin,
    ]);
    $markerId = (int)$db->lastInsertId();

    if ($label === '') {
        $label = ($type === 'cue' ? 'cue_' : 'marker_') . $markerId;
        $upd = $db->prepare('UPDATE markers SET label = ? WHERE id = ?');
        $upd->execute([$label, $markerId]);
    }

    if ($type === 'cue') {
        if (!is_dir(FM_TMP)) mkdir(FM_TMP, 0775, true);
        $queueFile = FM_TMP . '/clip_queue.json';
        $fh = fopen($queueFile, 'c+');
        if ($fh) {
            flock($fh, LOCK_EX);
            $contents = stream_get_contents($fh);
            $queue = json_decode($contents, true);
            if (!is_array($queue)) $queue = [];
            $queue[] = [
                'marker_id'    => $markerId,
                'recording_id' => $recordingId,
                'created_at'   => $now->format('Y-m-d H:i:s'),
            ];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($queue, JSON_PRETTY_PRINT));
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    return ['marker_id' => $markerId, 'elapsed_hms' => $elapsedHms];
}

/**
 * Ferma il relay ffmpeg di un'anteprima live, se in esecuzione.
 *
 * ⚠️ I segnali sono numerici di proposito: le costanti SIGTERM/SIGKILL sono
 * definite dall'estensione pcntl, caricata in PHP CLI ma NON in PHP-FPM. Usarle
 * qui produce un fatal "Undefined constant SIGTERM" e una risposta vuota.
 */
function fmKillPreviewRelay(string $pidFile): void
{
    if (!is_file($pidFile)) return;

    $pid = (int)trim((string)@file_get_contents($pidFile));
    if ($pid > 1 && file_exists('/proc/' . $pid)) {
        @posix_kill($pid, 15);
        for ($i = 0; $i < 20 && file_exists('/proc/' . $pid); $i++) usleep(100000);
        if (file_exists('/proc/' . $pid)) @posix_kill($pid, 9);
    }
    @unlink($pidFile);
}

/**
 * Percorso MediaMTX realmente alimentato da una sorgente rtmp-push.
 *
 * L'URL pubblicizzato in UI è rtmp://<ip>:1935/{source_id}, ma diversi encoder
 * (Wirecast fra questi) hanno DUE campi obbligatori — "indirizzo" e "stream" —
 * e li concatenano sempre: il percorso che arriva a MediaMTX diventa
 * "{source_id}/{stream}" e non "{source_id}". Invece di pretendere il nome
 * esatto si chiede a MediaMTX quale percorso sta effettivamente ricevendo,
 * accettando sia "{id}" sia "{id}/<qualunque cosa>".
 *
 * ⚠️ Il confronto sul prefisso include lo slash: senza, la sorgente 21
 * intercetterebbe anche un push sulla sorgente 210.
 *
 * @return array|null La voce della path pronta (name, tracks, ...), oppure null
 *                    se nessuno sta pushando per quella sorgente.
 */
function fmResolvePushPath(int $sourceId): ?array
{
    if ($sourceId <= 0) return null;

    $ch = curl_init('http://127.0.0.1:9997/v3/paths/list?itemsPerPage=1000');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;

    $data = json_decode((string)$res, true);
    if (!is_array($data) || empty($data['items'])) return null;

    $id     = (string)$sourceId;
    $prefix = $id . '/';
    $exact  = null;
    $nested = null;
    foreach ($data['items'] as $item) {
        if (empty($item['ready'])) continue;
        $name = (string)($item['name'] ?? '');
        if ($name === $id) {
            $exact = $item;           // il nome esatto vince sempre
        } elseif ($nested === null && strncmp($name, $prefix, strlen($prefix)) === 0) {
            $nested = $item;
        }
    }

    return $exact ?? $nested;
}

/**
 * URL RTMP da dare a ffmpeg per una sorgente rtmp-push.
 * Se nessuno sta pushando si torna al nome canonico: sarà ffmpeg a fallire e,
 * in record.sh, il ciclo supervisore a riprovare più tardi.
 */
function fmPushInputUrl(int $sourceId): string
{
    $path = fmResolvePushPath($sourceId);
    $name = $path['name'] ?? (string)$sourceId;

    return 'rtmp://127.0.0.1:1935/' . $name;
}
