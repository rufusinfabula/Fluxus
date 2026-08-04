<?php
/**
 * Richiede $recording (riga di recordings) e $markers (righe di markers,
 * ordinate per elapsed_seconds) già valorizzate dal chiamante.
 */
$fmWebBase = rtrim(FM_WEB_BASE, '/');
$fmIsVideo = ($recording['media_type'] ?? 'audio') === 'video';
$fmCuePlaySvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="6,4 20,12 6,20"></polygon></svg>';
?>
<h3 class="uk-margin-remove-bottom uk-flex uk-flex-middle" style="gap:8px;font-size:22px;font-weight:600;color:#333;">
    <span uk-icon="icon: bookmark; ratio: 1.1"></span> Marker <span class="uk-text-meta" style="font-size:16px;font-weight:400;">(<?= count($markers) ?>)</span>
</h3>
<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table fm-markers-table">
    <thead>
        <tr>
            <th style="width:90px;">Tempo</th>
            <th style="width:150px;">Timestamp</th>
            <th>Etichetta</th>
            <th style="width:50px;">ID</th>
            <th style="width:80px;">Tipo</th>
            <th style="width:70px;">Remote</th>
            <th style="width:150px;">Cue</th>
            <th style="width:40px;"></th>
        </tr>
    </thead>
    <tbody id="fm-markers-tbody">
    <?php if (empty($markers)): ?>
        <tr><td colspan="8" class="uk-text-meta">Nessun marker/cue.</td></tr>
    <?php endif; ?>
    <?php foreach ($markers as $m):
        $isCue = $m['type'] === 'cue';
        $badgeClass = $isCue ? 'fm-badge-cue' : 'fm-badge-marker';
        $badgeLabel = $isCue ? 'CUE' : 'MARKER';
    ?>
        <tr data-marker-id="<?= (int)$m['id'] ?>">
            <td class="fm-mono" style="font-size:13px;"><?= fmH($m['elapsed_hms']) ?></td>
            <td class="uk-text-meta fm-mono" style="font-size:12px;"><?= fmH($m['absolute_time']) ?></td>
            <td><?= fmH($m['label'] ?: '—') ?></td>
            <td class="uk-text-meta fm-mono" style="font-size:12px;"><?= (int)$m['id'] ?></td>
            <td><span class="uk-badge <?= $badgeClass ?>" style="font-size:9px;"><?= $badgeLabel ?></span></td>
            <td>
                <?php if (($m['origin'] ?? 'local') === 'remote'): ?>
                <span class="fm-remote-flag" uk-tooltip="Creato da Fluxus Remote">
                    <span uk-icon="icon: check; ratio: 0.7"></span>
                </span>
                <?php else: ?>
                <span class="uk-text-meta">—</span>
                <?php endif; ?>
            </td>
            <td>
            <?php if (!$isCue): ?>
                <?php if (($recording['media_type'] ?? '') !== 'clock'): ?>
                <a href="#" class="fm-action-icon" uk-icon="icon: arrow-up; ratio: 0.8" uk-tooltip="Promuovi a cue (ritaglia una clip)"
                   onclick="fmPromoteMarker(<?= (int)$m['id'] ?>);return false;"></a>
                <?php else: ?>
                <span class="uk-text-meta">—</span>
                <?php endif; ?>
            <?php elseif ($m['clip_status'] === 'pending'): ?>
                <span class="uk-text-meta" uk-icon="icon: clock; ratio: 0.8"></span> <span class="uk-text-meta">in estrazione&hellip;</span>
            <?php elseif ($m['clip_status'] === 'failed'): ?>
                <span class="uk-text-danger">fallita</span>
            <?php elseif ($m['clip_status'] === 'ready'):
                $clipUrl = $fmWebBase . '/api/download.php?cue_id=' . (int)$m['id'];
                $trimUrl = $fmWebBase . '/api/download.php?cue_id=' . (int)$m['id'] . '&trim=1';
            ?>
                <div class="uk-flex uk-flex-middle" style="gap:2px;flex-wrap:wrap;justify-content:center;">
                <?php if ($fmIsVideo): ?>
                    <a href="#" class="fm-action-icon fm-cue-play-btn" uk-tooltip="Riproduci"
                       onclick="fmToggleCuePlayer(this, '<?= fmH($clipUrl) ?>', 'video');return false;"><?= $fmCuePlaySvg ?></a>
                    <a href="<?= fmH($clipUrl) ?>" class="fm-action-icon" uk-icon="icon: download; ratio: 0.8" uk-tooltip="Scarica" download></a>
                <?php else: ?>
                    <a href="#" class="fm-action-icon fm-cue-play-btn" uk-tooltip="Riproduci"
                       onclick="fmToggleCuePlayer(this, '<?= fmH($clipUrl) ?>', 'audio');return false;"><?= $fmCuePlaySvg ?></a>
                    <a href="<?= fmH($clipUrl) ?>" class="fm-action-icon" uk-icon="icon: download; ratio: 0.8" uk-tooltip="Scarica originale" download></a>
                    <?php if (!empty($m['clip_trim_filename'])): ?>
                    <a href="<?= fmH($trimUrl) ?>" class="fm-action-icon" uk-icon="icon: file-edit; ratio: 0.8" uk-tooltip="Scarica trim" download></a>
                    <?php endif; ?>
                    <?php /* Link "Ritaglia in EDIT" rimosso: TRIM/EDIT manuale in quarantena, vedi docs/NOTE-TECNICHE.md. */ ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </td>
            <td>
                <a href="#" class="fm-action-icon uk-text-danger" uk-icon="icon: trash; ratio: 0.8" uk-tooltip="Elimina"
                   onclick="fmDeleteMarker(<?= (int)$m['id'] ?>);return false;"></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<script>
var fmMarkersRecordingId = <?= (int)($recording['id'] ?? 0) ?>;

function fmRefreshMarkersTable() {
    var wrap = document.getElementById('fm-markers-wrap');
    if (!wrap || !fmMarkersRecordingId) return;
    fetch(<?= json_encode($fmWebBase) ?> + '/recording.php?id=' + fmMarkersRecordingId + '&partial=markers')
        .then(function (r) { return r.text(); })
        .then(function (html) { wrap.innerHTML = html; });
}

function fmPromoteMarker(id) {
    if (!confirm('Promuovere questo marker a cue? Verrà tagliata una clip.')) return;
    fetch(<?= json_encode($fmWebBase) ?> + '/api/marker.php?id=' + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'promote' })
    }).then(function (r) { return r.json(); })
      .then(function (d) {
          if (d.ok) {
              fmRefreshMarkersTable();
          } else {
              alert(d.error || 'Errore durante la promozione');
          }
      }).catch(function () { alert('Errore di rete'); });
}

function fmDeleteMarker(id) {
    if (!confirm('Eliminare questo marker/cue?')) return;
    fetch(<?= json_encode($fmWebBase) ?> + '/api/marker.php?id=' + id, { method: 'DELETE' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                var row = document.querySelector('tr[data-marker-id="' + id + '"]');
                var next = row ? row.nextElementSibling : null;
                if (next && next.classList.contains('fm-player-row')) next.remove();
                if (row) row.remove();
            } else {
                alert(d.error || 'Errore eliminazione');
            }
        });
}

var FM_CUE_PLAY_ICON = '<?= $fmCuePlaySvg ?>';
var FM_CUE_STOP_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12"></rect></svg>';

function fmCloseCuePlayers() {
    document.querySelectorAll('.fm-player-row').forEach(function (row) { row.remove(); });
    document.querySelectorAll('.fm-cue-play-btn').forEach(function (btn) { btn.innerHTML = FM_CUE_PLAY_ICON; });
}

function fmToggleCuePlayer(el, url, kind) {
    var tr = el.closest('tr');
    var next = tr.nextElementSibling;
    var wasOpen = next && next.classList.contains('fm-player-row');

    fmCloseCuePlayers();
    if (wasOpen) return;

    var td = document.createElement('td');
    td.colSpan = 8;
    td.style.padding = '10px 0 14px';

    if (kind === 'video') {
        td.innerHTML = '<video controls autoplay style="width:100%;max-height:360px;border-radius:4px;background:#000;" src="' + url + '"></video>';
    } else {
        td.innerHTML = '<audio controls autoplay class="fm-inline-audio" src="' + url + '"></audio>';
    }

    var playerRow = document.createElement('tr');
    playerRow.className = 'fm-player-row';
    playerRow.appendChild(td);
    tr.insertAdjacentElement('afterend', playerRow);

    el.innerHTML = FM_CUE_STOP_ICON;
    var media = td.querySelector('audio, video');
    media.addEventListener('ended', function () { fmCloseCuePlayers(); });
}
</script>
