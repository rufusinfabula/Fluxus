<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$webBase = rtrim(FM_WEB_BASE, '/');

$sources = $db->query("SELECT * FROM sources WHERE active = 1 ORDER BY name ASC")->fetchAll();

$activeStmt = $db->prepare("SELECT id, start_time, slot_duration FROM recordings WHERE source_id = ? AND status = 'recording' ORDER BY id DESC LIMIT 1");
$lastStmt = $db->prepare("SELECT filename_base, start_time, status FROM recordings WHERE source_id = ? ORDER BY id DESC LIMIT 1");
$schedStmt = $db->prepare("SELECT id, label, on_calendar, active FROM schedules WHERE source_id = ? ORDER BY id ASC");

foreach ($sources as &$s) {
    $activeStmt->execute([$s['id']]);
    $s['_active_recording'] = $activeStmt->fetch();
    $lastStmt->execute([$s['id']]);
    $s['_last_recording'] = $lastStmt->fetch();
    $schedStmt->execute([$s['id']]);
    $s['_schedules'] = $schedStmt->fetchAll();
}
unset($s);

// Prossime registrazioni attese: schedule attivi con prossimo scatto calcolato.
$activeSchedules = $db->query("SELECT sc.*, s.name AS source_name, s.media_type
    FROM schedules sc JOIN sources s ON s.id = sc.source_id
    WHERE sc.active = 1 AND s.active = 1")->fetchAll();
$upcoming = [];
foreach ($activeSchedules as $sc) {
    $timer = fmGetTimerStatus((int)$sc['id']);
    if ($timer['next'] !== null) {
        $upcoming[] = [
            'source_name' => $sc['source_name'],
            'label'       => $sc['label'],
            'on_calendar' => $sc['on_calendar'],
            'next'        => $timer['next'],
            'media_type'  => $sc['media_type'],
        ];
    }
}
usort($upcoming, fn($a, $b) => $a['next'] <=> $b['next']);
$upcoming = array_slice($upcoming, 0, 10);

$recent = $db->query("SELECT r.*,
    CASE
        WHEN r.duration_seconds IS NOT NULL AND r.duration_seconds > 0 THEN r.duration_seconds
        WHEN r.start_time IS NOT NULL AND r.end_time IS NOT NULL
            THEN CAST((julianday(r.end_time) - julianday(r.start_time)) * 86400 AS INTEGER)
        ELSE r.duration_seconds
    END AS duration_seconds
    FROM recordings r
    WHERE r.status != 'recording' ORDER BY r.id DESC LIMIT 10")->fetchAll();

$statusMeta = [
    'completed' => ['Completata', '#32d296'],
    'failed'    => ['Fallita',    '#f0506e'],
    'recording' => ['In corso',  '#f0506e'],
];

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/head.php';
?>

<div class="uk-flex uk-flex-middle uk-flex-between uk-margin-bottom" style="flex-wrap:wrap;gap:10px;">
    <h2 class="fm-page-title uk-margin-remove"><span uk-icon="icon: home"></span> Dashboard</h2>
    <div class="uk-button-group" id="fm-filter-group">
        <button class="uk-button uk-button-small uk-button-primary" data-filter="all">Tutti</button>
        <button class="uk-button uk-button-small uk-button-default" data-filter="audio"><span uk-icon="icon: microphone; ratio: 0.8"></span> Audio</button>
        <button class="uk-button uk-button-small uk-button-default" data-filter="video"><span uk-icon="icon: video-camera; ratio: 0.8"></span> Video</button>
        <button class="uk-button uk-button-small uk-button-default" data-filter="clock"><span uk-icon="icon: clock; ratio: 0.8"></span> Clock</button>
    </div>
</div>

<div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@l uk-margin-bottom" uk-grid id="fm-source-grid">
<?php if (empty($sources)): ?>
    <div><p class="uk-text-meta">Nessuna sorgente configurata. Vai su <a href="<?= $webBase ?>/sources.php">Sorgenti</a> per aggiungerne una.</p></div>
<?php endif; ?>
<?php foreach ($sources as $s):
    $isVideo = $s['media_type'] === 'video';
    $isClock = $s['media_type'] === 'clock';
    $rec = $s['_active_recording'];
    $last = $s['_last_recording'];
    $cardNavUrl = $rec
        ? $webBase . '/recording.php?id=' . fmRecCode((int)$rec['id'], $s['media_type'])
        : $webBase . '/recordings.php?source_id=' . (int)$s['id'];
?>
    <div class="fm-source-card" data-media-type="<?= $s['media_type'] ?>" data-nav-url="<?= fmH($cardNavUrl) ?>">
        <div class="uk-card uk-card-default uk-card-body fm-card<?= $rec ? ' fm-card-active' : '' ?>">
            <div class="uk-flex uk-flex-middle uk-flex-between">
                <h3 class="uk-card-title uk-margin-remove uk-flex uk-flex-middle" style="gap:8px;flex-wrap:wrap;">
                    <?= fmH($s['name']) ?>
                    <?= fmMediaTypeBadge($s['media_type'], true) ?>
                </h3>
                <?php if ($rec): ?>
                <span class="uk-badge fm-badge-live fm-pulse"><span class="fm-rec-dot" style="background:#fff;"></span>REC</span>
                <?php else: ?>
                <span class="fm-idle-badge">Idle</span>
                <?php endif; ?>
            </div>
            <div class="fm-card-meta uk-margin-small-top fm-mono"><?= $isClock ? 'Sorgente oraria — nessun flusso' : fmH($s['url'] ?: $s['device'] ?: '—') ?></div>
            <?php if ($last): ?>
            <div class="uk-text-meta" style="font-size:12px;margin-top:6px;">
                Ultima: <span class="fm-mono"><?= fmH($last['filename_base']) ?></span> · <?= fmH(fmFormatDateTimeShort($last['start_time'])) ?>
            </div>
            <?php endif; ?>

            <div class="uk-flex uk-flex-middle uk-margin-top" style="gap:8px;flex-wrap:wrap;">
                <?php if ($rec): ?>
                <button type="button" class="uk-button uk-button-danger uk-button-small fm-pulse fm-btn-stop-rec" data-recording-id="<?= (int)$rec['id'] ?>">
                    <span class="fm-stop-dot-btn"></span>STOP
                </button>
                <button class="uk-button uk-button-primary uk-button-small fm-btn-marker" data-recording-id="<?= (int)$rec['id'] ?>" data-media-type="<?= fmH($s['media_type']) ?>">Marker <kbd>M</kbd></button>
                <?php if ($isClock): ?>
                <button class="uk-button uk-button-small" style="background:#1a1a1a;border-color:#444;color:#666;" disabled uk-tooltip="Non disponibile per sorgenti CLOCK"><span uk-icon="icon: nut; ratio: 0.8"></span> Cue <kbd>C</kbd></button>
                <?php else: ?>
                <button class="uk-button uk-button-small fm-btn-cue-btn" style="background:#1a1a1a;border-color:#444;color:#e0e0e0;" data-recording-id="<?= (int)$rec['id'] ?>"><span uk-icon="icon: nut; ratio: 0.8"></span> Cue <kbd>C</kbd></button>
                <?php endif; ?>
                <?php else: ?>
                <button class="uk-button uk-button-danger uk-button-small fm-btn-rec" data-source-id="<?= (int)$s['id'] ?>">
                    <span class="fm-rec-dot-btn"></span>REC
                </button>
                <?php endif; ?>

                <?php if (!$isClock): ?>
                <button type="button" class="fm-icon-btn fm-btn-preview"
                        data-source-id="<?= (int)$s['id'] ?>"
                        data-source-name="<?= fmH($s['name']) ?>"
                        data-media-type="<?= $s['media_type'] ?>"
                        uk-tooltip="Anteprima live">
                    <?php if ($isVideo): ?>
                    <span uk-icon="icon: video-camera; ratio: 0.8"></span>
                    <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>
                    <?php endif; ?>
                </button>

                <button type="button" class="fm-icon-btn fm-btn-check" data-source-id="<?= (int)$s['id'] ?>" uk-tooltip="Verifica se la sorgente è raggiungibile">
                    <span uk-icon="icon: bolt; ratio: 0.9"></span>
                </button>
                <?php endif; ?>

                <a href="<?= $webBase ?>/recordings.php?source_id=<?= (int)$s['id'] ?>" class="fm-icon-btn" uk-tooltip="Registrazioni">
                    <span uk-icon="icon: album; ratio: 0.9"></span>
                </a>
            </div>

            <?php if ($rec):
                // Un cronometro CLOCK non ha un obiettivo (nessuno slot_duration
                // sensato da rispettare): trattato come "durata non prevista"
                // a prescindere dal valore in DB, come già in recording.php.
                $recSlot = (int)($rec['slot_duration'] ?? 0);
                $hasTarget = !$isClock && $recSlot > 0;
            ?>
            <div class="uk-margin-small-top">
                <div class="fm-progress-row">
                    <span class="fm-progress-elapsed fm-mono fm-live-elapsed" data-start="<?= fmH($rec['start_time']) ?>">00:00:00</span>
                    <span class="fm-progress-filename"><?= $hasTarget ? 'obiettivo ' . fmH(fmFormatDuration($recSlot)) : 'durata non prevista' ?></span>
                </div>
                <?php if ($hasTarget): ?>
                <progress class="uk-progress fm-progress fm-live-bar" value="0" max="<?= $recSlot ?>" style="height:4px;margin:4px 0 2px;"></progress>
                <?php else: ?>
                <div class="uk-progress fm-progress fm-progress-unbounded" style="height:4px;margin:4px 0 2px;"></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="fm-check-result" style="display:none;margin-top:8px;font-size:12px;line-height:1.4;"></div>

            <?php if (!$isClock): ?>
            <div class="fm-preview-inline" id="fm-preview-<?= (int)$s['id'] ?>" hidden>
                <div class="fm-preview-inline-loading">
                    <span uk-spinner="ratio: 1"></span>
                    <span class="fm-preview-inline-loading-text">Connessione alla sorgente… <span class="fm-preview-inline-secs">0</span>s</span>
                </div>
                <div class="fm-preview-inline-error uk-alert uk-alert-danger" style="display:none;font-size:12px;"></div>
                <video class="fm-preview-inline-video" style="display:none;" controls playsinline></video>
                <audio class="fm-preview-inline-audio" style="display:none;width:100%;" controls></audio>
            </div>
            <?php endif; ?>

            <?php if ($s['_schedules']): ?>
            <div class="fm-card-subsection">
                <div class="fm-section-title">Orari programmati</div>
                <?php foreach ($s['_schedules'] as $sc): ?>
                <div class="fm-schedule-row">
                    <span class="fm-schedule-label"><?= fmH($sc['label'] ?: 'senza etichetta') ?></span>
                    <span class="fm-prefix-chip"><?= fmH($sc['on_calendar']) ?></span>
                    <span class="fm-status-pill fm-schedule-pill <?= $sc['active'] ? 'fm-status-pill-on' : 'fm-status-pill-off' ?>"><?= $sc['active'] ? 'ON' : 'OFF' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<h3 class="fm-section-title uk-margin-small-bottom">Prossime registrazioni attese</h3>
<?php if (!empty($upcoming)): ?>
<div class="uk-overflow-auto uk-margin-bottom">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead><tr><th>Sorgente</th><th>Etichetta</th><th>OnCalendar</th><th style="width:150px;">Prossima</th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $u): ?>
        <tr>
            <td>
                <?= fmH($u['source_name']) ?>
                <span style="margin-left:6px;"><?= fmMediaTypeBadge($u['media_type'], true) ?></span>
            </td>
            <td><?= fmH($u['label'] ?: '—') ?></td>
            <td><span class="fm-prefix-chip"><?= fmH($u['on_calendar']) ?></span></td>
            <td class="fm-mono" style="font-size:12px;color:#1e87f0;"><?= fmH(fmFormatTs($u['next'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else: ?>
<div class="uk-margin-bottom">
    <p class="uk-text-meta uk-margin-remove-bottom">Nessuna registrazione in programma.</p>
</div>
<?php endif; ?>

<h3 class="fm-section-title uk-margin-small-bottom">Ultime registrazioni</h3>
<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table" id="fm-recent-table">
    <thead>
        <tr><th style="width:70px;">Tipo</th><th>Sorgente</th><th>Inizio</th><th>Durata</th><th>Stato</th><th style="width:24px;"></th></tr>
    </thead>
    <tbody>
    <?php if (empty($recent)): ?>
        <tr><td colspan="6" class="uk-text-meta">Nessuna registrazione.</td></tr>
    <?php endif; ?>
    <?php foreach ($recent as $r):
        [$statusLabel, $statusColor] = $statusMeta[$r['status']] ?? [$r['status'], '#999'];
    ?>
        <tr data-media-type="<?= $r['media_type'] ?>" class="fm-row-clickable" onclick="location.href='<?= $webBase ?>/recording.php?id=<?= fmRecCode((int)$r['id'], $r['media_type']) ?>'">
            <td><?= fmMediaTypeBadge($r['media_type'], true) ?></td>
            <td><?= fmH($r['source_name']) ?></td>
            <td class="fm-date"><?= fmH(fmFormatDateTime($r['start_time'])) ?></td>
            <td class="fm-mono" style="font-size:12px;"><?= fmFormatDuration($r['duration_seconds']) ?></td>
            <td>
                <span class="fm-status-dot" style="background:<?= $statusColor ?>;"></span>
                <span class="fm-status-text" style="color:<?= $statusColor ?>;"><?= fmH($statusLabel) ?></span>
            </td>
            <td><span class="fm-chevron" uk-icon="icon: chevron-right; ratio: 0.9"></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<script>
(function () {
    var base = <?= json_encode($webBase) ?>;

    // Filtro Tutti/Audio/Video
    document.getElementById('fm-filter-group').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-filter]');
        if (!btn) return;
        var filter = btn.getAttribute('data-filter');
        this.querySelectorAll('button').forEach(function (b) {
            b.classList.remove('uk-button-primary');
            b.classList.add('uk-button-default');
        });
        btn.classList.remove('uk-button-default');
        btn.classList.add('uk-button-primary');
        document.querySelectorAll('#fm-source-grid .fm-source-card, #fm-recent-table tbody tr[data-media-type]').forEach(function (el) {
            var show = (filter === 'all') || (el.getAttribute('data-media-type') === filter);
            el.style.display = show ? '' : 'none';
        });
    });

    function fmStartRecording(sourceId, btn) {
        btn.disabled = true;
        fetch(base + '/api/start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_id: sourceId, duration: 3600 })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d.ok) {
                  location.href = base + '/recording.php?id=' + d.recording_code;
              } else {
                  btn.disabled = false;
                  alert(d.error || 'Errore avvio registrazione');
              }
          }).catch(function () { btn.disabled = false; alert('Errore di rete'); });
    }

    function fmStopRecording(recordingId, btn) {
        if (!confirm('Fermare questa registrazione?')) return;
        btn.disabled = true;
        fetch(base + '/api/stop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ recording_id: recordingId })
        }).then(function () { setTimeout(function () { location.reload(); }, 1000); })
          .catch(function () { btn.disabled = false; alert('Errore di rete'); });
    }

    document.addEventListener('click', function (e) {
        var recBtn = e.target.closest('.fm-btn-rec');
        if (recBtn) { fmStartRecording(recBtn.getAttribute('data-source-id'), recBtn); return; }

        var stopBtn = e.target.closest('.fm-btn-stop-rec');
        if (stopBtn) { fmStopRecording(stopBtn.getAttribute('data-recording-id'), stopBtn); return; }

        var prevBtn = e.target.closest('.fm-btn-preview');
        if (prevBtn) {
            var sid = prevBtn.getAttribute('data-source-id');
            if (fmOpenPreviews[sid]) {
                fmCloseInlinePreview(sid);
            } else {
                fmOpenInlinePreview(sid, prevBtn.getAttribute('data-media-type'));
            }
            return;
        }

        var checkBtn = e.target.closest('.fm-btn-check');
        if (checkBtn) { fmCheckSource(checkBtn); return; }

        var mBtn = e.target.closest('.fm-btn-marker');
        if (mBtn) { fmOpenMarkerModal(mBtn.getAttribute('data-recording-id'), 'marker'); return; }
        var cBtn = e.target.closest('.fm-btn-cue-btn');
        if (cBtn) { fmOpenMarkerModal(cBtn.getAttribute('data-recording-id'), 'cue'); return; }
    });

    // Card sorgente cliccabile: naviga verso la registrazione attiva (o l'elenco
    // filtrato) SOLO se il click non è su un pulsante/link interno. Niente
    // stopPropagation qui: i pulsanti della card sono gestiti dal listener
    // delegato su document qui sopra, e stopPropagation a metà albero DOM
    // impedirebbe all'evento di risalire fino a lì, disattivando tutti i
    // pulsanti della card (bug già preso in produzione).
    document.querySelectorAll('.fm-source-card[data-nav-url]').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('button, a')) return;
            var url = card.getAttribute('data-nav-url');
            if (url) location.href = url;
        });
    });

    // --- Anteprima live inline nella card (audio e video) ---------------------
    var fmOpenPreviews = {}; // source_id -> { tick, hls }

    function fmPreviewBox(sourceId) {
        var wrap = document.getElementById('fm-preview-' + sourceId);
        if (!wrap) return null;
        return {
            wrap: wrap,
            loading: wrap.querySelector('.fm-preview-inline-loading'),
            secs: wrap.querySelector('.fm-preview-inline-secs'),
            error: wrap.querySelector('.fm-preview-inline-error'),
            video: wrap.querySelector('.fm-preview-inline-video'),
            audio: wrap.querySelector('.fm-preview-inline-audio')
        };
    }

    function fmCloseInlinePreview(sourceId, useBeacon) {
        var state = fmOpenPreviews[sourceId];
        if (!state) return;
        if (state.tick) clearInterval(state.tick);
        if (state.hls) state.hls.destroy();
        delete fmOpenPreviews[sourceId];

        var box = fmPreviewBox(sourceId);
        if (box) {
            [box.video, box.audio].forEach(function (p) {
                p.pause();
                p.removeAttribute('src');
                p.load();
                p.style.display = 'none';
            });
            box.error.style.display = 'none';
            box.loading.style.display = '';
            box.wrap.hidden = true;
        }

        var payload = JSON.stringify({ source_id: sourceId });
        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon(base + '/api/preview_stop.php', new Blob([payload], { type: 'application/json' }));
        } else {
            fetch(base + '/api/preview_stop.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
        }
    }

    function fmOpenInlinePreview(sourceId, mediaType) {
        var box = fmPreviewBox(sourceId);
        if (!box) return;
        var isVideo = mediaType === 'video';

        fmOpenPreviews[sourceId] = {};
        box.wrap.hidden = false;
        box.error.style.display = 'none';
        box.video.style.display = 'none';
        box.audio.style.display = 'none';
        box.loading.style.display = '';
        var secs = 0;
        box.secs.textContent = secs;
        fmOpenPreviews[sourceId].tick = setInterval(function () {
            secs++;
            box.secs.textContent = secs;
        }, 1000);

        fetch(base + '/api/preview_start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_id: sourceId })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              var state = fmOpenPreviews[sourceId];
              if (!state) return; // chiusa nel frattempo

              if (!d.ok) {
                  clearInterval(state.tick);
                  box.loading.style.display = 'none';
                  box.error.style.display = '';
                  box.error.textContent = d.error || 'Anteprima non disponibile';
                  delete fmOpenPreviews[sourceId];
                  return;
              }

              var player = isVideo ? box.video : box.audio;

              function onReady() {
                  var s = fmOpenPreviews[sourceId];
                  if (s && s.tick) { clearInterval(s.tick); s.tick = null; }
                  box.loading.style.display = 'none';
                  player.style.display = '';
                  var p = player.play();
                  if (p && p.catch) p.catch(function () {});
              }

              if (window.Hls && Hls.isSupported()) {
                  var hls = new Hls({ liveDurationInfinity: true });
                  state.hls = hls;
                  hls.loadSource(d.hls_url);
                  hls.attachMedia(player);
                  hls.on(Hls.Events.MANIFEST_PARSED, onReady);
                  hls.on(Hls.Events.ERROR, function (evt, data) {
                      if (!data.fatal) return;
                      box.loading.style.display = 'none';
                      box.error.style.display = '';
                      box.error.textContent = 'Errore di riproduzione HLS: ' + data.type;
                  });
              } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                  player.src = d.hls_url;
                  player.addEventListener('loadedmetadata', onReady, { once: true });
                  player.addEventListener('error', function () {
                      box.loading.style.display = 'none';
                      box.error.style.display = '';
                      box.error.textContent = 'Il player non è riuscito ad aprire il flusso HLS.';
                  }, { once: true });
              } else {
                  clearInterval(state.tick);
                  box.loading.style.display = 'none';
                  box.error.style.display = '';
                  box.error.textContent = 'Il browser non supporta la riproduzione HLS.';
              }
          }).catch(function () {
              var state = fmOpenPreviews[sourceId];
              if (!state) return;
              clearInterval(state.tick);
              box.loading.style.display = 'none';
              box.error.style.display = '';
              box.error.textContent = 'Errore di rete';
              delete fmOpenPreviews[sourceId];
          });
    }

    // Più anteprime insieme sono ammesse: qui basta sapere se ce n'è ALMENO una,
    // per sospendere il polling che ricaricherebbe la pagina a metà.
    window.fmPreviewActive = function () {
        return Object.keys(fmOpenPreviews).length > 0;
    };

    window.addEventListener('pagehide', function () {
        Object.keys(fmOpenPreviews).forEach(function (sid) { fmCloseInlinePreview(sid, true); });
    });

    // Tasti M/C collegati alla prima registrazione attiva in pagina
    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var first = document.querySelector('.fm-btn-marker');
        if (!first) return;
        var recordingId = first.getAttribute('data-recording-id');
        if (e.key === 'm' || e.key === 'M') fmOpenMarkerModal(recordingId, 'marker');
        if ((e.key === 'c' || e.key === 'C') && first.getAttribute('data-media-type') !== 'clock') fmOpenMarkerModal(recordingId, 'cue');
    });

    // --- Verifica raggiungibilità sorgente (pulsante "check") -----------------
    function fmCheckSource(btn) {
        var box = btn.closest('.fm-card').querySelector('.fm-check-result');
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span uk-spinner="ratio: 0.6"></span>';
        box.style.display = '';
        box.style.color = '#999';
        box.textContent = 'Verifica in corso…';

        fetch(base + '/api/test_source.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_id: btn.getAttribute('data-source-id') })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d.ok) {
                  box.style.color = '#32d296';
                  box.textContent = '✓ Raggiungibile — ' + (d.summary || '');
              } else {
                  box.style.color = '#f0506e';
                  box.textContent = '✗ Non raggiungibile — ' + (d.error || 'errore sconosciuto');
              }
          }).catch(function () {
              box.style.color = '#f0506e';
              box.textContent = '✗ Errore di rete durante la verifica';
          }).then(function () {
              btn.disabled = false;
              btn.innerHTML = original;
          });
    }


    function fmtDur(sec) {
        sec = Math.max(0, sec | 0);
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        function p(n) { return n.toString().padStart(2, '0'); }
        return p(h) + ':' + p(m) + ':' + p(s);
    }

    function fmUpdateLiveProgress() {
        document.querySelectorAll('.fm-live-elapsed[data-start]').forEach(function (el) {
            var startMs = new Date(el.getAttribute('data-start').replace(' ', 'T') + 'Z').getTime();
            var elapsed = (Date.now() - startMs) / 1000;
            el.textContent = fmtDur(elapsed);
            var bar = el.closest('.uk-margin-small-top').querySelector('.fm-live-bar');
            if (bar) bar.value = Math.min(parseInt(bar.max, 10) || 0, elapsed);
        });
    }

    if (document.querySelector('.fm-live-elapsed')) {
        fmUpdateLiveProgress();
        setInterval(fmUpdateLiveProgress, 1000);
    }

    function pollStatus() {
        // Un reload mentre l'anteprima è aperta la ammazzerebbe a metà.
        if (window.fmPreviewActive && window.fmPreviewActive()) return;
        fetch(base + '/api/status.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                var currentIds = (d.recordings || []).map(function (r) { return r.id; }).sort().join(',');
                if (currentIds !== window.__fmLastActiveIds) {
                    if (window.__fmLastActiveIds !== undefined) location.reload();
                    window.__fmLastActiveIds = currentIds;
                }
            }).catch(function () {});
    }

    setInterval(pollStatus, 5000);

    // Keep-alive della sessione: il polling di status.php qui sopra la rinnova
    // già, ma si ferma mentre l'anteprima è aperta e non esiste nelle altre
    // pagine. Questo è esplicito e indipendente (vedi includes/session_modal.php).
    // ⚠️ Dentro DOMContentLoaded: session_modal.php è incluso più in basso, qui
    // fmStartKeepAlive non è ancora definita (stessa trappola del box volumi).
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof window.fmStartKeepAlive === 'function') window.fmStartKeepAlive(300000);
    });
})();
</script>

<?php /* Anteprima inline nella card, non più un modale condiviso: vedi lo script
         qui sopra. hls.js resta lo stesso build 'light' servito da preview_modal.php. */ ?>
<script src="<?= $webBase ?>/assets/vendor/hls.light-1.6.16.min.js"></script>
<?php include __DIR__ . '/includes/session_modal.php'; ?>
<?php include __DIR__ . '/includes/marker_modal.php'; ?>
<?php include __DIR__ . '/includes/foot.php'; ?>
