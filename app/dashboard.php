<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$webBase = rtrim(FM_WEB_BASE, '/');

$sources = $db->query("SELECT * FROM sources WHERE active = 1 ORDER BY name ASC")->fetchAll();

$activeStmt = $db->prepare("SELECT id, start_time FROM recordings WHERE source_id = ? AND status = 'recording' ORDER BY id DESC LIMIT 1");
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
    </div>
</div>

<div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@l uk-margin-bottom" uk-grid id="fm-source-grid">
<?php if (empty($sources)): ?>
    <div><p class="uk-text-meta">Nessuna sorgente configurata. Vai su <a href="<?= $webBase ?>/sources.php">Sorgenti</a> per aggiungerne una.</p></div>
<?php endif; ?>
<?php foreach ($sources as $s):
    $isVideo = $s['media_type'] === 'video';
    $rec = $s['_active_recording'];
    $last = $s['_last_recording'];
?>
    <div class="fm-source-card" data-media-type="<?= $s['media_type'] ?>">
        <div class="uk-card uk-card-default uk-card-body fm-card<?= $rec ? ' fm-card-active' : '' ?>">
            <div class="uk-flex uk-flex-middle uk-flex-between">
                <h3 class="uk-card-title uk-margin-remove uk-flex uk-flex-middle" style="gap:8px;flex-wrap:wrap;">
                    <?= fmH($s['name']) ?>
                    <span class="<?= $isVideo ? 'fm-badge-video' : 'fm-badge-audio' ?>"><?= $isVideo ? 'VIDEO' : 'AUDIO' ?></span>
                </h3>
                <?php if ($rec): ?>
                <span class="uk-badge fm-badge-live fm-pulse"><span class="fm-rec-dot" style="background:#fff;"></span>REC</span>
                <?php else: ?>
                <span class="fm-idle-badge">Idle</span>
                <?php endif; ?>
            </div>
            <div class="fm-card-meta uk-margin-small-top fm-mono"><?= fmH($s['url'] ?: $s['device'] ?: '—') ?></div>
            <?php if ($last): ?>
            <div class="uk-text-meta" style="font-size:12px;margin-top:6px;">
                Ultima: <span class="fm-mono"><?= fmH($last['filename_base']) ?></span> · <?= fmH(fmFormatDateTimeShort($last['start_time'])) ?>
            </div>
            <?php endif; ?>

            <div class="uk-flex uk-flex-middle uk-margin-top" style="gap:8px;flex-wrap:wrap;">
                <?php if ($rec): ?>
                <a href="<?= $webBase ?>/recording.php?id=<?= fmRecCode((int)$rec['id'], $s['media_type']) ?>" class="uk-button uk-button-danger uk-button-small fm-pulse">
                    <span class="fm-rec-dot-btn"></span>REC
                </a>
                <button class="uk-button uk-button-primary uk-button-small fm-btn-marker" data-recording-id="<?= (int)$rec['id'] ?>">Marker <kbd>M</kbd></button>
                <button class="uk-button uk-button-small fm-btn-cue-btn" style="background:#1a1a1a;border-color:#444;color:#e0e0e0;" data-recording-id="<?= (int)$rec['id'] ?>"><span uk-icon="icon: nut; ratio: 0.8"></span> Cue <kbd>C</kbd></button>
                <?php else: ?>
                <button class="uk-button uk-button-danger uk-button-small fm-btn-rec" data-source-id="<?= (int)$s['id'] ?>">
                    <span class="fm-rec-dot-btn"></span>REC
                </button>
                <?php endif; ?>

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

                <a href="<?= $webBase ?>/recordings.php?source_id=<?= (int)$s['id'] ?>" class="fm-icon-btn" uk-tooltip="Registrazioni">
                    <span uk-icon="icon: album; ratio: 0.9"></span>
                </a>
            </div>

            <div class="fm-check-result" style="display:none;margin-top:8px;font-size:12px;line-height:1.4;"></div>

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
<div class="uk-overflow-auto uk-margin-bottom">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead><tr><th>Sorgente</th><th>Etichetta</th><th>OnCalendar</th><th style="width:150px;">Prossima</th></tr></thead>
    <tbody>
    <?php if (empty($upcoming)): ?>
        <tr><td colspan="4" class="uk-text-meta">Nessun orario attivo in programma.</td></tr>
    <?php endif; ?>
    <?php foreach ($upcoming as $u): $isV = $u['media_type'] === 'video'; ?>
        <tr>
            <td>
                <?= fmH($u['source_name']) ?>
                <span class="<?= $isV ? 'fm-badge-video' : 'fm-badge-audio' ?>" style="margin-left:6px;"><?= $isV ? 'VIDEO' : 'AUDIO' ?></span>
            </td>
            <td><?= fmH($u['label'] ?: '—') ?></td>
            <td><span class="fm-prefix-chip"><?= fmH($u['on_calendar']) ?></span></td>
            <td class="fm-mono" style="font-size:12px;color:#1e87f0;"><?= fmH(fmFormatTs($u['next'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

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
        $isVideo = $r['media_type'] === 'video';
        [$statusLabel, $statusColor] = $statusMeta[$r['status']] ?? [$r['status'], '#999'];
    ?>
        <tr data-media-type="<?= $r['media_type'] ?>" class="fm-row-clickable" onclick="location.href='<?= $webBase ?>/recording.php?id=<?= fmRecCode((int)$r['id'], $r['media_type']) ?>'">
            <td><span class="<?= $isVideo ? 'fm-badge-video' : 'fm-badge-audio' ?>"><?= $isVideo ? 'VIDEO' : 'AUDIO' ?></span></td>
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

    document.addEventListener('click', function (e) {
        var recBtn = e.target.closest('.fm-btn-rec');
        if (recBtn) { fmStartRecording(recBtn.getAttribute('data-source-id'), recBtn); return; }

        var prevBtn = e.target.closest('.fm-btn-preview');
        if (prevBtn) {
            window.fmOpenPreview(
                prevBtn.getAttribute('data-source-id'),
                prevBtn.getAttribute('data-source-name'),
                prevBtn.getAttribute('data-media-type')
            );
            return;
        }

        var checkBtn = e.target.closest('.fm-btn-check');
        if (checkBtn) { fmCheckSource(checkBtn); return; }

        var mBtn = e.target.closest('.fm-btn-marker');
        if (mBtn) { fmOpenMarkerModal(mBtn.getAttribute('data-recording-id'), 'marker'); return; }
        var cBtn = e.target.closest('.fm-btn-cue-btn');
        if (cBtn) { fmOpenMarkerModal(cBtn.getAttribute('data-recording-id'), 'cue'); return; }
    });

    // Tasti M/C collegati alla prima registrazione attiva in pagina
    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var first = document.querySelector('.fm-btn-marker');
        if (!first) return;
        var recordingId = first.getAttribute('data-recording-id');
        if (e.key === 'm' || e.key === 'M') fmOpenMarkerModal(recordingId, 'marker');
        if (e.key === 'c' || e.key === 'C') fmOpenMarkerModal(recordingId, 'cue');
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

<?php include __DIR__ . '/includes/preview_modal.php'; ?>
<?php include __DIR__ . '/includes/session_modal.php'; ?>
<?php include __DIR__ . '/includes/marker_modal.php'; ?>
<?php include __DIR__ . '/includes/foot.php'; ?>
