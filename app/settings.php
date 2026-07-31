<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$saved = false;
$errors = [];

// Il box Archiviazione non passa da qui: ordine dei volumi e destinazioni
// audio/video si salvano da soli a ogni movimento, via api/volume_order.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nodeName = trim($_POST['node_name'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Europe/Rome');
    $authEnabled = !empty($_POST['auth_enabled']) ? '1' : '0';
    $newPassword = $_POST['password'] ?? '';
    $cuePreRoll = (int)($_POST['cue_pre_roll'] ?? 30);
    $cuePostRoll = (int)($_POST['cue_post_roll'] ?? 90);
    $markerAutosaveSeconds = (int)($_POST['marker_autosave_seconds'] ?? 8);

    if ($nodeName === '') $errors[] = 'Il nome nodo è obbligatorio.';

    try {
        new DateTimeZone($timezone);
    } catch (Exception $e) {
        $errors[] = 'Timezone non valida.';
    }

    if ($authEnabled === '1' && $newPassword === '' && fmSetting('password_hash', '') === '') {
        $errors[] = 'Imposta una password per abilitare l\'autenticazione.';
    }

    if ($cuePreRoll < 0 || $cuePreRoll > 120) {
        $errors[] = 'Il pre-roll del cue deve essere tra 0 e 120 secondi (2 minuti).';
    }
    if ($cuePostRoll < 1 || $cuePostRoll > 240) {
        $errors[] = 'Il post-roll del cue deve essere tra 1 e 240 secondi (4 minuti).';
    }
    if ($markerAutosaveSeconds < 3 || $markerAutosaveSeconds > 60) {
        $errors[] = 'Il salvataggio automatico del modale deve essere tra 3 e 60 secondi.';
    }

    if (empty($errors)) {
        fmSetSetting('node_name', $nodeName);
        fmSetSetting('timezone', $timezone);
        fmSetSetting('auth_enabled', $authEnabled);
        fmSetSetting('cue_pre_roll', (string)$cuePreRoll);
        fmSetSetting('cue_post_roll', (string)$cuePostRoll);
        fmSetSetting('marker_autosave_seconds', (string)$markerAutosaveSeconds);
        if ($newPassword !== '') {
            fmSetSetting('password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
        }
        header('Location: settings.php?saved=1');
        exit;
    }
}

$saved = isset($_GET['saved']);
$nodeName = fmSetting('node_name', FM_APP_NAME);
$timezone = fmSetting('timezone', 'Europe/Rome');
$authEnabled = fmSetting('auth_enabled', '0') === '1';
$cuePreRoll = (int)fmSetting('cue_pre_roll', '30');
$cuePostRoll = (int)fmSetting('cue_post_roll', '90');
$markerAutosaveSeconds = (int)fmSetting('marker_autosave_seconds', '8');

$storageVolumes = fmStorageVolumes(true);
$storageAudioId = (int)fmSetting('storage_volume_audio', '1');
$storageVideoId = (int)fmSetting('storage_volume_video', '1');
// Stesso elenco e stesso ordine della barra di stato.
$displayVolumes = fmVolumesForDisplay();

// Mount point attualmente selezionato per audio e per video: si risale dal
// volume salvato in DB al disco montato che lo contiene.
$pathById = [];
foreach ($storageVolumes as $v) $pathById[$v['id']] = rtrim($v['path'], '/');
$mountFor = function (int $volumeId) use ($pathById, $displayVolumes): string {
    $path = $pathById[$volumeId] ?? FM_BASE;
    foreach ($displayVolumes as $d) {
        if (rtrim($d['path'], '/') === $path) return $d['mount'];
    }
    return '/';
};
$currentAudioMount = $mountFor($storageAudioId);
$currentVideoMount = $mountFor($storageVideoId);

// Volumi scollegati che contengono davvero delle registrazioni: solo quelli
// meritano un avviso, perché è ciò che l'utente non può più aprire. Un disco
// scollegato e vuoto non è un problema da segnalare.
$offlineVolumes = [];
foreach ($storageVolumes as $v) {
    if ($v['online']) continue;
    $n = fmVolumeRecordingCount($v['path']);
    if ($n > 0) {
        $v['recordings'] = $n;
        $offlineVolumes[] = $v;
    }
}

$pageTitle = 'Impostazioni';
include __DIR__ . '/includes/head.php';
?>

<h2 class="fm-page-title"><span uk-icon="icon: settings"></span> Impostazioni</h2>

<?php if ($saved): ?><div class="uk-alert-success" uk-alert><span uk-icon="icon: check"></span> Impostazioni salvate.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="uk-alert-danger" uk-alert><span uk-icon="icon: warning"></span> <?= fmH($e) ?></div><?php endforeach; ?>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Marker &amp; Cue</h3>
    <p class="uk-text-meta uk-margin-remove-top">Intervallo estratto attorno al click di un cue (marker/cue &rarr; clip, vale per audio e video, si applica ai cue creati dopo il salvataggio) e durata del salvataggio automatico del modale Marker/Cue (la barra si riduce mano a mano che i secondi passano; allo scadere il marker/cue viene salvato con l'etichetta inserita, o con il nome di default <span class="fm-mono">marker_ID</span> / <span class="fm-mono">cue_ID</span> se lasciata vuota).</p>
    <form method="post">
        <input type="hidden" name="node_name" value="<?= fmH($nodeName) ?>">
        <input type="hidden" name="timezone" value="<?= fmH($timezone) ?>">
        <?php if ($authEnabled): ?><input type="hidden" name="auth_enabled" value="1"><?php endif; ?>
        <div class="uk-grid-small" uk-grid>
            <div class="uk-width-1-3@m">
                <label class="uk-form-label">Pre-roll (secondi prima del click)</label>
                <div class="uk-flex uk-flex-middle" style="gap:8px;">
                    <input type="range" class="uk-range" id="fm-set-cue-pre-roll-range" min="0" max="120" step="1" value="<?= (int)$cuePreRoll ?>" style="flex:1;">
                    <input class="uk-input uk-form-small fm-mono" type="number" name="cue_pre_roll" id="fm-set-cue-pre-roll-number" min="0" max="120" value="<?= (int)$cuePreRoll ?>" required style="width:70px;flex:none;">
                </div>
                <div class="uk-text-meta" style="font-size:11px;">0–120 secondi (max 2 minuti).</div>
            </div>
            <div class="uk-width-1-3@m">
                <label class="uk-form-label">Post-roll (secondi dopo il click)</label>
                <div class="uk-flex uk-flex-middle" style="gap:8px;">
                    <input type="range" class="uk-range" id="fm-set-cue-post-roll-range" min="1" max="240" step="1" value="<?= (int)$cuePostRoll ?>" style="flex:1;">
                    <input class="uk-input uk-form-small fm-mono" type="number" name="cue_post_roll" id="fm-set-cue-post-roll-number" min="1" max="240" value="<?= (int)$cuePostRoll ?>" required style="width:70px;flex:none;">
                </div>
                <div class="uk-text-meta" style="font-size:11px;">1–240 secondi (max 4 minuti).</div>
            </div>
            <div class="uk-width-1-3@m">
                <label class="uk-form-label">Salvataggio automatico modale (secondi di inattività)</label>
                <div class="uk-flex uk-flex-middle" style="gap:8px;">
                    <input type="range" class="uk-range" id="fm-set-autosave-range" min="3" max="60" step="1" value="<?= (int)$markerAutosaveSeconds ?>" style="flex:1;">
                    <input class="uk-input uk-form-small fm-mono" type="number" name="marker_autosave_seconds" id="fm-set-autosave-number" min="3" max="60" value="<?= (int)$markerAutosaveSeconds ?>" required style="width:70px;flex:none;">
                </div>
                <div class="uk-text-meta" style="font-size:11px;">3–60 secondi.</div>
            </div>
        </div>
        <div class="uk-margin-top">
            <button type="submit" class="uk-button uk-button-primary uk-button-small">Salva</button>
        </div>
    </form>
</div>

<script>
(function () {
    function bindRangeNumber(rangeId, numberId) {
        var range = document.getElementById(rangeId);
        var number = document.getElementById(numberId);
        if (!range || !number) return;
        range.addEventListener('input', function () { number.value = range.value; });
        number.addEventListener('input', function () {
            var v = number.value;
            if (v === '') return;
            v = Math.min(Number(number.max), Math.max(Number(number.min), Number(v)));
            range.value = v;
        });
    }
    bindRangeNumber('fm-set-cue-pre-roll-range', 'fm-set-cue-pre-roll-number');
    bindRangeNumber('fm-set-cue-post-roll-range', 'fm-set-cue-post-roll-number');
    bindRangeNumber('fm-set-autosave-range', 'fm-set-autosave-number');
})();
</script>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom" id="archiviazione">
    <h3 class="fm-section-title uk-margin-small-bottom">Archiviazione</h3>
    <p class="uk-text-meta uk-margin-remove-top">Dischi collegati al Pi. Trascina il tag <span class="fm-badge-audio">AUDIO</span> o <span class="fm-badge-video">VIDEO</span> sul volume dove vuoi salvare quel tipo di registrazioni, e trascina i volumi per l'impaginazione (lo stesso ordine si vede nella barra di stato). <strong>Ogni movimento si salva da solo.</strong> Fluxus crea sul disco una cartella <span class="fm-mono"><?= fmH(FM_VOLUME_DIR) ?>/</span> con dentro <span class="fm-mono">recordings/</span> e <span class="fm-mono">clips/</span>, senza toccare il resto. Le registrazioni già fatte restano dove sono. Se al momento di registrare il disco non è collegato, si registra sul volume interno e la registrazione lo annota.</p>

    <?php // Rilegge i dischi collegati e annulla le rimozioni dall'elenco. ?>
    <button type="button" class="fm-vol-rescan" id="fm-vol-rescan"
            uk-tooltip="title: Rilegge i dischi collegati e rimette in elenco quelli tolti; pos: right"
            aria-label="Rileva dischi">
        <span uk-icon="icon: refresh; ratio: 0.75"></span>
    </button>

    <div id="fm-volume-list" class="fm-vol-list" uk-sortable="handle: .fm-drag-handle">
    <?php foreach ($displayVolumes as $d):
        $usable = $d['online'] && ($d['is_root'] || $d['writable']);
        $barColor = $d['used_pct'] > 85 ? '#f0506e' : ($d['used_pct'] > 70 ? '#faa05a' : '#32d296');
        $isAudio = $currentAudioMount === $d['mount'];
        $isVideo = $currentVideoMount === $d['mount'];
    ?>
        <div class="fm-vol-card<?= $usable ? '' : ' fm-vol-card-disabled' ?>" data-key="<?= fmH($d['key']) ?>" data-mount="<?= fmH($d['mount']) ?>" data-usable="<?= $usable ? '1' : '0' ?>">
            <span class="fm-drag-handle" uk-tooltip="title: Trascina per riordinare; pos: right">
                <span uk-icon="icon: table; ratio: 0.8"></span>
            </span>

            <?php // Cerchio dell'icona: blu se ospita l'audio, viola il video, metà e metà se entrambi. ?>
            <span class="fm-vol-badge" uk-tooltip="title: <?= $d['is_root'] ? 'Disco interno (microSD)' : 'Disco esterno' ?>; pos: top">
                <?= fmVolumeIcon((bool)$d['is_root']) ?>
            </span>

            <?php if ($usable): ?>
            <?php /* Due caselle separate, una per tipo: ogni tag ha il proprio gruppo
                      sortable, così il tag AUDIO non può finire nella casella VIDEO.
                      Niente spazi dentro i div: il segnaposto è :empty::after in CSS. */ ?>
            <div class="fm-vol-drop fm-vol-drop-audio" uk-sortable="group: fm-tag-audio"><?php if ($isAudio): ?><span class="fm-badge-audio fm-media-tag" data-media="audio">AUDIO</span><?php endif; ?></div>
            <div class="fm-vol-drop fm-vol-drop-video" uk-sortable="group: fm-tag-video"><?php if ($isVideo): ?><span class="fm-badge-video fm-media-tag" data-media="video">VIDEO</span><?php endif; ?></div>
            <?php else:
                // Su un disco non abilitato le caselle dei tag non servono: al loro
                // posto va il pulsante, con la stessa larghezza complessiva perché le
                // colonne restino allineate fra le card.
                $mountable = in_array($d['fs'], ['ext2','ext3','ext4','xfs','btrfs','f2fs','vfat','exfat','ntfs','ntfs3','fuseblk'], true);
            ?>
            <div class="fm-vol-slot-enable">
                <?php if ($d['online']): ?>
                <button type="button" class="uk-button uk-button-primary uk-button-small fm-vol-enable"
                        data-mount="<?= fmH($d['mount']) ?>" data-label="<?= fmH($d['label']) ?>"
                        data-fs="<?= fmH($d['fs']) ?>"
                        data-mountable="<?= $mountable ? '1' : '0' ?>"
                        data-used="<?= fmH(number_format($d['used_gb'], 1, ',', '')) ?>">Abilita</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="fm-vol-info">
                <div>
                    <span class="uk-text-bold"><?= fmH($d['label']) ?></span>
                    <?php if ($d['is_root']): ?>
                        <span class="uk-badge uk-background-muted uk-text-muted fm-version-badge">interno</span>
                    <?php endif; ?>
                </div>
                <?php /* Tutto su una riga sola: una card con più avvisi non deve
                          diventare più alta delle altre. I dettagli stanno nei tooltip. */ ?>
                <div class="uk-text-meta fm-mono fm-vol-meta" style="font-size:11px;">
                    <span><?= fmH($d['mount']) ?> · <?= fmH($d['device']) ?> · <?= fmH($d['fs']) ?></span>
                    <?php if ($d['fs'] === 'vfat'): ?>
                        <?php // FAT32 non regge file oltre 4 GB: uno slot video di 2h in copy ci arriva. ?>
                        <span class="fm-vol-chip fm-vol-chip-warn"
                              uk-tooltip="title: FAT32 non permette file oltre 4 GB: per i video usa la segmentazione o un profilo di qualità; pos: top">max 4 GB/file</span>
                    <?php endif; ?>
                    <?php if (!$d['online']): ?>
                        <span class="fm-vol-chip fm-vol-chip-err">non collegato</span>
                    <?php elseif (!$usable): ?>
                        <span class="fm-vol-chip fm-vol-chip-err"
                              uk-tooltip="title: <?= !empty($d['desktop_mount'])
                                  ? 'Montato dal desktop in ' . fmH(dirname($d['mount'])) . ', una cartella in cui ' . fmH(FM_USER) . ' non può entrare. Il pulsante lo rimonta sotto /mnt in modo permanente.'
                                  : 'La cartella non è scrivibile da ' . fmH(FM_USER) . '. Il pulsante rimonta il disco con i permessi giusti.' ?>; pos: top">non abilitato per <?= fmH(FM_APP_NAME) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php // Spazio sempre mostrato, anche sui dischi non ancora abilitati:
                  // le dimensioni si leggono comunque (vedi fmVolumeSizeFallback). ?>
            <div class="fm-vol-space<?= $usable ? '' : ' fm-vol-space-narrow' ?>">
                <?php if ($d['total_gb'] > 0): ?>
                    <div class="fm-mono uk-text-small"><?= number_format($d['free_gb'], 1) ?> GB liberi di <?= number_format($d['total_gb'], 1) ?></div>
                    <div class="uk-flex uk-flex-middle uk-flex-right" style="gap:6px;margin-top:3px;">
                        <span class="fm-sysbar-bar" style="width:80px;"><span class="fm-sysbar-bar-fill" style="width:<?= min(100, $d['used_pct']) ?>%;background:<?= $barColor ?>;"></span></span>
                        <span class="uk-text-meta" style="font-size:11px;"><?= number_format($d['used_pct'], 0) ?>% usato</span>
                    </div>
                <?php else: ?>
                    <span class="uk-text-meta uk-text-small">dimensione non leggibile</span>
                <?php endif; ?>
            </div>

            <?php /* I volumi non abilitati o scollegati si possono togliere dall'elenco:
                      restano montati e i file non si toccano, semplicemente spariscono
                      di qui e dalla barra di stato. "Rileva dischi" li riporta. */ ?>
            <?php if (!$usable && !$d['is_root']): ?>
            <button type="button" class="fm-vol-remove" data-key="<?= fmH($d['key']) ?>" data-label="<?= fmH($d['label']) ?>"
                    data-online="<?= $d['online'] ? '1' : '0' ?>"
                    uk-tooltip="title: Togli dall'elenco; pos: left" aria-label="Togli dall'elenco">
                <span uk-icon="icon: close; ratio: 0.8"></span>
            </button>
            <?php else: ?>
            <span class="fm-vol-remove-spacer"></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="uk-margin-small-top" style="font-size:11px;">
        <span class="uk-text-meta">Il volume senza tag non riceve nuove registrazioni.</span>
        <span id="fm-order-hint" style="margin-left:8px;"></span>
    </div>

    <?php if ($offlineVolumes): ?>
    <div class="uk-alert-warning uk-margin-small-top" uk-alert style="font-size:12px;">
        <span uk-icon="icon: warning; ratio: 0.8"></span>
        Ci sono registrazioni su dischi non collegati: restano in elenco, ma i loro file non sono riproducibili finché il disco non torna.
        <?php foreach ($offlineVolumes as $v): ?>
            <div style="font-size:11px;">
                <b><?= fmH($v['label']) ?></b>: <?= (int)$v['recordings'] ?> registrazion<?= $v['recordings'] === 1 ? 'e' : 'i' ?>
                <span class="fm-mono uk-text-meta">(<?= fmH($v['path']) ?>)</span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="uk-text-meta uk-margin-small-top" style="font-size:11px;">
        Una singola sorgente può usare un volume diverso da questi: campo &laquo;Volume di archiviazione&raquo; nel modale della sorgente.
    </div>
</div>

<script>
// ⚠️ Deve girare DOPO uikit.min.js, che foot.php carica in fondo al body: qui
// UIkit non è ancora definito, e senza l'attesa l'intero blocco muore su
// "UIkit is not defined" (niente drag&drop, niente colori).
document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('fm-volume-list');
    if (!list || typeof UIkit === 'undefined') return;

    var hint = document.getElementById('fm-order-hint');
    var endpoint = <?= json_encode(FM_WEB_BASE . '/api/volume_order.php') ?>;
    // Nome della cartella che Fluxus crea sui dischi esterni: è quello
    // dell'istanza, e va detto all'utente prima che accetti di preparare il disco.
    var volumeDir = <?= json_encode(FM_VOLUME_DIR) ?>;
    // Stessi colori dei tag AUDIO/VIDEO usati in tutta la UI.
    var AUDIO = '#1e87f0', VIDEO = '#a855c9', NONE = '#9aa0ad';
    var timer = null;

    function say(text, cls) {
        hint.textContent = text;
        hint.className = cls || 'uk-text-meta';
        if (cls === 'uk-text-success') {
            setTimeout(function () { if (hint.textContent === text) hint.textContent = ''; }, 2500);
        }
    }

    // Il colore del cerchio segue i tag presenti sulla card.
    function paintBadges() {
        list.querySelectorAll('.fm-vol-card').forEach(function (card) {
            var hasA = !!card.querySelector('.fm-media-tag[data-media="audio"]');
            var hasV = !!card.querySelector('.fm-media-tag[data-media="video"]');
            var badge = card.querySelector('.fm-vol-badge');
            if (!badge) return;
            if (hasA && hasV) badge.style.background = 'linear-gradient(90deg,' + AUDIO + ' 50%,' + VIDEO + ' 50%)';
            else if (hasA)    badge.style.background = AUDIO;
            else if (hasV)    badge.style.background = VIDEO;
            else              badge.style.background = NONE;
        });
    }

    // Lo stato si legge sempre dal DOM: l'ordine delle card e la card su cui si
    // trova ciascun tag. Non si dipende dai dettagli dell'evento di UIkit, che
    // cambiano fra spostamento interno (moved) e fra liste diverse (added/removed).
    function readState() {
        var state = { order: [], audio: null, video: null, labels: {} };
        list.querySelectorAll('.fm-vol-card[data-key]').forEach(function (card) {
            state.order.push(card.getAttribute('data-key'));
            var mount = card.getAttribute('data-mount');
            var nameEl = card.querySelector('.uk-text-bold');
            state.labels[mount] = nameEl ? nameEl.textContent.trim() : mount;
            card.querySelectorAll('.fm-media-tag').forEach(function (tag) {
                var media = tag.getAttribute('data-media');
                if (media === 'audio' || media === 'video') {
                    state[media] = { mount: mount, usable: card.getAttribute('data-usable') === '1' };
                }
            });
        });
        return state;
    }

    function save() {
        var state = readState();
        if (!state.order.length) return;

        var payload = { order: state.order };
        var parts = [];
        ['audio', 'video'].forEach(function (media) {
            var sel = state[media];
            if (!sel) return;
            if (!sel.usable) {
                // Tag lasciato su un disco non utilizzabile: il server rifiuterebbe,
                // meglio dirlo subito e rimettere le cose com'erano.
                say('quel volume non è utilizzabile', 'uk-text-danger');
                setTimeout(function () { location.reload(); }, 1200);
                payload = null;
                return;
            }
            payload[media] = sel.mount;
            parts.push(media.toUpperCase() + ' → ' + state.labels[sel.mount]);
        });
        if (!payload) return;

        post(payload, parts.length ? 'salvato — ' + parts.join(', ') : 'ordine salvato');
    }

    // Con reload=true la pagina si ricarica dopo il salvataggio: serve quando
    // cambia l'elenco delle card (volume tolto, dischi rilevati).
    function post(payload, okText, reload) {
        say('salvataggio…');
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw new Error(d.error || ('http ' + r.status));
                return d;
            });
        }).then(function () {
            say(okText, 'uk-text-success');
            if (reload) setTimeout(function () { location.reload(); }, 500);
        }).catch(function (e) {
            say(e.message || 'non salvato', 'uk-text-danger');
            if (!reload) setTimeout(function () { location.reload(); }, 1500);
        });
    }

    // Uno spostamento fra card emette added sul contenitore di destinazione e
    // removed su quello di partenza: il debounce li unisce in una sola chiamata.
    function onSortableChange(e) {
        if (!list.contains(e.target)) return;
        paintBadges();
        clearTimeout(timer);
        timer = setTimeout(save, 150);
    }

    ['moved', 'added', 'removed'].forEach(function (evt) {
        UIkit.util.on(document, evt, onSortableChange);
    });

    // Oltre al trascinamento: un clic su una casella vuota ci porta dentro il tag
    // di quel tipo, ovunque si trovi. Più rapido del drag, e usabile da telefono.
    list.addEventListener('click', function (e) {
        var drop = e.target.closest('.fm-vol-drop');
        if (!drop || drop.querySelector('.fm-media-tag')) return;

        var media = drop.classList.contains('fm-vol-drop-audio') ? 'audio' : 'video';
        var card = drop.closest('.fm-vol-card');
        if (card && card.getAttribute('data-usable') !== '1') {
            say('quel volume non è utilizzabile', 'uk-text-danger');
            return;
        }
        var tag = list.querySelector('.fm-media-tag[data-media="' + media + '"]');
        if (!tag) return;

        drop.appendChild(tag);
        paintBadges();
        clearTimeout(timer);
        timer = setTimeout(save, 50);
    });

    // "Abilita per Fluxus": rimonta il disco sotto /mnt da fstab e crea la
    // cartella dati di www-data. Serve per i dischi automontati dal desktop e
    // per quelli senza permessi di scrittura.
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.fm-vol-enable');
        if (!btn) return;
        e.stopPropagation();

        var label = btn.getAttribute('data-label') || 'il disco';
        var fs = btn.getAttribute('data-fs') || '';
        var used = parseFloat((btn.getAttribute('data-used') || '0').replace(',', '.'));

        // Il filesystem non è montabile così com'è: servirebbe formattare, e
        // Fluxus non formatta. Solo avviso, nessuna operazione.
        if (btn.getAttribute('data-mountable') !== '1') {
            UIkit.modal.alert(
                '<h4 class="uk-margin-remove-bottom">«' + label + '» non può essere abilitato</h4>' +
                '<p class="uk-margin-small-top">Il filesystem <b>' + (fs || 'sconosciuto') + '</b> non è utilizzabile da Fluxus.</p>' +
                '<p class="uk-alert-danger uk-padding-small uk-margin-remove-bottom">' +
                'Per usare questo disco andrebbe <b>formattato</b>, operazione che <b>cancellerebbe tutti i dati presenti</b>. ' +
                'Fluxus non formatta nulla: se vuoi procedere, fallo a mano dopo aver messo al sicuro il contenuto.</p>'
            );
            return;
        }

        var dataLine = used >= 0.05
            ? '<p class="uk-alert-success uk-padding-small uk-margin-remove-bottom">' +
              'Sul disco ci sono già <b>' + used.toFixed(1).replace('.', ',') + ' GB</b> di dati: <b>non vengono toccati</b>. ' +
              'Fluxus non formatta e non cancella nulla, aggiunge solo la cartella <code>' + volumeDir + '/</code>.</p>'
            : '<p class="uk-alert-success uk-padding-small uk-margin-remove-bottom">' +
              'Il disco è praticamente vuoto. Nessun dato viene cancellato: Fluxus aggiunge solo la cartella <code>' + volumeDir + '/</code>.</p>';

        UIkit.modal.confirm(
            '<h4 class="uk-margin-remove-bottom">Preparare «' + label + '» per Fluxus?</h4>' +
            '<p class="uk-margin-small-top">Il disco verrà smontato dal desktop e rimontato sotto ' +
            '<code>/mnt</code> in modo permanente (voce in <code>/etc/fstab</code>), così resta al suo posto a ogni riavvio.</p>' +
            dataLine
        ).then(function () {
            btn.disabled = true;
            btn.textContent = 'preparazione…';
            fetch(<?= json_encode(rtrim(FM_WEB_BASE, '/') . '/api/volume_enable.php') ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mount: btn.getAttribute('data-mount') })
            }).then(function (r) {
                return r.json().then(function (d) {
                    if (!r.ok) throw new Error(d.error || ('http ' + r.status));
                    return d;
                });
            }).then(function (d) {
                say('«' + (d.label || label) + '» pronto su ' + d.mount, 'uk-text-success');
                setTimeout(function () { location.reload(); }, 900);
            }).catch(function (err) {
                btn.disabled = false;
                btn.textContent = 'Abilita per Fluxus';
                UIkit.modal.alert('Non è stato possibile abilitare il disco:\n\n' + (err.message || 'errore sconosciuto'));
            });
        }, function () {});
    });

    // Togli un volume dall'elenco (× sulla card).
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.fm-vol-remove');
        if (!btn) return;
        e.stopPropagation();

        var label = btn.getAttribute('data-label') || 'questo volume';
        var online = btn.getAttribute('data-online') === '1';
        // Il ritorno dipende dallo stato: un disco collegato lo riporta il
        // refresh, uno scollegato solo ricollegandolo davvero.
        var backLine = online
            ? 'Se cambi idea, il pulsante <b>rileva dischi</b> lo rimette in elenco.'
            : 'Il disco non è collegato, quindi <b>il refresh non lo riporterà</b>: ricomparirà da sé quando lo ricolleghi al Pi.';

        UIkit.modal.confirm(
            '<h4 class="uk-margin-remove-bottom">Togliere «' + label + '» dall\'elenco?</h4>' +
            '<p class="uk-margin-small-top">Sparisce da qui e dalla barra di stato. ' + backLine + '</p>' +
            '<p class="uk-alert-success uk-padding-small uk-margin-remove-bottom">' +
            'Nessun file viene cancellato. Le registrazioni già fatte su questo disco restano ' +
            'in elenco e tornano riproducibili appena il disco è di nuovo collegato.</p>'
        ).then(function () {
            post({ hide: btn.getAttribute('data-key') }, '«' + label + '» tolto dall\'elenco', true);
        }, function () {});
    });

    // "Rileva dischi": rilegge i dischi collegati e annulla le rimozioni.
    var rescan = document.getElementById('fm-vol-rescan');
    if (rescan) {
        rescan.addEventListener('click', function () {
            post({ rescan: 1 }, 'elenco aggiornato', true);
        });
    }

    paintBadges();
});
</script>

<div class="uk-card uk-card-default uk-card-body fm-card uk-margin-bottom">
    <h3 class="fm-section-title uk-margin-small-bottom">Nodo</h3>
    <form method="post">
        <input type="hidden" name="cue_pre_roll" value="<?= (int)$cuePreRoll ?>">
        <input type="hidden" name="cue_post_roll" value="<?= (int)$cuePostRoll ?>">
        <input type="hidden" name="marker_autosave_seconds" value="<?= (int)$markerAutosaveSeconds ?>">
        <div class="uk-grid-small" uk-grid>
            <div class="uk-width-1-2@m">
                <label class="uk-form-label">Nome nodo</label>
                <input class="uk-input uk-form-small" type="text" name="node_name" value="<?= fmH($nodeName) ?>" required>
            </div>
            <div class="uk-width-1-2@m">
                <label class="uk-form-label">Timezone</label>
                <input class="uk-input uk-form-small fm-mono" type="text" name="timezone" value="<?= fmH($timezone) ?>" placeholder="Europe/Rome">
            </div>
            <?php // Il sottopercorso web non si cambia da qui: lo decide
                  // l'installazione, e deve corrispondere a ciò che dice nginx.
                  // Il campo che c'era prima salvava un valore che nessuno
                  // leggeva. Si mostra soltanto, insieme all'istanza. ?>
            <div class="uk-width-1-2@m">
                <label class="uk-form-label">Istanza</label>
                <p class="uk-text-small fm-mono uk-margin-remove"><?= fmH(FM_INSTANCE) ?><span class="uk-text-meta"> · <?= fmH(FM_WEB_BASE !== '' ? FM_WEB_BASE . '/' : '/') ?></span></p>
                <p class="uk-text-meta uk-margin-remove">Configurata in <span class="fm-mono"><?= fmH(FM_CONF_FILE) ?></span></p>
            </div>
            <div class="uk-width-1-2@m">
                <label class="uk-form-label uk-display-block">&nbsp;</label>
                <label class="uk-text-small"><input type="checkbox" class="uk-checkbox" name="auth_enabled" <?= $authEnabled ? 'checked' : '' ?>> Richiedi autenticazione</label>
            </div>
            <div class="uk-width-1-2@m">
                <label class="uk-form-label">Nuova password (lascia vuoto per non modificare)</label>
                <input class="uk-input uk-form-small" type="password" name="password" autocomplete="new-password">
            </div>
        </div>
        <div class="uk-margin-top">
            <button type="submit" class="uk-button uk-button-primary uk-button-small">Salva</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/foot.php'; ?>
