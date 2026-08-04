<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();

$sourceId = (int)($_GET['source_id'] ?? 0);
$mediaType = in_array($_GET['media_type'] ?? '', ['audio', 'video', 'clock'], true) ? $_GET['media_type'] : '';
$status = in_array($_GET['status'] ?? '', ['recording', 'completed', 'failed'], true) ? $_GET['status'] : '';
$date = $_GET['date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($sourceId > 0) { $where[] = 'r.source_id = ?'; $params[] = $sourceId; }
if ($mediaType) { $where[] = 'r.media_type = ?'; $params[] = $mediaType; }
if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
if ($date) { $where[] = 'date(r.start_time) = ?'; $params[] = $date; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM recordings r $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT r.*,
    CASE
        WHEN r.duration_seconds IS NOT NULL AND r.duration_seconds > 0 THEN r.duration_seconds
        WHEN r.start_time IS NOT NULL AND r.end_time IS NOT NULL
            THEN CAST((julianday(r.end_time) - julianday(r.start_time)) * 86400 AS INTEGER)
        ELSE r.duration_seconds
    END AS duration_seconds
    FROM recordings r $whereSql ORDER BY r.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $mStmt = $db->prepare("SELECT recording_id,
        SUM(CASE WHEN type='cue' AND clip_status='ready' THEN 1 ELSE 0 END) AS clips,
        COUNT(*) AS markers
        FROM markers WHERE recording_id IN ($in) GROUP BY recording_id");
    $mStmt->execute($ids);
    $counts = [];
    foreach ($mStmt->fetchAll() as $c) {
        $counts[$c['recording_id']] = ['clips' => (int)$c['clips'], 'markers' => (int)$c['markers']];
    }
} else {
    $counts = [];
}

$sources = $db->query('SELECT id, name, media_type FROM sources ORDER BY name ASC')->fetchAll();

$statusMeta = [
    'completed' => ['Completata', '#32d296'],
    'failed'    => ['Fallita',    '#f0506e'],
    'recording' => ['In corso',  '#f0506e'],
];

function fmQueryUrl(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) { if ($v === '' || $v === null) unset($params[$k]); }
    return '?' . http_build_query($params);
}

$webBase = rtrim(FM_WEB_BASE, '/');
$pageTitle = 'Registrazioni';
include __DIR__ . '/includes/head.php';
?>

<h2 class="fm-page-title"><span uk-icon="icon: album"></span> Registrazioni</h2>

<form method="get" class="uk-flex uk-grid-small uk-flex-middle uk-margin-bottom fm-filterbar" uk-grid>
    <div class="uk-width-auto">
        <select class="uk-select uk-form-small" name="media_type">
            <optgroup label="TIPO">
                <option value="">Tutti i tipi</option>
                <option value="audio" <?= $mediaType === 'audio' ? 'selected' : '' ?>>Audio</option>
                <option value="video" <?= $mediaType === 'video' ? 'selected' : '' ?>>Video</option>
                <option value="clock" <?= $mediaType === 'clock' ? 'selected' : '' ?>>Clock</option>
            </optgroup>
        </select>
    </div>
    <div class="uk-width-auto">
        <select class="uk-select uk-form-small" name="source_id">
            <optgroup label="SORGENTE">
                <option value="">Tutte le sorgenti</option>
                <?php foreach ($sources as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $sourceId === (int)$s['id'] ? 'selected' : '' ?>><?= fmH($s['name']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        </select>
    </div>
    <div class="uk-width-auto">
        <select class="uk-select uk-form-small" name="status">
            <optgroup label="STATO">
                <option value="">Tutti gli stati</option>
                <option value="recording" <?= $status === 'recording' ? 'selected' : '' ?>>In corso</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completata</option>
                <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Fallita</option>
            </optgroup>
        </select>
    </div>
    <div class="uk-width-auto">
        <input type="date" class="uk-input uk-form-small" name="date" value="<?= fmH($date) ?>">
    </div>
    <div class="uk-width-auto">
        <button type="submit" class="uk-button uk-button-primary uk-button-small">Filtra</button>
        <a href="recordings.php" class="uk-button uk-button-default uk-button-small">Reset</a>
    </div>
    <div class="uk-width-auto fm-filter-total">
        <span class="uk-text-meta"><?= (int)$total ?> registrazioni totali</span>
    </div>
</form>

<!-- Barra delle azioni di selezione: compare solo quando c'è almeno una riga spuntata. -->
<div id="fm-bulkbar" class="fm-bulkbar uk-flex uk-flex-middle uk-margin-small-bottom" hidden>
    <span class="uk-text-meta uk-margin-small-right"><strong id="fm-bulk-count">0</strong> selezionate</span>
    <button id="fm-bulk-delete" class="uk-button uk-button-danger uk-button-small" type="button">
        <span uk-icon="icon: trash; ratio: 0.8"></span> Elimina selezionate
    </button>
    <button id="fm-bulk-clear" class="uk-button uk-button-default uk-button-small uk-margin-small-left" type="button">
        Annulla selezione
    </button>
</div>

<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead>
        <tr>
            <th style="width:32px;">
                <input class="fm-dot-check fm-check-all" type="checkbox" title="Seleziona tutte"
                       onclick="event.stopPropagation()">
            </th>
            <th style="width:40px;">#</th>
            <th>Sorgente</th>
            <th style="width:130px;">Inizio</th>
            <th>Registrazione</th>
            <th style="width:60px;" uk-tooltip="Clip pronte"><span uk-icon="icon: copy; ratio: 0.9"></span></th>
            <th style="width:60px;" uk-tooltip="Marker"><span uk-icon="icon: bookmark; ratio: 0.9"></span></th>
            <th style="width:90px;">Durata</th>
            <th style="width:90px;">Dimensione</th>
            <th style="width:110px;">Stato</th>
            <th style="width:24px;"></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="11" class="uk-text-meta">Nessuna registrazione trovata con i filtri selezionati.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r):
        [$statusLabel, $statusColor] = $statusMeta[$r['status']] ?? [$r['status'], '#999'];
        $clips = $counts[$r['id']]['clips'] ?? 0;
        $markerCnt = $counts[$r['id']]['markers'] ?? 0;
        $sizeBytes = fmRecordingSize($r);
        $dur = (int)$r['duration_seconds'];
        // GB/h effettivi: rende immediatamente confrontabile il peso fra profili di qualità.
        $rate = ($sizeBytes > 0 && $dur > 60) ? ($sizeBytes / $dur) * 3600 : 0;
    ?>
        <tr class="fm-row-clickable" onclick="location.href='<?= $webBase ?>/recording.php?id=<?= fmRecCode((int)$r['id'], $r['media_type']) ?>'">
            <td onclick="event.stopPropagation()">
                <?php if ($r['status'] === 'recording'): ?>
                    <input class="fm-dot-check" type="checkbox" disabled
                           uk-tooltip="Registrazione in corso: fermala prima di eliminarla">
                <?php else: ?>
                    <input class="fm-dot-check fm-check-rec" type="checkbox"
                           value="<?= (int)$r['id'] ?>"
                           data-code="<?= fmH(fmRecCode((int)$r['id'], $r['media_type'])) ?>">
                <?php endif; ?>
            </td>
            <td class="fm-idx fm-mono"><?= fmRecCode((int)$r['id'], $r['media_type']) ?></td>
            <td>
                <?= fmH($r['source_name']) ?>
                <span style="margin-left:6px;"><?= fmMediaTypeBadge($r['media_type'], false) ?></span>
                <?php if (!empty($r['clock_origin'])): ?>
                <span class="uk-badge" style="background:#fdf1e0;color:#b45309;font-size:9px;margin-left:4px;"
                      uk-tooltip="Audio caricato a posteriori da un registratore CLOCK esterno">da CLOCK</span>
                <?php endif; ?>
            </td>
            <td class="fm-date"><?= fmH(fmFormatDateTime($r['start_time'])) ?></td>
            <td class="fm-filename"><?= fmH($r['filename_base']) ?></td>
            <td><span class="fm-tag <?= $clips > 0 ? 'fm-tag-on' : 'fm-tag-off' ?>"><span uk-icon="icon: copy; ratio: 0.65"></span><?= $clips > 0 ? $clips : '—' ?></span></td>
            <td><span class="fm-tag <?= $markerCnt > 0 ? 'fm-tag-on' : 'fm-tag-off' ?>"><span uk-icon="icon: bookmark; ratio: 0.65"></span><?= $markerCnt > 0 ? $markerCnt : '—' ?></span></td>
            <td class="fm-mono" style="font-size:12px;"><?= fmFormatDuration($r['duration_seconds']) ?></td>
            <td class="fm-mono" style="font-size:12px;"<?= $rate > 0 ? ' uk-tooltip="' . fmH(fmFormatBytes((int)$rate)) . '/ora"' : '' ?>>
                <?php if ($sizeBytes > 0): ?>
                    <?= fmH(fmFormatBytes($sizeBytes)) ?>
                <?php elseif (fmRecordingVolumeOffline($r)): ?>
                    <?php // Senza questo la riga mostrerebbe "—" senza spiegare che il disco è staccato. ?>
                    <span class="uk-text-warning" uk-tooltip="title: I file sono su un volume non collegato: <?= fmH($r['output_dir']) ?>">
                        <span uk-icon="icon: warning; ratio: 0.7"></span>
                    </span>
                <?php else: ?>
                    <span class="uk-text-meta">—</span>
                <?php endif; ?>
            </td>
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

<?php if ($totalPages > 1): ?>
<ul class="uk-pagination uk-flex-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="<?= $p === $page ? 'uk-active' : '' ?>"><?php if ($p === $page): ?><span><?= $p ?></span><?php else: ?><a href="<?= fmH(fmQueryUrl(['page' => $p])) ?>"><?= $p ?></a><?php endif; ?></li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<script>
(function () {
    var checks   = Array.prototype.slice.call(document.querySelectorAll('.fm-check-rec'));
    var checkAll = document.querySelector('.fm-check-all');
    var bar      = document.getElementById('fm-bulkbar');
    var counter  = document.getElementById('fm-bulk-count');
    var btnDel   = document.getElementById('fm-bulk-delete');
    var btnClear = document.getElementById('fm-bulk-clear');
    if (!checks.length || !bar) { if (checkAll) checkAll.disabled = true; return; }

    function selected() { return checks.filter(function (c) { return c.checked; }); }

    function refresh() {
        var n = selected().length;
        counter.textContent = n;
        bar.hidden = n === 0;
        // Lo stato "indeterminato" distingue a colpo d'occhio "alcune" da "tutte".
        checkAll.checked = n === checks.length && n > 0;
        checkAll.indeterminate = n > 0 && n < checks.length;
        // Il pallino è piccolo di proposito: è la riga a dire cosa è selezionato.
        checks.forEach(function (c) {
            var tr = c.closest('tr');
            if (tr) tr.classList.toggle('fm-row-selected', c.checked);
        });
    }

    checks.forEach(function (c) { c.addEventListener('change', refresh); });

    checkAll.addEventListener('change', function () {
        checks.forEach(function (c) { c.checked = checkAll.checked; });
        refresh();
    });

    btnClear.addEventListener('click', function () {
        checks.forEach(function (c) { c.checked = false; });
        refresh();
    });

    btnDel.addEventListener('click', function () {
        var sel = selected();
        if (!sel.length) return;
        var ids   = sel.map(function (c) { return c.value; });
        var codes = sel.map(function (c) { return c.dataset.code; });
        // L'elenco dei codici nella conferma evita l'errore più costoso: cancellare
        // le registrazioni sbagliate perché si era perso il conto delle spunte.
        var msg = '<p>Eliminare <strong>' + ids.length + '</strong> registrazion' +
                  (ids.length === 1 ? 'e' : 'i') + '?</p>' +
                  '<p class="uk-text-meta">' + codes.join(', ') + '</p>' +
                  '<p class="uk-text-meta">Vengono rimossi i file audio/video, i cue estratti ' +
                  'e i marker collegati. L\'operazione non è reversibile.</p>';

        UIkit.modal.confirm(msg, { labels: { ok: 'Elimina', cancel: 'Annulla' }, stack: true }).then(function () {
            btnDel.disabled = btnClear.disabled = true;
            btnDel.innerHTML = '<div uk-spinner="ratio: 0.5"></div> Eliminazione…';

            fetch('<?= $webBase ?>/api/recordings.php?ids=' + encodeURIComponent(ids.join(',')), { method: 'DELETE' })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                .then(function (res) {
                    if (!res.ok || !res.body.ok) throw new Error(res.body.error || 'Errore durante l\'eliminazione');
                    var b = res.body, msg = b.deleted.length + ' registrazioni eliminate';
                    if (b.skipped_running && b.skipped_running.length) {
                        msg += ', ' + b.skipped_running.length + ' saltate perché in corso';
                    }
                    UIkit.notification({ message: msg, status: 'success', pos: 'bottom-right' });
                    setTimeout(function () { location.reload(); }, 700);
                })
                .catch(function (e) {
                    btnDel.disabled = btnClear.disabled = false;
                    btnDel.innerHTML = '<span uk-icon="icon: trash; ratio: 0.8"></span> Elimina selezionate';
                    UIkit.notification({ message: e.message, status: 'danger', pos: 'bottom-right' });
                });
        }, function () { /* annullato dall'utente */ });
    });

    refresh();
})();
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
