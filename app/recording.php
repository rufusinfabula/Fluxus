<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$id = fmParseRecId($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Registrazione non trovata'); }

$stmt = $db->prepare('SELECT r.*, s.name AS current_source_name FROM recordings r
    LEFT JOIN sources s ON s.id = r.source_id WHERE r.id = ?');
$stmt->execute([$id]);
$recording = $stmt->fetch();
if (!$recording) { http_response_code(404); exit('Registrazione non trovata'); }

$stmt = $db->prepare('SELECT * FROM markers WHERE recording_id = ? ORDER BY elapsed_seconds ASC');
$stmt->execute([$id]);
$markers = $stmt->fetchAll();

if (isset($_GET['partial']) && $_GET['partial'] === 'markers') {
    include __DIR__ . '/includes/markers_table.php';
    exit;
}

$isVideo = $recording['media_type'] === 'video';
$isClock = $recording['media_type'] === 'clock';
$ext = $isVideo ? '.mp4' : '.mp3';

// Multi-file o file singolo si decide da cosa c'è su disco, non da
// segment_duration: dal 2026-07-27 anche una registrazione non segmentata può
// avere più file _NNN, se il supervisore di record.sh l'ha ripresa dopo una
// caduta dello stream. Se non c'è ancora nulla (registrazione appena avviata)
// si ricade sulla configurazione dello schedule.
$partsPattern = $recording['output_dir'] . '/' . $recording['filename_base'] . '_[0-9][0-9][0-9]' . $ext;
$partsOnDisk = glob($partsPattern) ?: [];
$isSegmented = $partsOnDisk
    ? true
    : ((int)$recording['segment_duration'] > 0
        && !is_file($recording['output_dir'] . '/' . $recording['filename_base'] . $ext));
$webBase = rtrim(FM_WEB_BASE, '/');
// I media possono stare su un volume esterno: l'URL passa dal symlink del volume
// (vedi fmMediaBaseUrl). Gli export marker restano invece sempre sul volume interno.
$mediaBaseUrl   = fmMediaBaseUrl($recording);
$markersBaseUrl = $webBase . '/recordings/' . $recording['source_id'] . '/';

// Volume scollegato: la pagina si apre lo stesso (marker, durata, note e tutto il
// resto stanno nel DB), ma i player non hanno file da leggere e va detto quale
// disco manca.
$recVolume        = fmRecordingVolume($recording);
$recVolumeOffline = fmRecordingVolumeOffline($recording);

$segmentFiles = [];
if ($isSegmented) {
    foreach ($partsOnDisk as $f) {
        $segmentFiles[] = basename($f);
    }
    sort($segmentFiles);
    // ffmpeg apre il file del segmento appena inizia a scriverlo: mentre la
    // registrazione è attiva l'ultimo file della lista è ancora in scrittura
    // (dimensione/durata mostrate sarebbero fuorvianti) e va escluso.
    if ($recording['status'] === 'recording' && !empty($segmentFiles)) {
        array_pop($segmentFiles);
    }
} else {
    $singleFile = $recording['filename_base'] . $ext;
    if (!is_file($recording['output_dir'] . '/' . $singleFile)) {
        $singleFile = null;
    }
}

$markersFiles = fmGenerateMarkersFiles($id);
$markersUrls = [];
foreach ($markersFiles as $fmt => $path) {
    $markersUrls[$fmt] = $markersBaseUrl . rawurlencode(basename($path));
}

$statusMeta = [
    'completed' => ['Completata', '#32d296'],
    'failed'    => ['Fallita',    '#f0506e'],
    'recording' => ['In corso',  '#f0506e'],
];
[$statusLabel, $statusColor] = $statusMeta[$recording['status']] ?? [$recording['status'], '#999'];
$isRecording = $recording['status'] === 'recording';
$triggerLabel = $recording['trigger_type'] === 'scheduled' ? 'Programmata' : 'Manuale';
$markerTotal = count($markers);

$recCode = fmRecCode($id, $recording['media_type']);
$pageTitle = $recCode . ' — ' . $recording['filename_base'];
include __DIR__ . '/includes/head.php';
?>

<ul class="uk-breadcrumb uk-margin-small-bottom">
    <li><a href="<?= $webBase ?>/recordings.php">Registrazioni</a></li>
    <li><span><?= fmH($recCode) ?></span></li>
</ul>

<?php if ($recVolumeOffline): ?>
<div class="uk-alert-warning" uk-alert>
    <span uk-icon="icon: warning"></span>
    <strong>Volume non collegato<?= $recVolume ? ' — ' . fmH($recVolume['label']) : '' ?>.</strong>
    I file audio/video e i cue di questa registrazione si trovano su
    <span class="fm-mono"><?= fmH($recording['output_dir']) ?></span> e non sono raggiungibili
    finché il disco non viene ricollegato. Marker, orari e informazioni restano disponibili qui sotto.
</div>
<?php endif; ?>

<div class="uk-grid-medium" uk-grid>
<div class="uk-width-2-3@m">
    <div class="uk-card uk-card-default uk-card-body fm-card<?= $isRecording ? ' fm-card-active' : '' ?>">
        <div class="uk-flex uk-flex-middle uk-flex-between" style="flex-wrap:wrap;gap:10px;">
            <h2 class="uk-margin-remove fm-mono" style="font-size:20px;"><?= fmH($recCode) ?> <span class="uk-text-meta" style="font-size:14px;"><?= fmH($recording['filename_base']) ?></span></h2>
            <?php if ($isRecording): ?>
            <span class="uk-badge fm-badge-live fm-pulse"><span class="fm-rec-dot" style="background:#fff;"></span>REC</span>
            <?php else: ?>
            <span class="fm-status-pill" style="background:<?= $statusColor ?>;"><?= fmH($statusLabel) ?></span>
            <?php endif; ?>
        </div>

        <div class="uk-margin-small-top">
            <div class="fm-dl-row">
                <div class="fm-dl-label">Sorgente</div>
                <div class="fm-dl-value">
                    <strong><?= fmH($recording['current_source_name'] ?? $recording['source_name']) ?></strong>
                    <span style="margin-left:6px;"><?= fmMediaTypeBadge($recording['media_type'], true) ?></span>
                    <?php if (!empty($recording['clock_origin'])): ?>
                    <span class="uk-badge" style="background:#fdf1e0;color:#b45309;font-size:9px;margin-left:4px;"
                          uk-tooltip="Audio caricato a posteriori da un registratore CLOCK esterno">da CLOCK</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Inizio</div>
                <div class="fm-dl-value"><?= fmH(fmFormatDateTime($recording['start_time'])) ?></div>
            </div>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Fine</div>
                <div class="fm-dl-value"><?= fmH(fmFormatDateTime($recording['end_time'])) ?></div>
            </div>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Durata</div>
                <div class="fm-dl-value fm-mono" id="fm-live-dur"><?= $isRecording ? '00:00:00' : fmH(fmFormatDuration($recording['duration_seconds'])) ?></div>
            </div>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Tipo</div>
                <div class="fm-dl-value"><?= fmH($triggerLabel) ?></div>
            </div>
            <?php if ($isSegmented):
                // Mentre la registrazione è attiva $segmentFiles esclude di proposito il
                // segmento in scrittura (vedi sopra): qui va mostrato il piano previsto
                // (slot / durata segmento), non il numero di file già chiusi su disco.
                $segmentCountDisplay = $isRecording
                    ? (int)ceil($recording['slot_duration'] / $recording['segment_duration'])
                    : count($segmentFiles);
            ?>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Segmenti</div>
                <div class="fm-dl-value fm-mono"><?= $segmentCountDisplay ?> &times; <?= fmH(fmFormatDuration($recording['segment_duration'])) ?></div>
            </div>
            <?php endif; ?>
            <div class="fm-dl-row">
                <div class="fm-dl-label">Marker</div>
                <div class="fm-dl-value"><?= $markerTotal ?></div>
            </div>
        </div>

        <?php if ($isRecording): ?>
        <div class="uk-margin-top" style="padding-top:14px;border-top:1px solid #f2f2f2;">
            <div class="uk-flex" style="gap:8px;flex-wrap:wrap;">
                <button class="uk-button uk-button-primary uk-button-small fm-on-accent" id="fm-btn-marker">Marker <kbd>M</kbd></button>
                <?php if ($isClock): ?>
                <button class="uk-button uk-button-small fm-on-accent" style="background:#1a1a1a;border-color:#444;color:#777;" disabled uk-tooltip="Non disponibile per sorgenti CLOCK"><span uk-icon="icon: nut; ratio: 0.8"></span> Cue <kbd>C</kbd></button>
                <?php else: ?>
                <button class="uk-button uk-button-small fm-btn-cue fm-on-accent" style="background:#1a1a1a;border-color:#444;color:#e0e0e0;" id="fm-btn-cue"><span uk-icon="icon: nut; ratio: 0.8"></span> Cue <kbd>C</kbd></button>
                <?php endif; ?>
                <button class="uk-button uk-button-danger uk-button-small" id="fm-btn-stop"><span class="fm-stop-dot-btn"></span>STOP</button>
                <?php if (!$isClock): ?>
                <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-btn-preview"
                        data-source-id="<?= (int)$recording['source_id'] ?>"
                        data-source-name="<?= fmH($recording['current_source_name'] ?? $recording['source_name']) ?>"
                        data-media-type="<?= fmH($recording['media_type']) ?>">
                    <span uk-icon="icon: <?= $isVideo ? 'video-camera' : 'microphone' ?>; ratio: 0.8"></span> Anteprima
                </button>
                <?php endif; ?>
            </div>
            <?php if (!$isClock): ?>
            <div class="uk-text-meta" style="font-size:11px;margin-top:6px;">
                L'anteprima apre un flusso separato verso la sorgente: mostra cosa sta entrando, non tocca la registrazione in corso.
            </div>
            <?php endif; ?>
            <?php if (!$isClock): ?>
            <div class="uk-margin-small-top">
                <div class="fm-progress-row">
                    <span class="fm-progress-elapsed fm-mono" id="fm-progress-elapsed">00:00:00</span>
                    <span class="fm-progress-filename">obiettivo <?= fmH(fmFormatDuration($recording['slot_duration'])) ?></span>
                </div>
                <progress class="uk-progress fm-progress" id="fm-progress-bar" value="0" max="<?= (int)$recording['slot_duration'] ?: 1 ?>" style="height:4px;margin:4px 0 2px;"></progress>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="uk-width-1-3@m">
    <div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
        <h3 class="uk-margin-remove-bottom uk-flex uk-flex-middle" style="gap:6px;font-size:18px;font-weight:600;">
            <span uk-icon="icon: download; ratio: 1"></span> Download
        </h3>
        <div class="uk-margin-small-top">
            <a class="fm-download-row uk-link-reset" style="display:flex;" href="<?= fmH($markersUrls['csv']) ?>" download>
                <span class="fm-dl-icon" uk-icon="icon: list; ratio: 1.1" style="color:#32d296;"></span>
                <div><div class="fm-download-title"><strong>CSV</strong> marker</div><div class="fm-download-desc">Excel, Numbers</div></div>
            </a>
            <a class="fm-download-row uk-link-reset" style="display:flex;" href="<?= fmH($markersUrls['txt']) ?>" download>
                <span class="fm-dl-icon" uk-icon="icon: file-edit; ratio: 1.1" style="color:#666;"></span>
                <div><div class="fm-download-title"><strong>TXT</strong> marker</div><div class="fm-download-desc">Leggibile, note</div></div>
            </a>
            <a class="fm-download-row uk-link-reset" style="display:flex;" href="<?= fmH($markersUrls['json']) ?>" download>
                <span class="fm-dl-icon" uk-icon="icon: code; ratio: 1.1" style="color:#f0506e;"></span>
                <div><div class="fm-download-title"><strong>JSON</strong> marker</div><div class="fm-download-desc">Strutturato, con clip</div></div>
            </a>
        </div>
    </div>

<?php /* La zona pericolosa non sta più qui in colonna: è in fondo alla pagina,
         dopo l'elenco marker — vedi in fondo al file. */ ?>
</div>
</div>

<?php
/* Rete di sicurezza: i media sono registrati per intero a prescindere dai
   marker, quindi un marker/cue perso (sessione scaduta, browser chiuso,
   operatore distratto) si può sempre ricostruire riascoltando. La barra vive
   in fondo alla card dei file — è lì che si riascolta, e attaccata costa una
   riga invece di una card intera. */
$posthocBox = '';
if (!$isRecording) {
    ob_start(); ?>
    <div class="fm-posthoc-bar">
        <span class="fm-posthoc-title">
            Marker/cue a posteriori
            <span class="fm-posthoc-help" uk-icon="icon: info; ratio: 0.7"
                  uk-tooltip="title: La registrazione è integrale: riascoltandola qui sopra puoi recuperare un marker o un cue non salvato, indicando l'istante in cui cade.<?= !empty($recording['duration_seconds']) ? ' Durata: ' . fmH(fmFormatDuration((int)$recording['duration_seconds'])) . '.' : '' ?> Per i cue la clip viene estratta in automatico entro un paio di minuti.; pos: top-left"></span>
        </span>
        <select class="uk-select uk-form-small" id="fm-posthoc-type" style="width:92px;">
            <option value="marker">Marker</option>
            <?php if (!$isClock): ?>
            <option value="cue">Cue</option>
            <?php endif; ?>
        </select>
        <input class="uk-input uk-form-small fm-mono" type="text" id="fm-posthoc-at"
               placeholder="h:mm:ss" style="width:96px;" autocomplete="off">
        <input class="uk-input uk-form-small fm-posthoc-label" type="text" id="fm-posthoc-label"
               placeholder="Etichetta (opzionale)" maxlength="120" autocomplete="off">
        <button class="uk-button uk-button-primary uk-button-small" id="fm-posthoc-btn" type="button">Aggiungi</button>
        <span class="uk-text-small" id="fm-posthoc-msg" style="display:none;"></span>
    </div>
    <?php $posthocBox = ob_get_clean();
}
?>

<?php if ($isVideo):
    $videoRows = [];
    if ($isSegmented) {
        foreach ($segmentFiles as $i => $sf) { $videoRows[] = ['file' => $sf, 'idx' => $i + 1]; }
    } elseif (!empty($singleFile)) {
        $videoRows[] = ['file' => $singleFile, 'idx' => null];
    }
?>
    <div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
        <h3 class="fm-section-title uk-margin-small-bottom">
            <span uk-icon="icon: video-camera; ratio: 0.9"></span> Video
        </h3>
        <?php if ($isSegmented): ?>
            <p class="uk-text-meta">Registrazione segmentata — <?= count($segmentFiles) ?> segmenti.<?= $isRecording ? ' Il segmento in scrittura non è mostrato finché non viene completato.' : '' ?></p>
        <?php endif; ?>
        <?php if ($videoRows):
            $vRecStartUtc = null;
            try { $vRecStartUtc = new DateTime($recording['start_time'], new DateTimeZone('UTC')); } catch (Exception $e) {}
            // Colonne della tabella: serve al colspan della riga-player inline.
            $vColspan = $isSegmented ? 10 : 9;
        ?>
        <div class="uk-overflow-auto">
        <table class="uk-table uk-table-small uk-table-middle fm-table">
            <thead>
                <tr>
                    <?php if ($isSegmented): ?><th style="width:36px;">#</th><?php endif; ?>
                    <th>File</th>
                    <th style="width:100px;">Data</th>
                    <th style="width:90px;">Inizio</th>
                    <th style="width:90px;">Fine</th>
                    <th style="width:90px;">Durata</th>
                    <th style="width:100px;">Risoluzione</th>
                    <th style="width:60px;">FPS</th>
                    <th style="width:90px;">Dimensione</th>
                    <th style="width:64px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($videoRows as $vRowIdx => $vr):
                $vPath = $recording['output_dir'] . '/' . $vr['file'];
                $vUrl = $mediaBaseUrl . rawurlencode($vr['file']);
                $vSize = @filesize($vPath);
                $vInfo = fmProbeVideoFile($vPath);

                if ($vr['idx'] !== null) {
                    $segLen = (int)$recording['segment_duration'];
                    $segStartSec = ($vr['idx'] - 1) * $segLen;
                    $segEndSec = $segStartSec + (int)round($vInfo['duration'] ?? $segLen);
                    $vStartDt = $vRecStartUtc ? (clone $vRecStartUtc)->modify("+{$segStartSec} seconds") : null;
                    $vEndDt = $vRecStartUtc ? (clone $vRecStartUtc)->modify("+{$segEndSec} seconds") : null;
                } else {
                    $vStartDt = $vRecStartUtc;
                    $vEndDt = null;
                    if (!empty($recording['end_time'])) {
                        try { $vEndDt = new DateTime($recording['end_time'], new DateTimeZone('UTC')); } catch (Exception $e) {}
                    }
                }
                if ($vStartDt) $vStartDt->setTimezone(new DateTimeZone(fmTimezone()));
                if ($vEndDt) $vEndDt->setTimezone(new DateTimeZone(fmTimezone()));

                $vDurLabel = !empty($vInfo['duration']) ? fmFormatDuration((int)round($vInfo['duration'])) : '—';
                $vResLabel = (!empty($vInfo['width']) && !empty($vInfo['height'])) ? $vInfo['width'] . '×' . $vInfo['height'] : '—';
                $vFpsLabel = !empty($vInfo['fps']) ? rtrim(rtrim(number_format($vInfo['fps'], 2), '0'), '.') : '—';
            ?>
                <tr class="fm-row-clickable fm-video-file-row" data-target="fm-vplayer-<?= $vRowIdx ?>" data-name="<?= fmH($vr['file']) ?>">
                    <?php if ($vr['idx'] !== null): ?><td class="fm-idx"><?= $vr['idx'] ?></td><?php endif; ?>
                    <td class="fm-filename"><?= fmH($vr['file']) ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vStartDt ? fmH($vStartDt->format('d/m/Y')) : '—' ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vStartDt ? fmH($vStartDt->format('H:i:s')) : '—' ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vEndDt ? fmH($vEndDt->format('H:i:s')) : '—' ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vDurLabel ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vResLabel ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= $vFpsLabel ?></td>
                    <td class="fm-mono" style="font-size:12px;"><?= fmH(fmFormatBytes($vSize ?: 0)) ?></td>
                    <td>
                        <div class="uk-flex uk-flex-middle" style="gap:2px;">
                            <span class="fm-action-icon fm-video-play-icon" uk-tooltip="Anteprima"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="6,4 20,12 6,20"></polygon></svg></span>
                            <a href="<?= fmH($vUrl) ?>" class="fm-action-icon fm-video-dl-btn" uk-icon="icon: download; ratio: 0.8" uk-tooltip="Scarica" download></a>
                        </div>
                    </td>
                </tr>
                <!-- Anteprima inline: si apre subito sotto la riga del segmento,
                     nessun modale. src assegnata solo alla prima apertura. -->
                <tr class="fm-video-player-row" id="fm-vplayer-<?= $vRowIdx ?>" hidden>
                    <td colspan="<?= $vColspan ?>">
                        <div class="fm-video-inline">
                            <div class="fm-video-inline-head">
                                <span class="fm-mono fm-video-inline-name"><?= fmH($vr['file']) ?></span>
                                <button type="button" class="fm-video-inline-close" uk-tooltip="Chiudi anteprima" aria-label="Chiudi anteprima">
                                    <span uk-icon="icon: close; ratio: 0.8"></span>
                                </button>
                            </div>
                            <video preload="none" controls playsinline data-src="<?= fmH($vUrl) ?>"></video>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php elseif ($recVolumeOffline): ?>
            <p class="uk-text-warning">
                <span uk-icon="icon: warning; ratio: 0.8"></span>
                I file sono sul volume <strong><?= fmH($recVolume['label'] ?? 'esterno') ?></strong>, attualmente non collegato.
            </p>
        <?php else: ?>
            <p class="uk-text-meta"><?= $isRecording ? 'Primo segmento in scrittura, sarà disponibile a breve.' : 'File video non ancora disponibile.' ?></p>
        <?php endif; ?>
        <?= $posthocBox ?>
    </div>

    <script>
    // Anteprima video inline: il player si apre nella riga subito sotto quella
    // del segmento cliccato, non in un modale. Un solo player aperto per volta.
    (function () {
        function closeRow(row) {
            var target = document.getElementById(row.getAttribute('data-target'));
            if (!target || target.hidden) return;
            var player = target.querySelector('video');
            if (player) player.pause();
            target.hidden = true;
            row.classList.remove('fm-video-row-open');
        }

        var rows = Array.prototype.slice.call(document.querySelectorAll('.fm-video-file-row'));

        rows.forEach(function (row) {
            var target = document.getElementById(row.getAttribute('data-target'));
            if (!target) return;
            var player = target.querySelector('video');

            row.addEventListener('click', function (e) {
                // Il download è un'azione a sé: non deve aprire l'anteprima.
                if (e.target.closest('.fm-video-dl-btn')) return;

                if (!target.hidden) { closeRow(row); return; }   // secondo click = chiudi

                rows.forEach(function (other) { if (other !== row) closeRow(other); });

                // src assegnata solo alla prima apertura (preload="none"): così
                // aprire la pagina non scarica nulla, e riaprire non riparte da capo.
                if (!player.getAttribute('src')) player.src = player.getAttribute('data-src');
                target.hidden = false;
                row.classList.add('fm-video-row-open');
                var p = player.play();
                if (p && p.catch) p.catch(function () {});
            });

            target.querySelector('.fm-video-inline-close').addEventListener('click', function (e) {
                e.stopPropagation();
                closeRow(row);
            });
        });
    })();
    </script>
<?php elseif ($isClock): ?>
    <div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
        <h3 class="fm-section-title uk-margin-small-bottom">
            <span uk-icon="icon: clock; ratio: 0.9"></span> CLOCK
        </h3>
        <p class="uk-text-meta">
            Sorgente CLOCK — nessun file audio/video. I marker qui sotto sono
            l'unico contenuto di questa registrazione.
        </p>
        <?php if (!$isRecording): ?>
        <div class="uk-margin-top" style="border-top:1px solid #f2f2f2;padding-top:12px;">
            <div class="uk-text-meta" style="font-size:12px;margin-bottom:8px;">
                Puoi collegare a questa sessione l'audio registrato nel frattempo da un
                dispositivo esterno: i marker verranno posizionati rispetto a questo file.
                Un solo caricamento per registrazione — non è prevista sostituzione.
            </div>
            <div class="uk-flex uk-flex-middle" style="gap:8px;flex-wrap:wrap;">
                <input type="file" id="fm-clock-file" accept="audio/*" class="uk-input uk-form-small" style="max-width:240px;">
                <input type="datetime-local" id="fm-clock-start" class="uk-input uk-form-small fm-mono" style="width:200px;" step="1">
                <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-clock-upload-btn">Carica e collega audio</button>
                <span uk-spinner="ratio: 0.6" id="fm-clock-upload-spinner" style="display:none;"></span>
            </div>
            <div class="uk-text-small" id="fm-clock-upload-msg" style="margin-top:6px;display:none;"></div>
        </div>
        <?php endif; ?>
        <?= $posthocBox ?>
    </div>
<?php else: ?>
    <div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
        <?php if (!$isSegmented && !empty($singleFile)): ?>
            <h3 class="fm-section-title uk-margin-small-bottom">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>
                Audio
            </h3>
            <audio controls preload="none" style="width:100%;" src="<?= fmH($mediaBaseUrl . rawurlencode($singleFile)) ?>"></audio>
        <?php elseif ($isSegmented && $segmentFiles): ?>
            <h3 class="uk-margin-remove-bottom uk-flex uk-flex-middle" style="gap:8px;font-size:22px;font-weight:600;color:#333;">
                <span uk-icon="icon: list; ratio: 1.1"></span> Segmenti (<?= count($segmentFiles) ?>)
            </h3>
            <?php if ($isRecording): ?>
            <p class="uk-text-meta" style="font-size:11px;">Il segmento in scrittura non è mostrato finché non viene completato.</p>
            <?php endif; ?>
            <div class="uk-margin-small-top">
            <?php foreach ($segmentFiles as $i => $sf):
                $segPath = $recording['output_dir'] . '/' . $sf;
                $segUrl = $mediaBaseUrl . rawurlencode($sf);
                $segSize = @filesize($segPath);
                // Durata reale del file: con le riprese dopo una caduta i segmenti
                // non durano tutti quanto `segment_duration` (vedi docs/NOTE-TECNICHE.md).
                $segDur = fmProbeDuration($segPath);
            ?>
                <div class="fm-segplayer-row">
                    <span class="fm-segplayer-idx"><?= $i + 1 ?></span>
                    <audio controls preload="none" class="fm-inline-audio" style="flex:1;min-width:0;" src="<?= fmH($segUrl) ?>"></audio>
                    <div class="fm-segplayer-actions">
                        <a href="<?= fmH($segUrl) ?>" class="fm-action-icon" uk-icon="icon: download; ratio: 0.8" uk-tooltip="Scarica" download></a>
                    </div>
                    <span class="fm-segplayer-dur" uk-tooltip="Durata del segmento"><?= $segDur ? fmH(fmFormatDuration((int)round($segDur))) : '—' ?></span>
                    <span class="fm-segplayer-size"><?= fmH(fmFormatBytes($segSize ?: 0)) ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php elseif ($recVolumeOffline): ?>
            <p class="uk-text-warning">
                <span uk-icon="icon: warning; ratio: 0.8"></span>
                I file sono sul volume <strong><?= fmH($recVolume['label'] ?? 'esterno') ?></strong>, attualmente non collegato.
            </p>
        <?php else: ?>
            <p class="uk-text-meta"><?= $isRecording ? 'Primo segmento in scrittura, sarà disponibile a breve.' : 'File audio non ancora disponibile.' ?></p>
        <?php endif; ?>
        <?= $posthocBox ?>
    </div>
    <?php /* Estrazione clip manuale e "Clip estratte manualmente" rimosse: TRIM/EDIT manuale in quarantena, vedi docs/NOTE-TECNICHE.md. */ ?>
<?php endif; ?>

<div id="fm-markers-wrap">
<?php include __DIR__ . '/includes/markers_table.php'; ?>
</div>

<?php if (!$isRecording): ?>
<?php /* In fondo alla pagina, dopo l'elenco marker: è un'azione irreversibile e
         non deve stare accanto a ciò che si consulta di continuo. */ ?>
<div class="uk-card uk-card-default uk-card-body fm-card fm-danger-zone uk-margin-top" style="border-left:3px solid #f0506e;">
    <h3 class="fm-danger-zone-title"><span uk-icon="icon: warning"></span> Zona pericolosa</h3>
    <p class="uk-text-meta uk-margin-small-bottom">
        Elimina la registrazione<?= $isClock ? '' : ', i file ' . ($isVideo ? 'MP4' : 'MP3') ?> e tutti i marker<?= $isClock ? '' : ' e clip' ?> associati.
    </p>
    <button class="uk-button uk-button-danger" id="fm-btn-delete-recording">
        <span uk-icon="icon: trash; ratio: 0.8"></span> Elimina registrazione
    </button>
</div>
<?php endif; ?>

<script>
(function () {
    var base = <?= json_encode($webBase) ?>;
    var recordingId = <?= (int)$id ?>;
    var isRecording = <?= $isRecording ? 'true' : 'false' ?>;
    var isClock = <?= $isClock ? 'true' : 'false' ?>;
    var startTime = <?= json_encode($recording['start_time']) ?>;
    var slotDuration = <?= (int)$recording['slot_duration'] ?>;

    function fmtDur(sec) {
        sec = Math.max(0, sec | 0);
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        function p(n) { return n.toString().padStart(2, '0'); }
        return p(h) + ':' + p(m) + ':' + p(s);
    }

    if (isRecording && startTime) {
        var startMs = new Date(startTime.replace(' ', 'T') + 'Z').getTime();
        setInterval(function () {
            var elapsed = (Date.now() - startMs) / 1000;
            var el = document.getElementById('fm-live-dur');
            if (el) el.textContent = fmtDur(elapsed);
            var elProg = document.getElementById('fm-progress-elapsed');
            if (elProg) elProg.textContent = fmtDur(elapsed);
            var bar = document.getElementById('fm-progress-bar');
            if (bar && slotDuration > 0) bar.value = Math.min(slotDuration, elapsed);
        }, 1000);
    }

    var mBtn = document.getElementById('fm-btn-marker');
    var cBtn = document.getElementById('fm-btn-cue');
    var sBtn = document.getElementById('fm-btn-stop');
    if (mBtn) mBtn.addEventListener('click', function () { fmOpenMarkerModal(recordingId, 'marker'); });
    if (cBtn) cBtn.addEventListener('click', function () { fmOpenMarkerModal(recordingId, 'cue'); });
    if (sBtn) sBtn.addEventListener('click', function () {
        if (!confirm('Fermare questa registrazione?')) return;
        fetch(base + '/api/stop.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ recording_id: recordingId })
        }).then(function () { setTimeout(function () { location.reload(); }, 1000); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (!isRecording) return;
        if (e.key === 'm' || e.key === 'M') fmOpenMarkerModal(recordingId, 'marker');
        if ((e.key === 'c' || e.key === 'C') && !isClock) fmOpenMarkerModal(recordingId, 'cue');
    });

    function refreshMarkersTable() {
        fetch(base + '/recording.php?id=' + recordingId + '&partial=markers')
            .then(function (r) { return r.text(); })
            .then(function (html) { document.getElementById('fm-markers-wrap').innerHTML = html; });
    }
    window.fmOnMarkerSaved = refreshMarkersTable;
    if (isRecording) {
        setInterval(refreshMarkersTable, 8000);
        // Sessione tenuta viva finché questa pagina resta aperta su una
        // registrazione in corso (vedi includes/session_modal.php).
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof window.fmStartKeepAlive === 'function') window.fmStartKeepAlive(300000);
        });
    }

    // --- Marker/cue a posteriori su una registrazione conclusa ---------------
    var posthocBtn = document.getElementById('fm-posthoc-btn');
    if (posthocBtn) {
        // Accetta "1:02:03", "2:03" e "123": chi recupera un marker legge
        // l'istante dal player, che lo mostra in una di queste forme.
        function parseAt(raw) {
            var t = (raw || '').trim();
            if (t === '') return null;
            if (!/^\d+(:\d{1,2}){0,2}$/.test(t)) return null;
            var parts = t.split(':').map(Number);
            var sec = 0;
            for (var i = 0; i < parts.length; i++) sec = sec * 60 + parts[i];
            return sec;
        }

        function posthocMsg(text, ok) {
            var box = document.getElementById('fm-posthoc-msg');
            box.style.display = '';
            box.style.color = ok ? '#32d296' : '#f0506e';
            box.textContent = text;
        }

        posthocBtn.addEventListener('click', function () {
            var at = parseAt(document.getElementById('fm-posthoc-at').value);
            if (at === null) {
                posthocMsg('Istante non valido: usa h:mm:ss, mm:ss oppure i secondi.', false);
                return;
            }
            var type = document.getElementById('fm-posthoc-type').value;
            var label = document.getElementById('fm-posthoc-label').value.trim();
            posthocBtn.disabled = true;
            posthocMsg('Salvataggio…', true);

            window.fmApiPost('/api/marker.php', {
                recording_id: recordingId,
                type: type,
                label: label,
                elapsed_seconds: at
            }).then(function (d) {
                posthocBtn.disabled = false;
                document.getElementById('fm-posthoc-at').value = '';
                document.getElementById('fm-posthoc-label').value = '';
                posthocMsg((type === 'cue' ? 'Cue' : 'Marker') + ' aggiunto a ' + d.elapsed_hms
                    + (type === 'cue' ? ' — la clip verrà estratta entro un paio di minuti.' : '.'), true);
                refreshMarkersTable();
            }).catch(function (err) {
                posthocBtn.disabled = false;
                if (err && err.kind === 'session') {
                    window.fmSessionExpired('Il ' + type + ' a ' + at + 's NON è stato salvato.', null);
                    return;
                }
                posthocMsg('NON salvato — ' + (err && err.message ? err.message : 'errore sconosciuto'), false);
            });
        });
    }

    // --- CLOCK: upload audio a posteriori -------------------------------------
    var clockFileInput = document.getElementById('fm-clock-file');
    var clockStartInput = document.getElementById('fm-clock-start');
    if (clockFileInput && clockStartInput) {
        // Precompilazione lato client SENZA alcuna richiesta di rete: mtime del
        // file scelto meno la durata letta al volo. Resta sempre modificabile a
        // mano, e il server ricalcola comunque la durata reale via ffprobe — non
        // ci si fida mai di questo suggerimento per la logica di posizionamento.
        clockFileInput.addEventListener('change', function () {
            var file = clockFileInput.files[0];
            if (!file) return;
            var probe = document.createElement('audio');
            probe.preload = 'metadata';
            var url = URL.createObjectURL(file);
            probe.src = url;
            probe.addEventListener('loadedmetadata', function () {
                var durSec = probe.duration || 0;
                var startMs = file.lastModified - durSec * 1000;
                if (startMs > 0 && isFinite(startMs)) {
                    var d = new Date(startMs);
                    function pad(n) { return String(n).padStart(2, '0'); }
                    clockStartInput.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                        + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
                }
                URL.revokeObjectURL(url);
            });
        });
    }

    var clockUploadBtn = document.getElementById('fm-clock-upload-btn');
    if (clockUploadBtn) {
        clockUploadBtn.addEventListener('click', function () {
            var file = clockFileInput.files[0];
            var msgEl = document.getElementById('fm-clock-upload-msg');
            var spinner = document.getElementById('fm-clock-upload-spinner');

            function showMsg(text, ok) {
                msgEl.style.display = '';
                msgEl.style.color = ok ? '#32d296' : '#f0506e';
                msgEl.textContent = text;
            }

            if (!file) { showMsg('Seleziona un file audio.', false); return; }
            if (!clockStartInput.value) { showMsg("Indica l'orario di inizio.", false); return; }
            if (!confirm('Caricare questo file come contenuto della sessione CLOCK? Non sarà possibile sostituirlo in seguito.')) return;

            var fd = new FormData();
            fd.append('recording_id', recordingId);
            fd.append('start_time', clockStartInput.value);
            fd.append('audio', file);

            clockUploadBtn.disabled = true;
            spinner.style.display = '';
            msgEl.style.display = 'none';

            fetch(base + '/api/clock_upload.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    spinner.style.display = 'none';
                    if (d.ok) {
                        showMsg('Audio collegato — durata ' + (d.duration_hms || '') + '. Ricarico…', true);
                        setTimeout(function () { location.reload(); }, 1200);
                    } else {
                        clockUploadBtn.disabled = false;
                        showMsg(d.error || 'Errore durante il caricamento', false);
                    }
                }).catch(function () {
                    spinner.style.display = 'none';
                    clockUploadBtn.disabled = false;
                    showMsg('Errore di rete', false);
                });
        });
    }

    var delBtn = document.getElementById('fm-btn-delete-recording');
    if (delBtn) delBtn.addEventListener('click', function () {
        if (!confirm('Eliminare definitivamente questa registrazione, i file e le clip associate?')) return;
        delBtn.disabled = true;
        fetch(base + '/api/recordings.php?id=' + recordingId, { method: 'DELETE' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    location.href = base + '/recordings.php';
                } else {
                    delBtn.disabled = false;
                    alert(d.error || 'Errore eliminazione');
                }
            }).catch(function () { delBtn.disabled = false; alert('Errore di rete'); });
    });
})();
</script>

<?php /* Script WaveSurfer + estrazione manuale rimossi: TRIM/EDIT manuale in quarantena, vedi docs/NOTE-TECNICHE.md. */ ?>

<?php if ($isRecording && !$isClock): ?>
<?php include __DIR__ . '/includes/preview_modal.php'; ?>
<script>
document.getElementById('fm-btn-preview').addEventListener('click', function () {
    window.fmOpenPreview(
        this.getAttribute('data-source-id'),
        this.getAttribute('data-source-name'),
        this.getAttribute('data-media-type')
    );
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/session_modal.php'; ?>
<?php include __DIR__ . '/includes/marker_modal.php'; ?>
<?php include __DIR__ . '/includes/foot.php'; ?>
