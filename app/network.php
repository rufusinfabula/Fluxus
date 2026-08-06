<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

// Raggiungibile anche dall'icona in navbar, oltre che dal pulsante
// "Gestisci rete" della card Rete in Impostazioni — stesso schema già in
// uso fra la barra di stato (riepilogo) e Impostazioni → Archiviazione
// (gestione).
$pageTitle = 'Rete';
include __DIR__ . '/includes/head.php';
?>

<h2 class="fm-page-title"><span uk-icon="icon: world"></span> Rete</h2>
<p class="uk-text-meta uk-margin-remove-top">
    <a href="<?= rtrim(FM_WEB_BASE, '/') ?>/settings.php"><span uk-icon="icon: chevron-left; ratio: 0.8"></span> Impostazioni</a>
</p>

<div id="fm-net-pending-banner" class="uk-alert-warning" uk-alert hidden>
    <span uk-icon="icon: warning"></span>
    <span id="fm-net-pending-text"></span>
    <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-net-confirm-btn" style="margin-left:10px;">Conferma questa rete</button>
</div>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Stato</h3>
    <div class="uk-grid-small" uk-grid>
        <div class="uk-width-1-2@m">
            <div class="uk-text-meta">Rete / interfaccia</div>
            <div class="fm-mono" id="fm-net-conn">—</div>
        </div>
        <div class="uk-width-1-2@m">
            <div class="uk-text-meta">Indirizzo IP</div>
            <div class="fm-mono" id="fm-net-addr">—</div>
        </div>
        <div class="uk-width-1-2@m">
            <div class="uk-text-meta">Gateway</div>
            <div class="fm-mono" id="fm-net-gw">—</div>
        </div>
        <div class="uk-width-1-2@m">
            <div class="uk-text-meta">DNS</div>
            <div class="fm-mono" id="fm-net-dns">—</div>
        </div>
    </div>
</div>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Reti WiFi</h3>
    <p class="uk-text-meta uk-margin-remove-top">La connessione a una nuova rete si applica subito: hai qualche secondo per verificarla, altrimenti torna da sola a quella di prima.</p>
    <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-net-scan-btn">Cerca reti</button>
    <div id="fm-net-wifi-list" class="uk-margin-small-top"></div>
</div>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Indirizzo IP</h3>
    <p class="uk-text-meta uk-margin-remove-top">Anche qui: la modifica si applica subito e torna indietro da sola se non la confermi.</p>
    <label class="uk-margin-small-right"><input class="uk-radio" type="radio" name="fm-net-ip-mode" value="auto"> Automatico (DHCP)</label>
    <label><input class="uk-radio" type="radio" name="fm-net-ip-mode" value="manual"> Manuale</label>
    <div id="fm-net-ip-manual" class="uk-grid-small uk-margin-small-top" uk-grid hidden>
        <div class="uk-width-1-4@m">
            <label class="uk-form-label">Indirizzo</label>
            <input class="uk-input uk-form-small fm-mono" type="text" id="fm-net-ip-address" placeholder="192.168.1.50">
        </div>
        <div class="uk-width-1-6@m">
            <label class="uk-form-label">Prefisso</label>
            <input class="uk-input uk-form-small fm-mono" type="number" id="fm-net-ip-prefix" min="1" max="32" placeholder="24">
        </div>
        <div class="uk-width-1-4@m">
            <label class="uk-form-label">Gateway</label>
            <input class="uk-input uk-form-small fm-mono" type="text" id="fm-net-ip-gateway" placeholder="192.168.1.1">
        </div>
        <div class="uk-width-1-3@m">
            <label class="uk-form-label">DNS (separati da virgola)</label>
            <input class="uk-input uk-form-small fm-mono" type="text" id="fm-net-ip-dns" placeholder="192.168.1.1, 8.8.8.8">
        </div>
    </div>
    <div class="uk-margin-top">
        <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-net-ip-save-btn">Applica</button>
        <span id="fm-net-ip-hint" class="uk-margin-small-left"></span>
    </div>
</div>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Hotspot di configurazione</h3>
    <p class="uk-text-meta uk-margin-remove-top">Se al prossimo riavvio la macchina non trova nessuna rete nota, ne apre una propria a cui collegarsi col telefono. Si può anche attivarla a mano da qui, ad esempio se il router è cambiato ed è irraggiungibile: si chiude da sola dopo 15 minuti se nessuno la configura.</p>
    <div id="fm-net-hotspot-active" class="uk-margin-small-bottom" hidden>
        <span class="uk-label uk-label-warning">attivo</span>
        <span class="fm-mono" id="fm-net-hotspot-ssid"></span>
        &middot; si chiude fra <span id="fm-net-hotspot-timeout" class="fm-mono"></span>
    </div>
    <button type="button" class="uk-button uk-button-default uk-button-small" id="fm-net-hotspot-start-btn">Attiva hotspot</button>
    <button type="button" class="uk-button uk-button-danger uk-button-small" id="fm-net-hotspot-stop-btn" hidden>Disattiva hotspot</button>
</div>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Nome macchina</h3>
    <p class="uk-text-meta uk-margin-remove-top">Il nome host del sistema operativo. Non c'entra col «Nome nodo» di Impostazioni, che identifica questa installazione a Fluxus Remote: sono due valori diversi. Non taglia la connessione, quindi qui non c'è ripristino automatico.</p>
    <div class="uk-flex uk-flex-middle" style="gap:8px;">
        <input class="uk-input uk-form-small fm-mono" type="text" id="fm-net-hostname" style="max-width:260px;">
        <button type="button" class="uk-button uk-button-primary uk-button-small" id="fm-net-hostname-btn">Salva</button>
        <span id="fm-net-hostname-hint"></span>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var base = <?= json_encode(rtrim(FM_WEB_BASE, '/')) ?>;
    var pollTimer = null;
    var lastStatus = null;

    function post(url, payload) {
        return fetch(base + url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw new Error(d.error || ('http ' + r.status));
                return d;
            });
        });
    }

    function schedulePoll(ms) {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(loadStatus, ms);
    }

    function renderPending(pendingSeconds) {
        var banner = document.getElementById('fm-net-pending-banner');
        var text = document.getElementById('fm-net-pending-text');
        if (pendingSeconds === null || pendingSeconds === undefined) {
            banner.hidden = true;
            return;
        }
        banner.hidden = false;
        text.textContent = pendingSeconds > 0
            ? 'Verifica che la nuova rete funzioni: fra ' + pendingSeconds + 's, se non confermi, torna quella precedente.'
            : 'Ripristino in corso…';
    }

    function loadStatus() {
        fetch(base + '/api/network_status.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                lastStatus = d;
                document.getElementById('fm-net-conn').textContent = (d.ssid || d.connection || d.device || 'non collegato');
                document.getElementById('fm-net-addr').textContent = d.address || '—';
                document.getElementById('fm-net-gw').textContent = d.gateway || '—';
                document.getElementById('fm-net-dns').textContent = d.dns || '—';

                if (!document.getElementById('fm-net-ip-address').matches(':focus')) {
                    var radios = document.getElementsByName('fm-net-ip-mode');
                    radios.forEach(function (r) { r.checked = (r.value === (d.method || 'auto')); });
                    document.getElementById('fm-net-ip-manual').hidden = (d.method !== 'manual');
                    if (d.method === 'manual' && d.address) {
                        document.getElementById('fm-net-ip-address').value = d.address;
                        document.getElementById('fm-net-ip-gateway').value = d.gateway || '';
                        document.getElementById('fm-net-ip-dns').value = d.dns || '';
                    }
                }
                var hostnameField = document.getElementById('fm-net-hostname');
                if (!hostnameField.matches(':focus') && !hostnameField.value && d.hostname) {
                    hostnameField.value = d.hostname;
                }

                renderPending(d.pending);
                renderHotspot(d.hotspot, d.hotspot_ssid, d.hotspot_timeout);
                schedulePoll(d.pending !== null && d.pending !== undefined ? 3000 : 15000);
            })
            .catch(function () {
                schedulePoll(15000);
            });
    }

    document.getElementById('fm-net-confirm-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        post('/api/network_confirm.php').then(function () {
            renderPending(null);
            loadStatus();
        }).catch(function (e) {
            UIkit.modal.alert('Conferma non riuscita: ' + (e.message || 'errore sconosciuto'));
        }).finally(function () { btn.disabled = false; });
    });

    // ── Reti WiFi ────────────────────────────────────────────────────────
    function signalIcon(pct) {
        return pct >= 66 ? '▂▄▆' : (pct >= 33 ? '▂▄' : '▂');
    }

    function renderWifiList(networks) {
        var list = document.getElementById('fm-net-wifi-list');
        list.innerHTML = '';
        if (!networks.length) {
            list.innerHTML = '<p class="uk-text-meta">Nessuna rete trovata.</p>';
            return;
        }
        networks.forEach(function (n) {
            var row = document.createElement('div');
            row.className = 'uk-flex uk-flex-middle uk-margin-small-bottom';
            row.style.gap = '10px';
            row.innerHTML =
                '<span class="fm-mono" style="width:40px;display:inline-block;">' + signalIcon(n.signal) + '</span>' +
                '<span style="flex:1;">' + n.ssid.replace(/</g, '&lt;') + (n.open ? '' : ' <span uk-icon="icon: lock; ratio: 0.7"></span>') + '</span>' +
                '<button type="button" class="uk-button uk-button-default uk-button-small fm-net-connect-btn">Connetti</button>';
            row.querySelector('.fm-net-connect-btn').addEventListener('click', function () {
                connectTo(n.ssid, n.open);
            });
            list.appendChild(row);
        });
    }

    function connectTo(ssid, open) {
        var psk = '';
        if (!open) {
            psk = window.prompt('Password per «' + ssid + '»:') || '';
            if (psk === '') return;
        }
        UIkit.modal.confirm(
            '<h4 class="uk-margin-remove-bottom">Connettersi a «' + ssid + '»?</h4>' +
            '<p class="uk-margin-small-top">Se questa pagina è raggiunta proprio tramite la rete attuale, il collegamento potrebbe interrompersi per qualche secondo. Annota comunque l\'indirizzo attuale prima di continuare, come misura di sicurezza aggiuntiva.</p>'
        ).then(function () {
            post('/api/network_apply_wifi.php', { ssid: ssid, psk: psk }).then(function () {
                loadStatus();
            }).catch(function (e) {
                UIkit.modal.alert('Connessione non riuscita: ' + (e.message || 'errore sconosciuto'));
            });
        }, function () {});
    }

    document.getElementById('fm-net-scan-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'ricerca…';
        post('/api/network_scan.php').then(function (d) {
            renderWifiList(d.networks || []);
        }).catch(function (e) {
            UIkit.modal.alert('Scansione non riuscita: ' + (e.message || 'errore sconosciuto'));
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = 'Cerca reti';
        });
    });

    // ── Indirizzo IP ─────────────────────────────────────────────────────
    document.getElementsByName('fm-net-ip-mode').forEach(function (r) {
        r.addEventListener('change', function () {
            document.getElementById('fm-net-ip-manual').hidden = (r.value !== 'manual') || !r.checked;
        });
    });

    document.getElementById('fm-net-ip-save-btn').addEventListener('click', function () {
        var mode = 'auto';
        document.getElementsByName('fm-net-ip-mode').forEach(function (r) { if (r.checked) mode = r.value; });
        var payload = { mode: mode };
        if (mode === 'manual') {
            payload.address = document.getElementById('fm-net-ip-address').value.trim();
            payload.prefix = document.getElementById('fm-net-ip-prefix').value.trim();
            payload.gateway = document.getElementById('fm-net-ip-gateway').value.trim();
            payload.dns = document.getElementById('fm-net-ip-dns').value.trim();
        }
        var hint = document.getElementById('fm-net-ip-hint');
        UIkit.modal.confirm(
            '<h4 class="uk-margin-remove-bottom">Applicare questo indirizzo IP?</h4>' +
            '<p class="uk-margin-small-top">Se questa pagina è raggiunta tramite l\'indirizzo attuale, il collegamento potrebbe interrompersi per qualche secondo. Se qualcosa va storto, torna da sola alla configurazione precedente.</p>'
        ).then(function () {
            hint.textContent = 'applicazione…';
            hint.className = 'uk-text-meta';
            post('/api/network_apply_ip.php', payload).then(function () {
                hint.textContent = 'applicato, in attesa di conferma';
                hint.className = 'uk-text-success';
                loadStatus();
            }).catch(function (e) {
                hint.textContent = e.message || 'non applicato';
                hint.className = 'uk-text-danger';
            });
        }, function () {});
    });

    // ── Hotspot di configurazione ────────────────────────────────────────
    function renderHotspot(active, ssid, timeoutSeconds) {
        document.getElementById('fm-net-hotspot-active').hidden = !active;
        document.getElementById('fm-net-hotspot-start-btn').hidden = !!active;
        document.getElementById('fm-net-hotspot-stop-btn').hidden = !active;
        if (active) {
            document.getElementById('fm-net-hotspot-ssid').textContent = ssid || '';
            var m = Math.floor((timeoutSeconds || 0) / 60), s = (timeoutSeconds || 0) % 60;
            document.getElementById('fm-net-hotspot-timeout').textContent = m + ':' + (s < 10 ? '0' : '') + s;
        }
    }

    function toggleHotspot(action) {
        var warning = action === 'start'
            ? 'Se questa pagina è raggiunta tramite la rete WiFi attuale, il collegamento potrebbe interrompersi: l\'hotspot prende il posto della connessione normale finché non lo si disattiva o non scadono i 15 minuti.'
            : 'La macchina proverà a tornare sulla rete WiFi nota, se ce n\'è una.';
        UIkit.modal.confirm(
            '<h4 class="uk-margin-remove-bottom">' + (action === 'start' ? 'Attivare' : 'Disattivare') + ' l\'hotspot?</h4>' +
            '<p class="uk-margin-small-top">' + warning + '</p>'
        ).then(function () {
            post('/api/network_hotspot.php', { action: action }).then(function () {
                loadStatus();
            }).catch(function (e) {
                UIkit.modal.alert('Non riuscito: ' + (e.message || 'errore sconosciuto'));
            });
        }, function () {});
    }
    document.getElementById('fm-net-hotspot-start-btn').addEventListener('click', function () { toggleHotspot('start'); });
    document.getElementById('fm-net-hotspot-stop-btn').addEventListener('click', function () { toggleHotspot('stop'); });

    // ── Nome macchina ────────────────────────────────────────────────────
    document.getElementById('fm-net-hostname-btn').addEventListener('click', function () {
        var name = document.getElementById('fm-net-hostname').value.trim();
        var hint = document.getElementById('fm-net-hostname-hint');
        if (name === '') return;
        hint.textContent = 'salvataggio…';
        hint.className = 'uk-text-meta';
        post('/api/network_hostname.php', { hostname: name }).then(function () {
            hint.textContent = 'salvato';
            hint.className = 'uk-text-success';
        }).catch(function (e) {
            hint.textContent = e.message || 'non salvato';
            hint.className = 'uk-text-danger';
        });
    });

    loadStatus();
});
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
