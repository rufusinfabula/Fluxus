<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

// TRIM/EDIT manuale in quarantena (vedi docs/NOTE-TECNICHE.md): redirect a edit.php
// (che mostra l'avviso di funzionalità in pausa). Codice originale sotto, invariato.
header('Location: ' . rtrim(FM_WEB_BASE, '/') . '/edit.php');
exit;

$db = fmDB();
$markerId = (int)($_GET['marker_id'] ?? 0);
if ($markerId <= 0) { http_response_code(404); exit('Marker non trovato'); }

$stmt = $db->prepare("SELECT m.*, r.source_name, r.filename_base, r.source_id, r.media_type
    FROM markers m JOIN recordings r ON r.id = m.recording_id WHERE m.id = ?");
$stmt->execute([$markerId]);
$marker = $stmt->fetch();
if (!$marker) { http_response_code(404); exit('Marker non trovato'); }
if ($marker['media_type'] !== 'audio' || $marker['clip_status'] !== 'ready') {
    http_response_code(400);
    exit('Trim non disponibile per questo marker');
}

$webBase = rtrim(FM_WEB_BASE, '/');
$clipUrl = $webBase . '/api/download.php?cue_id=' . $markerId;

$pageTitle = 'EDIT — Ritaglio';
include __DIR__ . '/includes/head_dark.php';
?>

<div class="ed-header">
    <div class="ed-header-title"><?= fmH($marker['source_name']) ?> · <?= fmH($marker['elapsed_hms']) ?></div>
    <p class="uk-text-meta" style="color:#666;font-size:11px;margin:6px 0 0;">
        <kbd>Space</kbd> play/pausa · <kbd>I</kbd> imposta inizio · <kbd>O</kbd> imposta fine ·
        <kbd>←</kbd>/<kbd>→</kbd> 50ms (<kbd>Shift</kbd> = 10s) · <kbd>J</kbd>/<kbd>K</kbd>/<kbd>L</kbd> velocità
    </p>
</div>

<div class="ed-card">
    <div class="ed-waveform">
        <div id="fm-editor-waveform"></div>
        <div class="ed-waveform-loading" id="fm-e-loading"><span class="ed-spinner"></span> caricamento…</div>
    </div>

    <div class="ed-transport">
        <button type="button" class="ed-transport-btn" id="fm-e-playpause" uk-tooltip="Play/Pausa (Space)">
            <span uk-icon="icon: play; ratio: 0.9"></span>
        </button>
        <button type="button" class="ed-transport-btn" id="fm-e-set-in" uk-tooltip="Imposta inizio (I)">IN</button>
        <button type="button" class="ed-transport-btn" id="fm-e-set-out" uk-tooltip="Imposta fine (O)">OUT</button>
        <button type="button" class="ed-transport-btn" id="fm-e-j" uk-tooltip="Rallenta (J)">
            <span uk-icon="icon: chevron-left; ratio: 0.8"></span>
        </button>
        <button type="button" class="ed-transport-btn" id="fm-e-k" uk-tooltip="Velocità normale (K)">1x</button>
        <button type="button" class="ed-transport-btn" id="fm-e-l" uk-tooltip="Accelera (L)">
            <span uk-icon="icon: chevron-right; ratio: 0.8"></span>
        </button>
        <button type="button" class="ed-transport-btn" id="fm-e-2x" uk-tooltip="2x">2x</button>
        <span class="ed-pill ed-pill-blue" id="fm-e-speed" style="margin-left:4px;">1x</span>

        <div class="ed-zoom">
            <span uk-icon="icon: search; ratio: 0.7" style="color:#666;"></span>
            <div class="ed-zoom-track" id="fm-e-zoom-track">
                <div class="ed-zoom-thumb" id="fm-e-zoom-thumb" style="left:10%;"></div>
            </div>
        </div>
    </div>

    <div class="ed-io-grid">
        <div class="ed-io-panel">
            <div class="ed-io-label">In</div>
            <div class="ed-io-value" id="fm-e-in">0:00.0</div>
        </div>
        <div class="ed-io-panel">
            <div class="ed-io-label">Out</div>
            <div class="ed-io-value" id="fm-e-out">--:--.-</div>
        </div>
    </div>

    <div class="ed-bar">
        <span id="fm-e-status"></span>
        <button type="button" class="ed-btn ed-btn-primary" id="fm-e-save" style="margin-left:auto;">Salva trim</button>
        <a href="<?= $webBase ?>/edit.php" class="ed-bar-back">&larr; torna a EDIT</a>
    </div>
</div>

<script src="<?= $webBase ?>/assets/vendor/wavesurfer-7.12.11.min.js"></script>
<script src="<?= $webBase ?>/assets/vendor/wavesurfer-regions-7.12.11.min.js"></script>
<script>
(function () {
    var base = <?= json_encode($webBase) ?>;
    var markerId = <?= (int)$markerId ?>;
    var clipUrl = <?= json_encode($clipUrl) ?>;

    var regions = WaveSurfer.Regions.create();
    var ws = WaveSurfer.create({
        container: '#fm-editor-waveform',
        waveColor: '#333',
        progressColor: '#f0506e',
        cursorColor: '#f0506e',
        height: 120,
        url: clipUrl,
        plugins: [regions]
    });

    var region = null;
    var rate = 1;

    function fmtT(t) {
        if (t === null || t === undefined) return '--:--.-';
        var m = Math.floor(t / 60);
        var s = (t % 60).toFixed(1);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function setSpeed(r) {
        rate = r;
        ws.setPlaybackRate(rate);
        document.getElementById('fm-e-speed').textContent = rate + 'x';
    }

    function ensureRegion(start, end) {
        if (region) region.remove();
        region = regions.addRegion({ start: start, end: end, color: 'rgba(240,80,110,0.25)', drag: true, resize: true });
        document.getElementById('fm-e-in').textContent = fmtT(region.start);
        document.getElementById('fm-e-out').textContent = fmtT(region.end);
        region.on('update-end', function () {
            document.getElementById('fm-e-in').textContent = fmtT(region.start);
            document.getElementById('fm-e-out').textContent = fmtT(region.end);
        });
    }

    ws.on('decode', function (duration) {
        ensureRegion(0, duration);
        document.getElementById('fm-e-loading').style.display = 'none';
    });

    document.getElementById('fm-e-playpause').addEventListener('click', function () { ws.playPause(); });
    ws.on('play', function () {
        document.getElementById('fm-e-playpause').classList.add('ed-active');
    });
    ws.on('pause', function () {
        document.getElementById('fm-e-playpause').classList.remove('ed-active');
    });

    document.getElementById('fm-e-set-in').addEventListener('click', function () {
        var cur = ws.getCurrentTime();
        var end = region ? region.end : ws.getDuration();
        ensureRegion(cur, Math.max(end, cur + 0.05));
    });
    document.getElementById('fm-e-set-out').addEventListener('click', function () {
        var cur = ws.getCurrentTime();
        var start = region ? region.start : 0;
        ensureRegion(Math.min(start, cur), cur);
    });

    document.getElementById('fm-e-2x').addEventListener('click', function () {
        setSpeed(rate === 2 ? 1 : 2);
    });
    document.getElementById('fm-e-j').addEventListener('click', function () { setSpeed(Math.max(0.25, rate - 0.25)); });
    document.getElementById('fm-e-k').addEventListener('click', function () { setSpeed(1); });
    document.getElementById('fm-e-l').addEventListener('click', function () { setSpeed(Math.min(4, rate + 0.25)); });

    // Zoom slider (drag)
    var zoomTrack = document.getElementById('fm-e-zoom-track');
    var zoomThumb = document.getElementById('fm-e-zoom-thumb');
    function setZoomFromClientX(clientX) {
        var rect = zoomTrack.getBoundingClientRect();
        var pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        zoomThumb.style.left = (pct * 100) + '%';
        ws.zoom(10 + pct * 190);
    }
    var dragging = false;
    zoomTrack.addEventListener('mousedown', function (e) { dragging = true; setZoomFromClientX(e.clientX); });
    document.addEventListener('mousemove', function (e) { if (dragging) setZoomFromClientX(e.clientX); });
    document.addEventListener('mouseup', function () { dragging = false; });

    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var cur = ws.getCurrentTime();
        var dur = ws.getDuration();

        if (e.code === 'Space') { e.preventDefault(); ws.playPause(); }

        if (e.key === 'i' || e.key === 'I') {
            var end = region ? region.end : dur;
            ensureRegion(cur, Math.max(end, cur + 0.05));
        }
        if (e.key === 'o' || e.key === 'O') {
            var start = region ? region.start : 0;
            ensureRegion(Math.min(start, cur), cur);
        }

        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            var step = e.shiftKey ? 10 : 0.05;
            var delta = (e.key === 'ArrowLeft' ? -1 : 1) * step;
            ws.setTime(Math.max(0, Math.min(dur, cur + delta)));
        }

        if (e.key === 'j' || e.key === 'J') setSpeed(Math.max(0.25, rate - 0.25));
        if (e.key === 'k' || e.key === 'K') setSpeed(1);
        if (e.key === 'l' || e.key === 'L') setSpeed(Math.min(4, rate + 0.25));
    });

    document.getElementById('fm-e-save').addEventListener('click', function () {
        if (!region) { return; }
        var btn = document.getElementById('fm-e-save');
        var status = document.getElementById('fm-e-status');
        btn.disabled = true;
        status.innerHTML = '<span class="ed-spinner"></span>';
        fetch(base + '/api/trim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cue', marker_id: markerId, start: region.start, end: region.end })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              btn.disabled = false;
              if (d.ok) {
                  status.innerHTML = '<span class="ed-status ed-status-ok">Trim salvato</span>';
              } else {
                  status.innerHTML = '<span class="ed-status ed-status-error">' + (d.error || 'Errore salvataggio') + '</span>';
              }
          })
          .catch(function () {
              btn.disabled = false;
              status.innerHTML = '<span class="ed-status ed-status-error">Errore di rete</span>';
          });
    });
})();
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
