<?php
/** Modal condiviso per creare Marker/Cue con etichetta, salvataggio automatico dopo
 *  N secondi di inattività (N = settings.marker_autosave_seconds, configurabile in
 *  Impostazioni, default 8). Se l'etichetta resta vuota, il default lato server è
 *  marker_ID / cue_ID (ID assegnato dal DB, vedi fmCreateMarker in helpers.php).
 *
 *  L'istante del marker è quello in cui è stato premuto il pulsante, non quello in
 *  cui parte il salvataggio: si manda `clicked_at` al server, come già fa Fluxus
 *  Remote. Senza, gli 8s di attesa dell'autosalvataggio finivano dentro la
 *  posizione del cue.
 *
 *  ⚠️ Il modale NON si chiude prima di sapere com'è andata: fino al 2026-07-30 si
 *  chiudeva subito e l'errore finiva in un `catch` vuoto, quindi un salvataggio
 *  fallito era indistinguibile da uno riuscito. Richiede includes/session_modal.php
 *  nella stessa pagina (fmApiPost / fmSessionExpired). */
$fmModalWebBase = rtrim(FM_WEB_BASE, '/');
$fmMarkerAutosaveSeconds = (int)fmSetting('marker_autosave_seconds', '8');
?>
<div id="fm-marker-modal" uk-modal="bg-close: false; esc-close: false">
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical fm-modal-wide">
        <h3 class="uk-modal-title" id="fm-marker-modal-title">Nuovo Marker</h3>
        <input type="text" class="uk-input" id="fm-marker-label-input" placeholder="Etichetta (opzionale)" maxlength="120" autocomplete="off">
        <progress class="uk-progress fm-progress" id="fm-marker-countdown-bar" value="1" max="1" style="height:4px;margin:10px 0 2px;"></progress>
        <div class="uk-text-meta" id="fm-marker-countdown" style="font-size:11px;"></div>

        <div class="uk-alert-danger uk-margin-small-top" id="fm-marker-error" style="display:none;" uk-alert>
            <p class="uk-margin-remove uk-text-small"><strong>Marker NON salvato.</strong> <span id="fm-marker-error-msg"></span></p>
        </div>

        <div class="uk-flex uk-flex-right uk-margin-top" style="gap:8px;">
            <button type="button" class="uk-button uk-button-default uk-button-small" onclick="fmCancelMarkerModal()">Annulla</button>
            <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-marker-save-btn" onclick="fmConfirmMarkerModal()">Salva</button>
        </div>
    </div>
</div>

<script>
(function () {
    var base = <?= json_encode($fmModalWebBase) ?>;
    var autosaveSeconds = <?= (int)$fmMarkerAutosaveSeconds ?>;
    var pending = null; // { recordingId, type, clickedAt }
    var countdownTimer = null;
    var secondsLeft = autosaveSeconds;

    function tickCountdown() {
        secondsLeft--;
        var el = document.getElementById('fm-marker-countdown');
        var bar = document.getElementById('fm-marker-countdown-bar');
        if (secondsLeft <= 0) {
            el.textContent = '';
            bar.value = 0;
            clearInterval(countdownTimer);
            countdownTimer = null;
            doSave();
            return;
        }
        el.textContent = 'Salvataggio automatico tra ' + secondsLeft + 's di inattività…';
        bar.value = secondsLeft;
    }

    function resetCountdown() {
        secondsLeft = autosaveSeconds;
        document.getElementById('fm-marker-countdown').textContent = 'Salvataggio automatico tra ' + autosaveSeconds + 's di inattività…';
        var bar = document.getElementById('fm-marker-countdown-bar');
        bar.max = autosaveSeconds;
        bar.value = autosaveSeconds;
        if (countdownTimer) clearInterval(countdownTimer);
        countdownTimer = setInterval(tickCountdown, 1000);
    }

    function stopCountdown() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    }

    function showError(msg) {
        document.getElementById('fm-marker-error-msg').textContent = msg;
        document.getElementById('fm-marker-error').style.display = '';
    }

    function clearError() {
        document.getElementById('fm-marker-error').style.display = 'none';
    }

    function setSaving(on) {
        var btn = document.getElementById('fm-marker-save-btn');
        btn.disabled = on;
        btn.textContent = on ? 'Salvataggio…' : 'Salva';
    }

    function doSave() {
        if (!pending) return;
        var label = document.getElementById('fm-marker-label-input').value.trim();
        var p = pending;
        stopCountdown();
        clearError();
        setSaving(true);
        document.getElementById('fm-marker-countdown').textContent = '';

        var payload = {
            recording_id: p.recordingId,
            type: p.type,
            label: label,
            clicked_at: p.clickedAt      // epoch (s): posizione reale del click
        };

        window.fmApiPost('/api/marker.php', payload)
            .then(function (d) {
                // Solo qui il marker esiste davvero: prima di questo punto il
                // modale resta aperto e il testo digitato non si perde.
                pending = null;
                setSaving(false);
                UIkit.modal('#fm-marker-modal').hide();
                if (typeof window.fmOnMarkerSaved === 'function') window.fmOnMarkerSaved(p, d);
            })
            .catch(function (err) {
                setSaving(false);
                if (err && err.kind === 'session') {
                    // L'avviso bloccante prende il posto di questo modale e sa
                    // come ritentare con lo stesso istante di click.
                    UIkit.modal('#fm-marker-modal').hide();
                    var che = (p.type === 'cue' ? 'Il CUE' : 'Il MARKER')
                        + (label ? ' «' + label + '»' : '')
                        + ' delle ' + new Date(p.clickedAt * 1000).toLocaleTimeString()
                        + ' NON è stato salvato.';
                    window.fmSessionExpired(che, function () {
                        pending = p;
                        document.getElementById('fm-marker-label-input').value = label;
                        UIkit.modal('#fm-marker-modal').show();
                        doSave();
                    });
                    return;
                }
                // Rete o server: il modale resta aperto con l'errore in chiaro e
                // il pulsante Salva pronto a ritentare, senza countdown.
                showError((err && err.message ? err.message : 'Errore sconosciuto') + ' — premi Salva per riprovare.');
            });
    }

    window.fmOpenMarkerModal = function (recordingId, type) {
        // L'istante si fissa ORA, all'apertura: è il momento che l'operatore
        // voleva marcare, non quello in cui finirà di scrivere l'etichetta.
        pending = { recordingId: recordingId, type: type, clickedAt: Math.floor(Date.now() / 1000) };
        document.getElementById('fm-marker-modal-title').textContent = type === 'cue' ? 'Nuovo Cue' : 'Nuovo Marker';
        var input = document.getElementById('fm-marker-label-input');
        input.value = '';
        clearError();
        setSaving(false);
        UIkit.modal('#fm-marker-modal').show();
        setTimeout(function () { input.focus(); }, 50);
        resetCountdown();
    };

    window.fmConfirmMarkerModal = function () {
        doSave();
    };

    window.fmCancelMarkerModal = function () {
        pending = null;
        stopCountdown();
        clearError();
        UIkit.modal('#fm-marker-modal').hide();
    };

    document.getElementById('fm-marker-label-input').addEventListener('input', resetCountdown);
    document.getElementById('fm-marker-label-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doSave(); }
    });
})();
</script>
