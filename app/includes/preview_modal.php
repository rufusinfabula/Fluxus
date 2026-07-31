<?php
/**
 * Modale di anteprima live di una sorgente (audio o video), condiviso fra
 * dashboard.php e recording.php.
 *
 * Espone due funzioni globali:
 *   window.fmOpenPreview(sourceId, sourceName, mediaType)
 *   window.fmPreviewActive()  → true se una preview è aperta
 *
 * Il flusso arriva da api/preview_start.php, che avvia un relay ffmpeg→HLS
 * locale (scripts/preview.sh). Vedi quel file per il motivo per cui non si
 * usa più il muxer HLS di MediaMTX.
 */
$fmPreviewWebBase = rtrim(FM_WEB_BASE, '/');
?>
<div id="fm-preview-modal" uk-modal>
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical fm-modal-wide">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <h3 class="uk-modal-title" id="fm-preview-title" style="font-size:16px;margin-bottom:12px;">Anteprima live</h3>

        <div id="fm-preview-loading" style="padding:44px 0;text-align:center;">
            <span uk-spinner="ratio: 1.6"></span>
            <div class="uk-text-meta uk-margin-small-top" id="fm-preview-loading-text">Connessione alla sorgente…</div>
        </div>

        <div id="fm-preview-error" class="uk-alert uk-alert-danger" style="display:none;font-size:13px;word-break:break-word;"></div>

        <video id="fm-preview-video" style="width:100%;max-height:70vh;background:#000;border-radius:4px;display:none;" controls playsinline></video>
        <audio id="fm-preview-audio" style="width:100%;display:none;" controls></audio>

        <div id="fm-preview-note" class="uk-text-meta" style="display:none;margin-top:8px;font-size:12px;"></div>
    </div>
</div>

<?php /* Build 'light' di hls.js: senza DRM, tracce audio alternative e sottotitoli,
         che questo flusso non ha — preview.sh produce un HLS mpegts a variante
         singola. Servita da Fluxus, non da CDN: assets/vendor/README.md. */ ?>
<script src="<?= $fmPreviewWebBase ?>/assets/vendor/hls.light-1.6.16.min.js"></script>
<script>
(function () {
    var base = <?= json_encode($fmPreviewWebBase) ?>;
    var previewSourceId = null;
    var previewHls = null;
    var previewTick = null;

    function els() {
        return {
            loading: document.getElementById('fm-preview-loading'),
            loadingText: document.getElementById('fm-preview-loading-text'),
            error: document.getElementById('fm-preview-error'),
            video: document.getElementById('fm-preview-video'),
            audio: document.getElementById('fm-preview-audio'),
            note: document.getElementById('fm-preview-note'),
            title: document.getElementById('fm-preview-title')
        };
    }

    function showError(msg) {
        var el = els();
        if (previewTick) { clearInterval(previewTick); previewTick = null; }
        el.loading.style.display = 'none';
        el.error.style.display = '';
        el.error.textContent = msg;
    }

    // Il click sul pulsante è un gesto utente, quindi l'autoplay con audio è
    // normalmente concesso. Se il browser lo rifiuta comunque si riparte muti,
    // invece di lasciare un player fermo senza spiegazione.
    function playSafely(player, note) {
        var p = player.play();
        if (p && p.catch) {
            p.catch(function () {
                player.muted = true;
                player.play().catch(function () {});
                note.style.display = '';
                note.textContent = 'Il browser ha bloccato l’audio automatico: riattivalo dai controlli del player.';
            });
        }
    }

    function openPreview(sourceId, sourceName, mediaType) {
        var el = els();
        var isVideo = mediaType === 'video';

        el.title.textContent = 'Anteprima live — ' + (sourceName || '');
        el.error.style.display = 'none';
        el.note.style.display = 'none';
        el.video.style.display = 'none';
        el.audio.style.display = 'none';
        el.loading.style.display = '';
        el.loadingText.textContent = 'Connessione alla sorgente…';

        UIkit.modal('#fm-preview-modal').show();

        // Collegarsi a uno stream live e produrre il primo segmento HLS
        // richiede qualche secondo: il contatore evita che sembri bloccato.
        var elapsed = 0;
        if (previewTick) clearInterval(previewTick);
        previewTick = setInterval(function () {
            elapsed++;
            el.loadingText.textContent = 'Connessione alla sorgente… ' + elapsed + 's';
        }, 1000);

        previewSourceId = sourceId;

        fetch(base + '/api/preview_start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source_id: sourceId })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.ok) { showError(d.error || 'Anteprima non disponibile'); return; }

              var player = isVideo ? el.video : el.audio;

              function onReady() {
                  if (previewTick) { clearInterval(previewTick); previewTick = null; }
                  el.loading.style.display = 'none';
                  player.style.display = '';
                  playSafely(player, el.note);
              }

              if (window.Hls && Hls.isSupported()) {
                  previewHls = new Hls({ liveDurationInfinity: true });
                  previewHls.loadSource(d.hls_url);
                  previewHls.attachMedia(player);
                  previewHls.on(Hls.Events.MANIFEST_PARSED, onReady);
                  previewHls.on(Hls.Events.ERROR, function (evt, data) {
                      if (data.fatal) showError('Errore di riproduzione HLS: ' + data.type + ' / ' + (data.details || ''));
                  });
              } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                  // Safari: HLS nativo.
                  player.src = d.hls_url;
                  player.addEventListener('loadedmetadata', onReady, { once: true });
                  player.addEventListener('error', function () {
                      showError('Il player non è riuscito ad aprire il flusso HLS.');
                  }, { once: true });
              } else {
                  showError('Il browser non supporta la riproduzione HLS.');
              }
          }).catch(function () { showError('Errore di rete'); });
    }

    function closePreview() {
        var el = els();
        if (previewTick) { clearInterval(previewTick); previewTick = null; }
        [el.video, el.audio].forEach(function (p) {
            p.pause();
            p.removeAttribute('src');
            p.muted = false;
            p.load();
        });
        if (previewHls) { previewHls.destroy(); previewHls = null; }
        if (previewSourceId) {
            var payload = JSON.stringify({ source_id: previewSourceId });
            previewSourceId = null;
            navigator.sendBeacon
                ? navigator.sendBeacon(base + '/api/preview_stop.php', new Blob([payload], { type: 'application/json' }))
                : fetch(base + '/api/preview_stop.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
        }
    }

    document.addEventListener('hidden', function (e) {
        if (e.target && e.target.id === 'fm-preview-modal') closePreview();
    }, true);

    window.addEventListener('pagehide', closePreview);

    window.fmOpenPreview = openPreview;
    window.fmPreviewActive = function () { return previewSourceId !== null; };
})();
</script>
