<?php
/**
 * Avviso bloccante di sessione scaduta + helper condivisi per le chiamate API.
 *
 * Incluso dalle pagine da cui si creano marker/cue (dashboard.php,
 * recording.php). Risolve il problema per cui una chiamata fallita faceva
 * perdere il marker in silenzio: qui ogni fallimento diventa visibile, il
 * contenuto non salvato resta a schermo e si può ritentare CONSERVANDO
 * l'istante del click, che è l'unica cosa davvero irrecuperabile.
 */
$fmSessWebBase = rtrim(FM_WEB_BASE, '/');
?>
<div id="fm-session-modal" uk-modal="bg-close: false; esc-close: false">
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
        <h3 class="uk-modal-title" style="color:#f0506e;">Sessione scaduta</h3>

        <div class="uk-alert-danger" uk-alert>
            <p class="uk-margin-remove"><strong id="fm-session-lost">Il marker NON è stato salvato.</strong></p>
        </div>

        <p class="uk-text-small">
            L'accesso non è più valido, quindi il server ha rifiutato il salvataggio.
            La registrazione <strong>non è stata interrotta</strong> e l'audio/video continua
            a essere registrato per intero: rifai il login e riprova, l'istante del click
            viene conservato.
        </p>

        <div id="fm-session-retry-hint" class="uk-text-small uk-text-success" style="display:none;">
            Accesso ripristinato: puoi salvare.
        </div>

        <div class="uk-flex uk-flex-right uk-margin-top" style="gap:8px;">
            <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-session-login-btn">Rifai login</button>
            <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-session-retry-btn" disabled>Riprova salvataggio</button>
        </div>
        <div class="uk-text-meta uk-margin-small-top" style="font-size:11px;">
            Chiudere questo avviso senza salvare perde il marker: usalo solo se non ti serve.
            <a href="#" id="fm-session-dismiss">Chiudi comunque</a>
        </div>
    </div>
</div>

<script>
(function () {
    var base = <?= json_encode($fmSessWebBase) ?>;
    var pendingRetry = null;   // funzione da rieseguire dopo il login
    var loginPoll = null;

    /**
     * POST JSON con gli errori che NON spariscono.
     * Rifiuta con un oggetto {kind: 'session'|'network'|'server', message}.
     */
    window.fmApiPost = function (path, body) {
        return fetch(base + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            if (r.status === 401) {
                return Promise.reject({ kind: 'session', message: 'Sessione scaduta' });
            }
            return r.text().then(function (txt) {
                var d;
                try {
                    d = JSON.parse(txt);
                } catch (e) {
                    // Risposta non JSON: tipicamente una pagina HTML di errore o
                    // di login. Prima veniva ingoiata da un catch vuoto.
                    return Promise.reject({ kind: 'server', message: 'Risposta non valida dal server (HTTP ' + r.status + ')' });
                }
                if (!r.ok || !d.ok) {
                    if (d && d.session_expired) return Promise.reject({ kind: 'session', message: d.error || 'Sessione scaduta' });
                    return Promise.reject({ kind: 'server', message: (d && d.error) || ('Errore del server (HTTP ' + r.status + ')') });
                }
                return d;
            });
        }, function () {
            return Promise.reject({ kind: 'network', message: 'Il server non risponde (rete o servizio giù)' });
        });
    };

    /**
     * Mostra l'avviso bloccante. `descrizione` dice esattamente cosa non è
     * stato salvato; `retry` è la funzione che rifà il salvataggio con gli
     * stessi dati, incluso l'istante originale del click.
     */
    window.fmSessionExpired = function (descrizione, retry) {
        pendingRetry = retry || null;
        document.getElementById('fm-session-lost').textContent = descrizione;
        document.getElementById('fm-session-retry-btn').disabled = true;
        document.getElementById('fm-session-retry-hint').style.display = 'none';
        UIkit.modal('#fm-session-modal').show();
        startLoginPoll();
    };

    // Finché l'avviso è aperto si controlla ogni 3s se l'accesso è tornato
    // valido: appena lo è, il pulsante di salvataggio si sblocca da solo.
    function startLoginPoll() {
        if (loginPoll) return;
        loginPoll = setInterval(function () {
            fetch(base + '/api/ping.php', { headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 200) {
                        document.getElementById('fm-session-retry-btn').disabled = false;
                        document.getElementById('fm-session-retry-hint').style.display = '';
                        stopLoginPoll();
                    }
                }).catch(function () {});
        }, 3000);
    }

    function stopLoginPoll() {
        if (loginPoll) { clearInterval(loginPoll); loginPoll = null; }
    }

    document.getElementById('fm-session-login-btn').addEventListener('click', function () {
        // Finestra separata: la pagina corrente non si ricarica, quindi non si
        // perde né il modale né il marker in attesa.
        window.open(base + '/login.php', 'fluxus_login', 'width=460,height=560');
        startLoginPoll();
    });

    document.getElementById('fm-session-retry-btn').addEventListener('click', function () {
        var retry = pendingRetry;
        pendingRetry = null;
        stopLoginPoll();
        UIkit.modal('#fm-session-modal').hide();
        if (typeof retry === 'function') retry();
    });

    document.getElementById('fm-session-dismiss').addEventListener('click', function (e) {
        e.preventDefault();
        pendingRetry = null;
        stopLoginPoll();
        UIkit.modal('#fm-session-modal').hide();
    });

    /**
     * Keep-alive: mentre la pagina resta aperta si rinnova la sessione. Il
     * polling di status.php (5s) di fatto già la rinnova, ma solo in dashboard
     * e solo finché non viene sospeso; questo è esplicito e vale ovunque.
     * Se la sessione è già scaduta si avvisa SUBITO, prima che l'operatore
     * prema Marker e perda un cue.
     */
    window.fmStartKeepAlive = function (intervalMs) {
        setInterval(function () {
            fetch(base + '/api/ping.php', { headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) {
                        window.fmSessionExpired('Sessione scaduta: da ora i marker NON verrebbero salvati.', null);
                    }
                }).catch(function () {});
        }, intervalMs || 300000);   // 5 minuti, molto sotto qualunque scadenza
    };
})();
</script>
