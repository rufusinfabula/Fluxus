<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM sources WHERE id = ?')->execute([$id]);
        header('Location: sources.php');
        exit;
    }

    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM sources WHERE id = ?');
        $stmt->execute([$id]);
        $src = $stmt->fetch();
        if ($src) {
            unset($src['id'], $src['created_at']);
            $src['name'] = $src['name'] . ' (copia)';
            $cols = array_keys($src);
            $stmt = $db->prepare('INSERT INTO sources (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $stmt->execute(array_values($src));
        }
        header('Location: sources.php');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $filePrefix = trim($_POST['file_prefix'] ?? '');
    $mediaType = in_array($_POST['media_type'] ?? 'audio', ['audio', 'video', 'clock'], true)
        ? $_POST['media_type'] : 'audio';
    $type = $_POST['type'] ?? 'http';
    $url = trim($_POST['url'] ?? '');
    $device = trim($_POST['device'] ?? '');
    $audioQuality = $_POST['audio_quality'] ?? '2';
    $resolution = $_POST['resolution'] ?? '1920x1080';
    $fps = (int)($_POST['fps'] ?? 25);
    $videoQuality = $_POST['video_quality'] ?? 'copy';
    // 'hd' mancava in questa whitelist dalla 2.4.4: il profilo era selezionabile
    // nel modale ma veniva salvato come 'copy'.
    if (!in_array($videoQuality, ['copy', 'hd', 'alta', 'media', 'bassa'], true)) $videoQuality = 'copy';
    $extraOpts = trim($_POST['extra_opts'] ?? '');
    $active = !empty($_POST['active']) ? 1 : 0;
    $maxRecordings = (int)($_POST['max_recordings'] ?? 30);
    $maxDaysRecordings = (int)($_POST['max_days_recordings'] ?? 45);
    $maxClipsPerMarker = (int)($_POST['max_clips_per_marker'] ?? 100);
    $maxDaysClips = (int)($_POST['max_days_clips'] ?? 20);
    // Volume di archiviazione: 0/vuoto = usa il predefinito del media_type.
    $storageVolumeId = (int)($_POST['storage_volume_id'] ?? 0);
    if ($storageVolumeId > 0 && !fmStorageVolume($storageVolumeId)) $storageVolumeId = 0;

    $validAudioTypes = ['http', 'rtmp', 'rtsp'];
    $validVideoTypes = ['http', 'rtmp', 'rtsp', 'srt', 'v4l2', 'rtmp-push'];

    if ($name === '') $errors[] = 'Il nome è obbligatorio.';
    if ($filePrefix === '') $errors[] = 'Il prefisso file è obbligatorio.';
    if ($mediaType === 'clock') {
        // Nessun flusso: nessuna delle validazioni su protocollo/url/device si applica.
        $type = 'clock';
        $url = '';
        $device = '';
    } else {
        if ($mediaType === 'audio' && !in_array($type, $validAudioTypes, true)) $errors[] = 'Tipo sorgente non valido per audio.';
        if ($mediaType === 'video' && !in_array($type, $validVideoTypes, true)) $errors[] = 'Tipo sorgente non valido per video.';
        if ($type === 'v4l2' && $device === '') $errors[] = 'Il device è obbligatorio per v4l2.';
        if ($type !== 'v4l2' && $type !== 'rtmp-push' && $url === '') $errors[] = "L'URL è obbligatorio.";
    }

    if (empty($errors)) {
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE sources SET name=?, file_prefix=?, media_type=?, url=?, type=?, device=?,
                audio_quality=?, resolution=?, fps=?, video_quality=?, extra_opts=?, active=?,
                max_recordings=?, max_days_recordings=?, max_clips_per_marker=?, max_days_clips=?,
                storage_volume_id=? WHERE id=?');
            $stmt->execute([$name, $filePrefix ?: null, $mediaType, $url ?: null, $type, $device ?: null,
                $audioQuality, $resolution, $fps, $videoQuality, $extraOpts ?: null, $active,
                $maxRecordings, $maxDaysRecordings, $maxClipsPerMarker, $maxDaysClips,
                $storageVolumeId ?: null, $id]);
            $savedId = $id;
        } else {
            $stmt = $db->prepare('INSERT INTO sources
                (name, file_prefix, media_type, url, type, device, audio_quality, resolution, fps, video_quality, extra_opts, active,
                 max_recordings, max_days_recordings, max_clips_per_marker, max_days_clips, storage_volume_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $filePrefix ?: null, $mediaType, $url ?: null, $type, $device ?: null,
                $audioQuality, $resolution, $fps, $videoQuality, $extraOpts ?: null, $active,
                $maxRecordings, $maxDaysRecordings, $maxClipsPerMarker, $maxDaysClips,
                $storageVolumeId ?: null]);
            $savedId = (int)$db->lastInsertId();
        }
        // Per rtmp-push riapriamo subito la sorgente in modifica: solo dopo il salvataggio
        // esiste l'ID che compone l'URL di ingresso, quindi è l'unico momento utile per mostrarlo.
        header('Location: sources.php' . ($type === 'rtmp-push' ? '?opened=' . $savedId : ''));
        exit;
    }
}

$sources = $db->query('SELECT * FROM sources ORDER BY active DESC, name ASC')->fetchAll();

$localIp = trim((string)@shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'")) ?: '<ip-pi>';

// Profili di qualità video. DEVONO restare allineati al case VIDEO_QUALITY in
// scripts/record.sh: qui vivono solo le etichette mostrate in UI.
// Bitrate e GB/h sono misurati su una sorgente 1080p30 e scalano con la risoluzione.
$fmVideoQualities = [
    'copy'  => ['label' => 'Originale (nessuna ricodifica)', 'video' => 'come sorgente', 'audio' => 'come sorgente', 'gbh' => 'come sorgente'],
    'hd'    => ['label' => 'HD',    'video' => 'H.264 CRF 17 (~4,2 Mbps)', 'audio' => 'AAC 128k', 'gbh' => '~0,9 GB/h'],
    'alta'  => ['label' => 'Alta',  'video' => 'H.264 CRF 21 (~2,6 Mbps)', 'audio' => 'AAC 128k', 'gbh' => '~0,6 GB/h'],
    'media' => ['label' => 'Media', 'video' => 'H.264 CRF 28 (~1,1 Mbps)', 'audio' => 'AAC 128k', 'gbh' => '~0,3 GB/h'],
    'bassa' => ['label' => 'Bassa', 'video' => 'H.264 CRF 32 (~690 kbps)', 'audio' => 'AAC 96k',  'gbh' => '~0,2 GB/h'],
];

$pageTitle = 'Sorgenti';
include __DIR__ . '/includes/head.php';
?>

<div class="uk-flex uk-flex-middle uk-flex-between uk-margin-bottom" style="flex-wrap:wrap;gap:10px;">
    <h2 class="fm-page-title uk-margin-remove"><span uk-icon="icon: rss"></span> Sorgenti</h2>
    <button class="uk-button uk-button-primary" type="button" onclick="fmOpenSourceModal()">
        <span uk-icon="icon: plus-circle; ratio: 0.9"></span> Aggiungi sorgente
    </button>
</div>

<?php foreach ($errors as $e): ?>
<div class="uk-alert-danger" uk-alert><span uk-icon="icon: warning"></span> <?= fmH($e) ?></div>
<?php endforeach; ?>

<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead><tr>
        <th style="padding-left:10px;">Nome</th>
        <th>URL stream</th>
        <th style="width:80px;">Prefisso</th>
        <th style="width:80px;">Qualità</th>
        <th style="width:80px;">Max reg.</th>
        <th style="width:90px;">Max giorni</th>
        <th style="width:90px;">Stato</th>
        <th style="width:100px;">Azioni</th>
    </tr></thead>
    <tbody>
    <?php if (empty($sources)): ?>
        <tr><td colspan="8" class="uk-text-meta">Nessuna sorgente configurata.</td></tr>
    <?php endif; ?>
    <?php foreach ($sources as $s):
        $isVideo = $s['media_type'] === 'video';
        $isClock = $s['media_type'] === 'clock';
        // Tinta di riga: colore accento della sua etichetta media_type, appena percettibile.
        // Lo stesso colore, a piena opacità, va sul bordo sinistro della riga (vedi $rowAccent).
        $rowTint = $isClock ? 'rgba(180,83,9,.08)' : ($isVideo ? 'rgba(168,85,201,.08)' : 'rgba(30,135,240,.08)');
        $rowAccent = $isClock ? '#b45309' : ($isVideo ? '#a855c9' : '#1e87f0');
    ?>
        <tr style="background:<?= $rowTint ?>;">
            <td style="padding-left:10px;box-shadow:inset 3px 0 0 <?= $rowAccent ?>;">
                <span style="margin-right:6px;"><?= fmMediaTypeBadge($s['media_type'], false) ?></span>
                <strong><?= fmH($s['name']) ?></strong>
            </td>
            <td class="uk-text-truncate fm-mono" style="max-width:320px;font-size:12px;color:#666;">
                <?php if ($s['type'] === 'rtmp-push'): ?>
                    rtmp://<?= fmH($localIp) ?>:<?= fmH(FM_RTMP_PORT) ?>/<?= (int)$s['id'] ?>
                <?php elseif ($isClock): ?>
                    <span class="uk-text-meta">nessun flusso</span>
                <?php else: ?>
                    <?= fmH($s['url'] ?: $s['device'] ?: '—') ?>
                <?php endif; ?>
            </td>
            <td><?php if ($s['file_prefix']): ?><span class="fm-prefix-chip"><?= fmH($s['file_prefix']) ?></span><?php else: ?><span class="uk-text-meta">—</span><?php endif; ?></td>
            <td class="fm-mono" style="font-size:12px;color:#666;">
                <?php if ($isClock): ?>
                    <span class="uk-text-meta">—</span>
                <?php elseif (!$isVideo): ?>
                    -q:a <?= fmH($s['audio_quality']) ?>
                <?php else: $vq = $s['video_quality'] ?: 'copy'; ?>
                    <span uk-tooltip="<?= fmH(($fmVideoQualities[$vq] ?? $fmVideoQualities['copy'])['video']) ?>"><?= fmH($vq) ?></span>
                <?php endif; ?>
            </td>
            <td class="fm-mono" style="font-size:12px;"><?= (int)$s['max_recordings'] ?></td>
            <td class="fm-mono" style="font-size:12px;"><?= (int)$s['max_days_recordings'] ?> gg</td>
            <td><span class="fm-status-pill <?= $s['active'] ? 'fm-status-pill-on' : 'fm-status-pill-off' ?>"><?= $s['active'] ? 'Attiva' : 'Inattiva' ?></span></td>
            <td>
                <a href="#" class="fm-action-icon" uk-icon="icon: pencil; ratio: 0.8" uk-tooltip="Modifica"
                   onclick='fmOpenSourceModal(<?= json_encode($s) ?>);return false;'></a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="duplicate">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="fm-action-icon" uk-icon="icon: copy; ratio: 0.8" uk-tooltip="Duplica" style="background:none;border:none;cursor:pointer;"></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questa sorgente?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="fm-action-icon uk-text-danger" uk-icon="icon: trash; ratio: 0.8" uk-tooltip="Elimina" style="background:none;border:none;cursor:pointer;"></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div id="fm-source-modal" uk-modal>
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical fm-modal-wide">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <h2 class="uk-modal-title" id="fm-source-modal-title">Nuova sorgente</h2>

        <form method="post" id="fm-source-form">
            <input type="hidden" name="id" id="fm-f-id" value="0">
            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-2-3@s">
                    <label class="uk-form-label">Nome <span class="fm-required">*</span></label>
                    <input class="uk-input uk-form-small" type="text" name="name" id="fm-f-name" required>
                </div>
                <div class="uk-width-1-3@s">
                    <label class="uk-form-label">Prefisso file <span class="fm-required">*</span></label>
                    <input class="uk-input uk-form-small fm-mono" type="text" name="file_prefix" id="fm-f-prefix" placeholder="es. KR" maxlength="12" required>
                </div>

                <div class="uk-width-1-1">
                    <label class="uk-form-label">Modalità sorgente</label>
                    <div class="uk-flex" style="gap:8px;">
                        <button type="button" class="uk-button uk-button-default uk-button-small fm-mode-btn" data-mode="pull" style="flex:1;">
                            <span uk-icon="icon: download; ratio: 0.8"></span> Preleva stream esistente
                        </button>
                        <button type="button" class="uk-button uk-button-default uk-button-small fm-mode-btn" data-mode="push" style="flex:1;">
                            <span uk-icon="icon: upload; ratio: 0.8"></span> Ricevi stream in ingresso (push)
                        </button>
                        <button type="button" class="uk-button uk-button-default uk-button-small fm-mode-btn" data-mode="clock" style="flex:1;">
                            <span uk-icon="icon: clock; ratio: 0.8"></span> CLOCK (nessun flusso)
                        </button>
                    </div>
                    <div class="uk-text-meta" style="font-size:11px;margin-top:4px;" id="fm-mode-hint"></div>
                </div>

                <div class="uk-width-1-1 fm-field-clock uk-alert-primary" uk-alert style="display:none;">
                    <p class="uk-margin-remove">
                        Nessun flusso da registrare: usala per prendere marker con orario reale
                        anche senza una diretta in corso. Avvia/Ferma crea comunque una
                        registrazione vera, con inizio/fine/durata ed export dei marker — ma
                        senza alcun file audio/video, e senza Cue (non c'è nulla da tagliare).
                    </p>
                </div>

                <div class="uk-width-1-3@s fm-field-media-type">
                    <label class="uk-form-label">Tipo media</label>
                    <select class="uk-select uk-form-small" name="media_type" id="fm-media-type">
                        <option value="audio">Audio</option>
                        <option value="video">Video</option>
                        <option value="clock" id="fm-opt-clock" hidden disabled>CLOCK</option>
                    </select>
                </div>
                <div class="uk-width-2-3@s fm-field-protocol">
                    <label class="uk-form-label">Protocollo</label>
                    <select class="uk-select uk-form-small" name="type" id="fm-source-type">
                        <optgroup label="AUDIO" id="fm-opts-audio">
                            <option value="http">http</option>
                            <option value="rtmp">rtmp</option>
                            <option value="rtsp">rtsp</option>
                        </optgroup>
                        <optgroup label="VIDEO" id="fm-opts-video">
                            <option value="http">http</option>
                            <option value="rtmp">rtmp</option>
                            <option value="rtsp">rtsp</option>
                            <option value="srt">srt</option>
                            <option value="v4l2">v4l2 (webcam/CSI locale)</option>
                            <option value="rtmp-push" id="fm-opt-rtmp-push" hidden disabled>rtmp-push (ricezione MediaMTX)</option>
                        </optgroup>
                    </select>
                </div>
                <div class="uk-width-1-1 fm-field-push-media uk-text-meta" style="display:none;font-size:12px;">
                    Tipo media: <strong>Video</strong> · Protocollo: <strong>rtmp-push</strong>
                    <span class="uk-text-muted">(fisso — il push in ingresso è disponibile solo per sorgenti video)</span>
                </div>

                <div class="uk-width-1-1 fm-field-url">
                    <label class="uk-form-label">URL stream <span class="fm-required">*</span></label>
                    <div class="uk-flex" style="gap:8px;">
                        <input class="uk-input uk-form-small fm-mono" type="text" name="url" id="fm-field-url" placeholder="es. http://host/stream.mp3 o rtsp://host/stream" style="flex:1;">
                        <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-test-btn" onclick="fmTestSource()"><span uk-icon="icon: bolt; ratio: 0.8"></span> Test</button>
                    </div>
                    <div id="fm-test-result" class="uk-text-meta" style="font-size:11px;margin-top:4px;"></div>
                </div>

                <div class="uk-width-1-1 fm-field-device" style="display:none;">
                    <label class="uk-form-label">Device</label>
                    <input class="uk-input uk-form-small fm-mono" type="text" name="device" id="fm-field-device" placeholder="/dev/video0">
                </div>

                <div class="uk-width-1-1 fm-field-rtmp-push uk-alert-primary" uk-alert style="display:none;">
                    <p class="uk-margin-remove" id="fm-rtmp-push-pending">Salva la sorgente per generare l'URL di ingresso univoco: verrà mostrato qui subito dopo, senza bisogno di riaprire manualmente.</p>
                    <p class="uk-margin-remove" id="fm-rtmp-push-ready" style="display:none;">
                        Invia lo stream (encoder/telecamera) a: <code id="fm-rtmp-push-url"></code>
                        <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-rtmp-copy-btn" style="margin-left:8px;">Copia</button>
                    </p>
                    <p class="uk-margin-small-top uk-text-small" id="fm-rtmp-push-hint" style="display:none;">
                        Se l'encoder chiede <em>due</em> campi separati (Wirecast, OBS e simili: "indirizzo"
                        e "stream"), metti <code id="fm-rtmp-push-server"></code> nell'indirizzo e
                        <code id="fm-rtmp-push-key"></code> nello stream. Va bene anche tenere l'id
                        nell'indirizzo e scrivere quello che si vuole nello stream: Fluxus riconosce
                        comunque il flusso in arrivo.
                    </p>
                </div>

                <div class="uk-width-1-4@s fm-field-audio-only">
                    <label class="uk-form-label">Qualità MP3</label>
                    <select class="uk-select uk-form-small" name="audio_quality" id="fm-f-audio-quality">
                        <option value="0">-q:a 0 (~245k)</option>
                        <option value="2">-q:a 2 (~190k)</option>
                        <option value="4">-q:a 4 (~165k)</option>
                        <option value="6">-q:a 6 (~128k)</option>
                        <option value="9">-q:a 9 (~65k)</option>
                    </select>
                </div>
                <div class="uk-width-1-4@s">
                    <label class="uk-form-label">Max registrazioni</label>
                    <input class="uk-input uk-form-small" type="number" name="max_recordings" id="fm-f-max-recordings" value="30" min="0">
                </div>
                <div class="uk-width-1-4@s">
                    <label class="uk-form-label">Max giorni registr.</label>
                    <input class="uk-input uk-form-small" type="number" name="max_days_recordings" id="fm-f-max-days-recordings" value="45" min="0">
                </div>
                <div class="uk-width-1-4@s">
                    <label class="uk-form-label">Stato</label>
                    <select class="uk-select uk-form-small" name="active" id="fm-f-active">
                        <option value="1">Attiva</option>
                        <option value="0">Disattiva</option>
                    </select>
                </div>

                <div class="uk-width-1-2@s fm-field-cue-retention">
                    <label class="uk-form-label">Max clip per marker</label>
                    <input class="uk-input uk-form-small" type="number" name="max_clips_per_marker" id="fm-f-max-clips" value="100" min="0">
                </div>
                <div class="uk-width-1-2@s fm-field-cue-retention">
                    <label class="uk-form-label">Max giorni clip</label>
                    <input class="uk-input uk-form-small" type="number" name="max_days_clips" id="fm-f-max-days-clips" value="20" min="0">
                </div>

                <div class="uk-width-1-2@s fm-field-v4l2-only" style="display:none;">
                    <label class="uk-form-label">Risoluzione di cattura</label>
                    <input class="uk-input uk-form-small" type="text" name="resolution" id="fm-f-resolution" value="1920x1080">
                    <div class="uk-text-meta" style="font-size:11px;margin-top:4px;">Parametro del device locale.</div>
                </div>
                <div class="uk-width-1-2@s fm-field-v4l2-only" style="display:none;">
                    <label class="uk-form-label">FPS di cattura</label>
                    <input class="uk-input uk-form-small" type="number" name="fps" id="fm-f-fps" value="25">
                    <div class="uk-text-meta" style="font-size:11px;margin-top:4px;">Parametro del device locale.</div>
                </div>

                <div class="uk-width-1-1 fm-field-video-only">
                    <label class="uk-form-label">Qualità registrazione</label>
                    <select class="uk-select uk-form-small" name="video_quality" id="fm-f-video-quality">
                        <?php foreach ($fmVideoQualities as $qk => $q): ?>
                        <option value="<?= fmH($qk) ?>"><?= fmH($q['label']) ?><?= $qk === 'copy' ? '' : ' — ' . fmH($q['gbh']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="fm-quality-info" class="uk-text-meta" style="font-size:11px;margin-top:6px;line-height:1.5;"></div>
                </div>

                <div class="uk-width-1-1 fm-field-storage-volume">
                    <label class="uk-form-label">Volume di archiviazione</label>
                    <select class="uk-select uk-form-small" name="storage_volume_id" id="fm-f-storage-volume">
                        <option value="0">Predefinito per tipo media</option>
                        <?php foreach (fmStorageVolumes() as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= fmH($v['label']) ?><?= $v['online'] ? '' : ' (non collegato)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="uk-text-meta" style="font-size:11px;margin-top:4px;">
                        Con "Predefinito" vale la destinazione impostata in <a href="settings.php#archiviazione">Impostazioni &rarr; Archiviazione</a> per audio o video. Vale per le nuove registrazioni; se il volume non è collegato si registra sul volume interno.
                    </div>
                </div>

                <div class="uk-width-1-1">
                    <details style="margin-top:4px;">
                        <summary style="cursor:pointer;font-size:12px;color:#999;">Avanzate</summary>
                        <div style="margin-top:8px;">
                            <label class="uk-form-label">Opzioni extra ffmpeg (opzionale)</label>
                            <input class="uk-input uk-form-small fm-mono" type="text" name="extra_opts" id="fm-f-extra-opts" placeholder="es. -threads 3">
                            <div class="uk-text-meta" style="font-size:11px;margin-top:4px;line-height:1.6;">
                                Aggiunte alla riga di comando ffmpeg dopo i parametri di codifica.
                                Riferimento:
                                <a href="https://ffmpeg.org/ffmpeg.html#Options" target="_blank" rel="noopener">opzioni generali</a> ·
                                <a href="https://ffmpeg.org/ffmpeg-codecs.html" target="_blank" rel="noopener">codec</a> ·
                                <a href="https://ffmpeg.org/ffmpeg-filters.html" target="_blank" rel="noopener">filtri</a> ·
                                <a href="https://ffmpeg.org/ffmpeg-protocols.html" target="_blank" rel="noopener">protocolli</a>
                                <br>Esempi utili: <code>-threads 3</code> (limita la CPU) ·
                                <code>-af loudnorm</code> (normalizza l'audio) ·
                                <code>-timeout 5000000</code> (timeout di rete).
                            </div>
                        </div>
                    </details>
                </div>
            </div>

            <div class="uk-flex uk-flex-right uk-grid-small uk-margin-top" uk-grid>
                <div class="uk-width-auto"><button type="button" class="uk-button uk-button-default uk-button-small" onclick="UIkit.modal('#fm-source-modal').hide()">Annulla</button></div>
                <div class="uk-width-auto"><button type="submit" class="uk-button uk-button-primary uk-button-small">Salva</button></div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var mediaTypeSel = document.getElementById('fm-media-type');
    var typeSel = document.getElementById('fm-source-type');
    var optsAudio = document.getElementById('fm-opts-audio');
    var optsVideo = document.getElementById('fm-opts-video');
    var optRtmpPush = document.getElementById('fm-opt-rtmp-push');
    var optClock = document.getElementById('fm-opt-clock');
    var fieldUrl = document.querySelector('.fm-field-url');
    var fieldDevice = document.querySelector('.fm-field-device');
    var fieldRtmpPush = document.querySelector('.fm-field-rtmp-push');
    var fieldMediaType = document.querySelector('.fm-field-media-type');
    var fieldProtocol = document.querySelector('.fm-field-protocol');
    var fieldPushMedia = document.querySelector('.fm-field-push-media');
    var fieldClockInfo = document.querySelector('.fm-field-clock');
    var fieldStorageVolume = document.querySelector('.fm-field-storage-volume');
    var audioOnly = document.querySelectorAll('.fm-field-audio-only');
    var videoOnly = document.querySelectorAll('.fm-field-video-only');
    var pushHide = document.querySelectorAll('.fm-field-push-hide');
    var v4l2Only = document.querySelectorAll('.fm-field-v4l2-only');
    var cueRetention = document.querySelectorAll('.fm-field-cue-retention');
    var qualitySel = document.getElementById('fm-f-video-quality');
    var qualityInfo = document.getElementById('fm-quality-info');
    var qualities = <?= json_encode($fmVideoQualities) ?>;
    var modeBtns = document.querySelectorAll('.fm-mode-btn');
    var modeHint = document.getElementById('fm-mode-hint');
    var localIp = <?= json_encode($localIp) ?>;
    // L'indirizzo mostrato all'encoder è quello della macchina in LAN, non
    // quello con cui ffmpeg si collega a MediaMTX qui dentro (loopback).
    var rtmpPort = <?= json_encode(FM_RTMP_PORT) ?>;
    var lastPullType = 'http';
    var currentMode = 'pull';

    function applyMediaType() {
        var isVideo = mediaTypeSel.value === 'video';
        optsAudio.style.display = isVideo ? 'none' : '';
        optsVideo.style.display = isVideo ? '' : 'none';
        audioOnly.forEach(function (el) { el.style.display = isVideo ? 'none' : ''; });
        videoOnly.forEach(function (el) { el.style.display = isVideo ? '' : 'none'; });
        applyQuality();
    }

    function applyQuality() {
        var q = qualities[qualitySel.value] || qualities.copy;
        var nota = 'Risoluzione e fps restano sempre quelli dello stream in ingresso: nessun ridimensionamento.';
        if (qualitySel.value === 'copy') {
            qualityInfo.innerHTML = 'Il file viene salvato esattamente come arriva dalla sorgente (stream copy). '
                + 'Il peso lo decide l\'encoder a monte: <strong>Fluxus non può ridurlo</strong>.';
        } else {
            qualityInfo.innerHTML = 'Video <strong>' + q.video + '</strong> · audio <strong>' + q.audio + '</strong> · '
                + 'stima <strong>' + q.gbh + '</strong>.<br>' + nota
                + ' Stime riferite a 1080p30: scalano con la risoluzione della sorgente.';
        }
    }

    function setMode(mode) {
        currentMode = mode;
        modeBtns.forEach(function (b) {
            b.classList.toggle('uk-button-primary', b.dataset.mode === mode);
            b.classList.toggle('uk-button-default', b.dataset.mode !== mode);
        });

        fieldClockInfo.style.display = mode === 'clock' ? '' : 'none';

        if (mode === 'push') {
            modeHint.textContent = 'Il tuo encoder/telecamera invierà lo stream a questo Raspberry Pi (MediaMTX riceve, <?= fmH(FM_APP_NAME) ?> registra).';
            fieldMediaType.style.display = 'none';
            fieldProtocol.style.display = 'none';
            fieldPushMedia.style.display = '';
            fieldStorageVolume.style.display = '';
            cueRetention.forEach(function (el) { el.style.display = ''; });
            optRtmpPush.hidden = false;
            optRtmpPush.disabled = false;
            optClock.hidden = true;
            optClock.disabled = true;
            mediaTypeSel.value = 'video';
            typeSel.value = 'rtmp-push';
            applyMediaType();
            applyType();
        } else if (mode === 'clock') {
            // Nessun flusso: niente protocollo/url/device/qualità/volume di
            // archiviazione né retention dei cue (non esistono per questo tipo).
            modeHint.textContent = 'Nessun flusso da collegare: ogni Avvia/Ferma crea comunque una registrazione vera, ma senza file audio/video.';
            fieldMediaType.style.display = 'none';
            fieldProtocol.style.display = 'none';
            fieldPushMedia.style.display = 'none';
            fieldUrl.style.display = 'none';
            fieldDevice.style.display = 'none';
            fieldRtmpPush.style.display = 'none';
            fieldStorageVolume.style.display = 'none';
            audioOnly.forEach(function (el) { el.style.display = 'none'; });
            videoOnly.forEach(function (el) { el.style.display = 'none'; });
            v4l2Only.forEach(function (el) { el.style.display = 'none'; });
            cueRetention.forEach(function (el) { el.style.display = 'none'; });
            optRtmpPush.hidden = true;
            optRtmpPush.disabled = true;
            optClock.hidden = false;
            optClock.disabled = false;
            mediaTypeSel.value = 'clock';
        } else {
            modeHint.textContent = '<?= fmH(FM_APP_NAME) ?> si collega lui stesso a uno stream o device già esistente.';
            fieldMediaType.style.display = '';
            fieldProtocol.style.display = '';
            fieldPushMedia.style.display = 'none';
            fieldStorageVolume.style.display = '';
            cueRetention.forEach(function (el) { el.style.display = ''; });
            optRtmpPush.hidden = true;
            optRtmpPush.disabled = true;
            optClock.hidden = true;
            optClock.disabled = true;
            if (typeSel.value === 'rtmp-push') typeSel.value = lastPullType;
            if (mediaTypeSel.value === 'clock') mediaTypeSel.value = 'audio';
            applyMediaType();
            applyType();
        }
    }

    function applyType() {
        var t = typeSel.value;
        if (t !== 'rtmp-push') lastPullType = t;
        fieldDevice.style.display = (t === 'v4l2') ? '' : 'none';
        fieldRtmpPush.style.display = (t === 'rtmp-push') ? '' : 'none';
        fieldUrl.style.display = (t === 'v4l2' || t === 'rtmp-push') ? 'none' : '';
        pushHide.forEach(function (el) { if (t === 'rtmp-push') el.style.display = 'none'; });
        // Risoluzione/FPS sono parametri di cattura del device locale: per pull e push
        // la dimensione la detta lo stream in ingresso, quindi non vanno chiesti.
        var showV4l2 = (t === 'v4l2' && mediaTypeSel.value === 'video');
        v4l2Only.forEach(function (el) { el.style.display = showV4l2 ? '' : 'none'; });

        var id = document.getElementById('fm-f-id').value;
        var hasId = id && id !== '0';
        document.getElementById('fm-rtmp-push-pending').style.display = hasId ? 'none' : '';
        document.getElementById('fm-rtmp-push-ready').style.display = hasId ? '' : 'none';
        document.getElementById('fm-rtmp-push-hint').style.display = hasId ? '' : 'none';
        if (hasId) {
            document.getElementById('fm-rtmp-push-url').textContent = 'rtmp://' + localIp + ':' + rtmpPort + '/' + id;
            document.getElementById('fm-rtmp-push-server').textContent = 'rtmp://' + localIp + ':' + rtmpPort;
            document.getElementById('fm-rtmp-push-key').textContent = id;
        }
    }

    mediaTypeSel.addEventListener('change', function () { applyMediaType(); applyType(); });
    typeSel.addEventListener('change', applyType);
    qualitySel.addEventListener('change', applyQuality);
    modeBtns.forEach(function (b) {
        b.addEventListener('click', function () { setMode(b.dataset.mode); });
    });
    document.getElementById('fm-rtmp-copy-btn').addEventListener('click', function () {
        var text = document.getElementById('fm-rtmp-push-url').textContent;
        navigator.clipboard.writeText(text).then(function () {
            var btn = document.getElementById('fm-rtmp-copy-btn');
            var orig = btn.textContent;
            btn.textContent = 'Copiato!';
            setTimeout(function () { btn.textContent = orig; }, 1500);
        });
    });

    window.fmOpenSourceModal = function (s) {
        document.getElementById('fm-test-result').textContent = '';
        var f = s || { id: 0, name: '', file_prefix: '', media_type: 'audio', type: 'http', url: '', device: '',
            audio_quality: '2', resolution: '1920x1080', fps: 25, video_quality: 'copy',
            extra_opts: '', active: 1, max_recordings: 30, max_days_recordings: 45, max_clips_per_marker: 100, max_days_clips: 20,
            storage_volume_id: 0 };

        document.getElementById('fm-source-modal-title').textContent = s ? 'Modifica sorgente' : 'Nuova sorgente';
        document.getElementById('fm-f-id').value = f.id || 0;
        document.getElementById('fm-f-name').value = f.name || '';
        document.getElementById('fm-f-prefix').value = f.file_prefix || '';
        mediaTypeSel.value = f.media_type || 'audio';
        document.getElementById('fm-field-url').value = f.url || '';
        document.getElementById('fm-field-device').value = f.device || '';
        document.getElementById('fm-f-audio-quality').value = f.audio_quality || '2';
        document.getElementById('fm-f-max-recordings').value = f.max_recordings ?? 30;
        document.getElementById('fm-f-max-days-recordings').value = f.max_days_recordings ?? 45;
        document.getElementById('fm-f-active').value = String(f.active ?? 1);
        document.getElementById('fm-f-max-clips').value = f.max_clips_per_marker ?? 100;
        document.getElementById('fm-f-max-days-clips').value = f.max_days_clips ?? 20;
        document.getElementById('fm-f-storage-volume').value = String(f.storage_volume_id || 0);
        document.getElementById('fm-f-resolution').value = f.resolution || '1920x1080';
        document.getElementById('fm-f-fps').value = f.fps || 25;
        qualitySel.value = f.video_quality || 'copy';
        document.getElementById('fm-f-extra-opts').value = f.extra_opts || '';

        optRtmpPush.hidden = false;
        optRtmpPush.disabled = false;
        optClock.hidden = false;
        optClock.disabled = false;
        typeSel.value = f.type || 'http';
        optRtmpPush.hidden = true;
        optRtmpPush.disabled = true;
        optClock.hidden = true;
        optClock.disabled = true;

        setMode(f.media_type === 'clock' ? 'clock' : (f.type === 'rtmp-push' ? 'push' : 'pull'));

        UIkit.modal('#fm-source-modal').show();
    };

    window.fmTestSource = function () {
        var result = document.getElementById('fm-test-result');
        result.textContent = 'test in corso…';
        fetch(<?= json_encode(rtrim(FM_WEB_BASE, '/')) ?> + '/api/test_source.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                url: document.getElementById('fm-field-url').value,
                device: document.getElementById('fm-field-device').value,
                type: typeSel.value
            })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d.ok) {
                  result.innerHTML = '<span style="color:#32d296;">&#10003; ' + (d.summary || 'OK') + '</span>';
              } else {
                  result.innerHTML = '<span style="color:#f0506e;">&#10007; ' + (d.error || 'errore') + '</span>';
              }
          }).catch(function () { result.innerHTML = '<span style="color:#f0506e;">errore di rete</span>'; });
    };

    setMode('pull');

    <?php if (!empty($_GET['opened'])):
        $openedId = (int)$_GET['opened'];
        $openedSource = null;
        foreach ($sources as $s) { if ((int)$s['id'] === $openedId) { $openedSource = $s; break; } }
        if ($openedSource): ?>
    fmOpenSourceModal(<?= json_encode($openedSource) ?>);
    UIkit.notification({ message: 'Sorgente salvata. URL di ingresso pronto qui sotto.', status: 'success', pos: 'top-center' });
    <?php endif; endif; ?>
})();
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
