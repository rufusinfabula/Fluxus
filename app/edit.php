<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

// TRIM/EDIT manuale in quarantena (vedi docs/NOTE-TECNICHE.md): pagina disattivata,
// nessuna query sui cue viene eseguita. Codice originale sotto, invariato.
$pageTitle = 'EDIT';
include __DIR__ . '/includes/head_dark.php';
?>

<div class="ed-header">
    <div class="ed-header-title">EDIT — in pausa</div>
</div>

<div class="ed-card">
    <p class="uk-text-meta" style="color:#666;margin-top:0;">
        La funzionalità di ritaglio (TRIM/EDIT manuale) è temporaneamente in quarantena.
        Non è disponibile al momento; i cue restano estratti e scaricabili dalla pagina della registrazione.
    </p>
</div>

<?php include __DIR__ . '/includes/foot.php'; exit; ?>

<?php
$db = fmDB();

$cues = $db->query("SELECT m.*, r.source_name, r.filename_base, r.source_id
    FROM markers m
    JOIN recordings r ON r.id = m.recording_id
    WHERE r.media_type = 'audio' AND m.clip_status = 'ready' AND m.type = 'cue'
    ORDER BY m.id DESC")->fetchAll();

$webBase = rtrim(FM_WEB_BASE, '/');
?>

<div class="ed-card">
    <p class="uk-text-meta" style="color:#666;margin-top:0;">Solo i cue estratti da registrazioni audio compaiono qui. I cue video non hanno editor di trim.</p>

    <div class="uk-overflow-auto">
    <table class="ed-table">
        <thead><tr><th>Sorgente</th><th>Registrazione</th><th>Tempo</th><th>Etichetta</th><th>Trim</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($cues)): ?>
            <tr><td colspan="6" style="color:#555;">Nessun cue audio pronto per il trim.</td></tr>
        <?php endif; ?>
        <?php foreach ($cues as $c): ?>
            <tr>
                <td><?= fmH($c['source_name']) ?></td>
                <td class="fm-mono" style="font-size:11px;color:#888;"><?= fmH($c['filename_base']) ?></td>
                <td class="fm-mono"><?= fmH($c['elapsed_hms']) ?></td>
                <td><?= fmH($c['label'] ?: '—') ?></td>
                <td>
                    <?php if ($c['clip_trim_filename']): ?>
                    <span class="ed-pill ed-pill-green">salvato</span>
                    <?php else: ?>
                    <span style="color:#555;">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;"><a class="ed-btn ed-btn-primary" href="<?= $webBase ?>/edit-trim.php?marker_id=<?= (int)$c['id'] ?>">Ritaglia</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/includes/foot.php'; ?>
