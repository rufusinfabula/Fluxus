<?php
$fmCurrentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$fmWebBase = rtrim(FM_WEB_BASE, '/');
function fmNavActive(string $page): string {
    global $fmCurrentPage;
    return $fmCurrentPage === $page ? 'uk-active' : '';
}
// EDIT (TRIM manuale) rimosso dalla navbar: funzionalità in quarantena, vedi docs/NOTE-TECNICHE.md.
$fmNavItems = [
    ['dashboard.php',  'home',     'Dashboard'],
    ['sources.php',    'server',   'Sorgenti'],
    ['schedules.php',  'calendar', 'Orari'],
    ['recordings.php', 'list',     'Registrazioni'],
];
?>
<nav class="uk-navbar-container" uk-navbar>
    <div class="uk-container fm-navbar-inner">
    <div class="uk-navbar-left">
        <ul class="uk-navbar-nav">
            <li>
                <a href="<?= $fmWebBase ?>/dashboard.php" class="uk-flex uk-flex-middle">
                    <img class="fm-navbar-logo" src="<?= $fmWebBase ?>/icons/fluxus-32.png" width="26" height="26" alt="<?= fmH(FM_APP_NAME) ?>">
                    <span class="uk-text-bold"><?= fmH(FM_APP_NAME) ?></span>
                    <span class="uk-badge uk-background-muted uk-text-muted fm-version-badge">v<?= fmH(FM_VERSION) ?></span>
                </a>
            </li>
        </ul>
        <ul class="uk-navbar-nav">
            <?php foreach ($fmNavItems as [$page, $icon, $label]): ?>
            <li class="<?= fmNavActive($page) ?>">
                <a href="<?= $fmWebBase ?>/<?= $page ?>"><span uk-icon="icon: <?= $icon ?>; ratio: 0.9"></span>&nbsp;<?= $label ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="uk-navbar-right">
        <ul class="uk-navbar-nav">
            <li class="uk-flex uk-flex-middle">
                <div class="fm-clock-block">
                    <div class="fm-clock-time" id="fm-clock-time">--:--:--</div>
                    <div class="uk-text-meta fm-clock-tz" id="fm-clock-tz"></div>
                </div>
            </li>
            <li>
                <a href="<?= $fmWebBase ?>/settings.php" uk-icon="icon: settings" uk-tooltip="title: Impostazioni; pos: bottom"></a>
            </li>
            <?php if (function_exists('fmAuthEnabled') && fmAuthEnabled()): ?>
            <li>
                <a href="<?= $fmWebBase ?>/logout.php" uk-icon="icon: sign-out" uk-tooltip="title: Esci; pos: bottom"></a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    </div>
</nav>

<div class="fm-sysbar">
    <div class="uk-container">
        <div class="fm-sysbar-row">
            <?php /* Volumi: fino a due si vedono affiancati qui; da tre in su
                      resta il primo, poi i puntini e la freccetta che apre la
                      tendina con l'elenco completo. Contenuto costruito dal JS. */ ?>
            <div class="fm-sysbar-metric fm-sysbar-vol">
                <span id="fm-vol-inline" class="fm-vol-inline">
                    <span class="fm-sysbar-label">Disco</span>
                    <span class="fm-sysbar-pct">--%</span>
                </span>
                <button type="button" class="fm-sysbar-vol-toggle" id="fm-vol-toggle" hidden>
                    <span uk-icon="icon: chevron-down; ratio: 0.7"></span>
                </button>
                <div id="fm-vol-dropdown" class="fm-vol-dropdown" uk-dropdown="mode: click; pos: bottom-left; offset: 6">
                    <div class="fm-vol-dropdown-title">Volumi di archiviazione</div>
                    <div id="fm-vol-list"></div>
                </div>
            </div>
            <span class="fm-sysbar-sep">│</span>
            <div class="fm-sysbar-metric">
                <span class="fm-sysbar-label">CPU</span>
                <span class="fm-sysbar-bar"><span class="fm-sysbar-bar-fill" id="fm-bar-cpu"></span></span>
                <span class="fm-sysbar-pct" id="fm-pct-cpu">--%</span>
            </div>
            <span class="fm-sysbar-sep">│</span>
            <div class="fm-sysbar-metric">
                <span class="fm-sysbar-label">RAM</span>
                <span class="fm-sysbar-bar"><span class="fm-sysbar-bar-fill" id="fm-bar-ram"></span></span>
                <span class="fm-sysbar-pct" id="fm-pct-ram">--%</span>
                <span class="fm-sysbar-abs fm-mono" id="fm-abs-ram"></span>
            </div>
            <span class="fm-sysbar-sep">│</span>
            <div class="fm-sysbar-metric">
                <span class="fm-sysbar-label">SWAP</span>
                <span class="fm-sysbar-bar"><span class="fm-sysbar-bar-fill" id="fm-bar-swap"></span></span>
                <span class="fm-sysbar-pct" id="fm-pct-swap">--%</span>
            </div>
            <div class="fm-sysbar-fresh">
                <span class="fm-sysbar-dot" id="fm-fresh-dot"></span>
                <span id="fm-fresh-text">in attesa&hellip;</span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var base = <?= json_encode($fmWebBase) ?>;
    var tz = <?= json_encode(function_exists('fmTimezone') ? fmTimezone() : 'Europe/Rome') ?>;
    var lastUpdate = null;

    function thresholdColor(pct) {
        if (pct > 85) return '#f0506e';
        if (pct > 70) return '#faa05a';
        return '#32d296';
    }

    function setBar(id, pct) {
        pct = Math.max(0, Math.min(100, pct || 0));
        var el = document.getElementById('fm-bar-' + id);
        el.style.width = pct + '%';
        el.style.background = thresholdColor(pct);
        document.getElementById('fm-pct-' + id).textContent = pct.toFixed(0) + '%';
    }

    // Un volume come appare in barra: nome, barra, percentuale, GB.
    function inlineVolume(v) {
        var wrap = document.createElement('span');
        wrap.className = 'fm-vol-inline-item';

        var icon = document.createElement('i');
        icon.className = 'fa ' + (v.is_default ? 'fa-hdd-o' : 'fa-usb') + ' fm-vol-icon';
        wrap.appendChild(icon);

        var label = document.createElement('span');
        label.className = 'fm-sysbar-label';
        label.textContent = v.label;
        wrap.appendChild(label);

        if (v.online) {
            var bar = document.createElement('span');
            bar.className = 'fm-sysbar-bar';
            var fill = document.createElement('span');
            fill.className = 'fm-sysbar-bar-fill';
            fill.style.width = Math.max(0, Math.min(100, v.used_pct)) + '%';
            fill.style.background = thresholdColor(v.used_pct);
            bar.appendChild(fill);
            wrap.appendChild(bar);

            var pct = document.createElement('span');
            pct.className = 'fm-sysbar-pct';
            pct.textContent = v.used_pct.toFixed(0) + '%';
            wrap.appendChild(pct);

            var abs = document.createElement('span');
            abs.className = 'fm-sysbar-abs fm-mono';
            abs.textContent = v.used_gb.toFixed(0) + '/' + v.total_gb.toFixed(0) + ' GB';
            wrap.appendChild(abs);
        } else {
            var off = document.createElement('span');
            off.className = 'fm-vol-offline-text';
            off.textContent = 'non collegato';
            wrap.appendChild(off);
        }
        return wrap;
    }

    // Fino a due volumi stanno tutti in barra, affiancati. Da tre in su resta
    // solo il primo, seguito dai puntini e dalla freccetta della tendina.
    function renderVolumes(volumes) {
        var toggle = document.getElementById('fm-vol-toggle');
        var list = document.getElementById('fm-vol-list');
        var inline = document.getElementById('fm-vol-inline');
        if (!volumes || !volumes.length) return;

        var shown = volumes.length <= 2 ? volumes : volumes.slice(0, 1);
        inline.innerHTML = '';
        shown.forEach(function (v, i) {
            if (i > 0) {
                var sep = document.createElement('span');
                sep.className = 'fm-sysbar-sep';
                sep.textContent = '│';
                inline.appendChild(sep);
            }
            inline.appendChild(inlineVolume(v));
        });
        if (volumes.length > 2) {
            var dots = document.createElement('span');
            dots.className = 'fm-vol-more';
            dots.textContent = '…';
            dots.title = (volumes.length - 1) + ' altri volumi';
            inline.appendChild(dots);
        }
        toggle.hidden = volumes.length <= 2;

        list.innerHTML = '';
        volumes.forEach(function (v) {
            var row = document.createElement('div');
            row.className = 'fm-vol-row' + (v.online ? '' : ' fm-vol-offline');

            // Stesse icone della pagina Impostazioni: interno vs disco esterno.
            var icon = document.createElement('i');
            icon.className = 'fa ' + (v.is_default ? 'fa-hdd-o' : 'fa-usb') + ' fm-vol-icon';
            row.appendChild(icon);

            var name = document.createElement('div');
            name.className = 'fm-vol-name';
            name.textContent = v.label;
            row.appendChild(name);

            if (v.online) {
                var bar = document.createElement('span');
                bar.className = 'fm-sysbar-bar';
                var fill = document.createElement('span');
                fill.className = 'fm-sysbar-bar-fill';
                fill.style.width = Math.max(0, Math.min(100, v.used_pct)) + '%';
                fill.style.background = thresholdColor(v.used_pct);
                bar.appendChild(fill);
                row.appendChild(bar);

                var pct = document.createElement('span');
                pct.className = 'fm-sysbar-pct';
                pct.textContent = v.used_pct.toFixed(0) + '%';
                row.appendChild(pct);

                var abs = document.createElement('span');
                abs.className = 'fm-sysbar-abs fm-mono';
                abs.textContent = v.used_gb.toFixed(0) + '/' + v.total_gb.toFixed(0) + ' GB';
                row.appendChild(abs);
            } else {
                var off = document.createElement('span');
                off.className = 'fm-vol-offline-text';
                off.textContent = 'non collegato';
                row.appendChild(off);
            }
            list.appendChild(row);
        });
    }

    function pollSystem() {
        fetch(base + '/api/system.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setBar('cpu', d.cpu_percent);
                setBar('ram', d.mem_used_pct);
                setBar('swap', d.swap_used_pct);
                document.getElementById('fm-abs-ram').textContent =
                    (d.mem_used_mb / 1024).toFixed(1) + '/' + (d.mem_total_mb / 1024).toFixed(1) + ' GB';
                renderVolumes(d.volumes);
                lastUpdate = Date.now();
                document.getElementById('fm-fresh-dot').style.background = '#32d296';
            })
            .catch(function () {
                document.getElementById('fm-fresh-dot').style.background = '#f0506e';
            });
    }

    function tickFreshness() {
        var el = document.getElementById('fm-fresh-text');
        if (!lastUpdate) { el.textContent = 'in attesa…'; return; }
        var age = Math.round((Date.now() - lastUpdate) / 1000);
        el.textContent = age < 2 ? 'aggiornato ora' : ('aggiornato ' + age + 's fa');
    }

    function tickClock() {
        try {
            var now = new Date();
            var timeStr = now.toLocaleTimeString('it-IT', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('fm-clock-time').textContent = timeStr;
            document.getElementById('fm-clock-tz').textContent = tz;
        } catch (e) {}
    }

    pollSystem();
    tickClock();
    setInterval(pollSystem, 10000);
    setInterval(tickFreshness, 1000);
    setInterval(tickClock, 1000);
})();
</script>
