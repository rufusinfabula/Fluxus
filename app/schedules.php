<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();
fmRequireAuth();

$db = fmDB();
$errors = [];

function fmWriteScheduleUnits(int $id, string $onCalendar): void {
    // KillMode=none: run_schedule.sh lancia record.sh in background (nohup ... &) e esce subito.
    // Con il KillMode di default (control-group) systemd ucciderebbe record.sh non appena
    // il service oneshot termina, impedendo alla registrazione di partire davvero.
    //
    // FLUXUS_CONF nell'unit: il timer può scattare mentre la macchina ospita più
    // installazioni, e run_schedule.sh deve sapere di quale è.
    $unit = fmScheduleUnit($id);
    $service = "[Unit]\nDescription=" . FM_APP_NAME . " (" . FM_INSTANCE . ") Schedule {$id}\n\n"
        . "[Service]\nType=oneshot\nUser=" . FM_USER . "\nGroup=" . FM_GROUP . "\nKillMode=none\n"
        . "Environment=FLUXUS_CONF=" . FM_CONF_FILE . "\n"
        . "ExecStart=" . FM_SCRIPTS . "/run_schedule.sh {$id}\n";
    $timer = "[Unit]\nDescription=" . FM_APP_NAME . " (" . FM_INSTANCE . ") Schedule {$id} Timer\n\n[Timer]\nOnCalendar={$onCalendar}\nPersistent=false\n\n[Install]\nWantedBy=timers.target\n";

    $tmpService = FM_TMP . "/{$unit}.service";
    $tmpTimer = FM_TMP . "/{$unit}.timer";
    file_put_contents($tmpService, $service);
    file_put_contents($tmpTimer, $timer);

    $cmds = [
        'sudo -n install -m 644 ' . escapeshellarg($tmpService) . ' /etc/systemd/system/' . $unit . '.service',
        'sudo -n install -m 644 ' . escapeshellarg($tmpTimer) . ' /etc/systemd/system/' . $unit . '.timer',
        'sudo -n systemctl daemon-reload',
        'sudo -n systemctl enable --now ' . $unit . '.timer',
    ];
    foreach ($cmds as $c) { shell_exec($c . ' 2>&1'); }
    @unlink($tmpService);
    @unlink($tmpTimer);
}

function fmRemoveScheduleUnits(int $id): void {
    $unit = fmScheduleUnit($id);
    $cmds = [
        'sudo -n systemctl disable --now ' . $unit . '.timer',
        'sudo -n rm -f /etc/systemd/system/' . $unit . '.timer /etc/systemd/system/' . $unit . '.service',
        'sudo -n systemctl daemon-reload',
    ];
    foreach ($cmds as $c) { shell_exec($c . ' 2>&1'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM schedules WHERE id = ?')->execute([$id]);
        fmRemoveScheduleUnits($id);
        header('Location: schedules.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM schedules WHERE id = ?');
        $stmt->execute([$id]);
        $sched = $stmt->fetch();
        if ($sched) {
            $newActive = $sched['active'] ? 0 : 1;
            $db->prepare('UPDATE schedules SET active = ? WHERE id = ?')->execute([$newActive, $id]);
            if ($newActive) {
                fmWriteScheduleUnits($id, $sched['on_calendar']);
            } else {
                fmRemoveScheduleUnits($id);
            }
        }
        header('Location: schedules.php');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $sourceId = (int)($_POST['source_id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $onCalendar = trim($_POST['on_calendar'] ?? '');
    $slotDuration = (int)($_POST['slot_duration'] ?? 3600);
    $segmentDuration = (int)($_POST['segment_duration'] ?? 0);
    $active = !empty($_POST['active']) ? 1 : 0;

    if ($sourceId <= 0) $errors[] = 'Seleziona una sorgente.';
    if ($onCalendar === '') $errors[] = 'OnCalendar è obbligatorio (formato systemd, es. *-*-* 08:00:00).';
    if ($slotDuration <= 0) $errors[] = 'La durata dello slot deve essere positiva.';

    if (empty($errors)) {
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE schedules SET source_id=?, label=?, on_calendar=?, slot_duration=?, segment_duration=?, active=? WHERE id=?');
            $stmt->execute([$sourceId, $label, $onCalendar, $slotDuration, $segmentDuration, $active, $id]);
        } else {
            $stmt = $db->prepare('INSERT INTO schedules (source_id, label, on_calendar, slot_duration, segment_duration, active)
                VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$sourceId, $label, $onCalendar, $slotDuration, $segmentDuration, $active]);
            $id = (int)$db->lastInsertId();
        }
        if ($active) {
            fmWriteScheduleUnits($id, $onCalendar);
        } else {
            fmRemoveScheduleUnits($id);
        }
        header('Location: schedules.php');
        exit;
    }
}

$schedules = $db->query("SELECT sc.*, s.name AS source_name, s.media_type
    FROM schedules sc LEFT JOIN sources s ON s.id = sc.source_id
    ORDER BY sc.active DESC, sc.id DESC")->fetchAll();

// Le sorgenti CLOCK non hanno alcun flusso: l'avvio è solo manuale, non schedulabile.
$sources = $db->query("SELECT id, name, media_type FROM sources WHERE active = 1 AND media_type != 'clock' ORDER BY name ASC")->fetchAll();

// Monitor timer systemd: stato ultimo/prossimo scatto + ultima registrazione generata.
$monitor = [];
foreach ($schedules as $s) {
    $timer = fmGetTimerStatus((int)$s['id']);
    $stmt = $db->prepare("SELECT filename_base, status, start_time FROM recordings WHERE schedule_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$s['id']]);
    $rec = $stmt->fetch();

    if (!$s['active']) {
        $esito = ['inattivo', '#faa05a'];
    } elseif ($timer['last'] === null) {
        $esito = ['in attesa', '#999'];
    } else {
        $matched = false;
        if ($rec && $rec['start_time']) {
            $recTs = strtotime($rec['start_time'] . ' UTC');
            if ($recTs !== false && abs($recTs - $timer['last']) <= 240) {
                $matched = true;
            }
        }
        $esito = $matched ? ['avviata', '#32d296'] : ['mancata', '#f0506e'];
    }

    $monitor[] = [
        'source_name' => $s['source_name'],
        'label'       => $s['label'],
        'on_calendar' => $s['on_calendar'],
        'last'        => $timer['last'],
        'next'        => $timer['next'],
        'rec'         => $rec,
        'esito'       => $esito,
    ];
}

$pageTitle = 'Orari';
include __DIR__ . '/includes/head.php';
?>

<div class="uk-flex uk-flex-middle uk-flex-between uk-margin-bottom" style="flex-wrap:wrap;gap:10px;">
    <h2 class="fm-page-title uk-margin-remove"><span uk-icon="icon: calendar"></span> Orari programmati</h2>
    <button class="uk-button uk-button-primary" type="button" onclick="fmOpenScheduleModal()">
        <span uk-icon="icon: plus-circle; ratio: 0.9"></span> Aggiungi orario
    </button>
</div>

<?php foreach ($errors as $e): ?>
<div class="uk-alert-danger" uk-alert><span uk-icon="icon: warning"></span> <?= fmH($e) ?></div>
<?php endforeach; ?>

<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead><tr>
        <th>Sorgente</th><th>Etichetta</th><th>OnCalendar</th><th style="width:90px;">Durata slot</th><th style="width:90px;">Segmento</th><th style="width:90px;">Stato</th><th style="width:70px;">Azioni</th>
    </tr></thead>
    <tbody>
    <?php if (empty($schedules)): ?>
        <tr><td colspan="7" class="uk-text-meta">Nessun orario configurato.</td></tr>
    <?php endif; ?>
    <?php foreach ($schedules as $s): $isVideo = $s['media_type'] === 'video'; ?>
        <tr>
            <td>
                <strong><?= fmH($s['source_name'] ?? '—') ?></strong>
                <span class="<?= $isVideo ? 'fm-badge-video' : 'fm-badge-audio' ?>" style="margin-left:6px;"><?= $isVideo ? 'VIDEO' : 'AUDIO' ?></span>
            </td>
            <td><?= fmH($s['label'] ?: '—') ?></td>
            <td><span class="fm-prefix-chip"><?= fmH($s['on_calendar']) ?></span></td>
            <td class="fm-mono" style="font-size:12px;"><?= fmH(fmFormatSlotDuration($s['slot_duration'])) ?></td>
            <td class="fm-mono" style="font-size:12px;"><?= $s['segment_duration'] ? fmH(round($s['segment_duration'] / 60) . ' min') : '—' ?></td>
            <td>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="fm-status-pill <?= $s['active'] ? 'fm-status-pill-on' : 'fm-status-pill-off' ?>" style="border:none;cursor:pointer;">
                        <?= $s['active'] ? 'Attivo' : 'Inattivo' ?>
                    </button>
                </form>
            </td>
            <td>
                <a href="#" class="fm-action-icon" uk-icon="icon: pencil; ratio: 0.8" uk-tooltip="Modifica"
                   onclick='fmOpenScheduleModal(<?= json_encode($s) ?>);return false;'></a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare questo orario?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" class="fm-action-icon uk-text-danger" uk-icon="icon: trash; ratio: 0.8" uk-tooltip="Elimina" style="background:none;border:none;cursor:pointer;"></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<h3 class="fm-section-title uk-margin-top uk-margin-small-bottom" style="font-size:13px;text-transform:none;letter-spacing:0;color:#333;font-weight:600;">
    <span uk-icon="icon: history; ratio: 0.9"></span> Monitor timer systemd
</h3>
<div class="uk-overflow-auto">
<table class="uk-table uk-table-small uk-table-middle fm-table">
    <thead><tr>
        <th>Sorgente / Etichetta</th><th>OnCalendar</th><th style="width:130px;">Ultimo scatto</th><th style="width:130px;">Prossimo scatto</th><th>Registrazione</th><th style="width:90px;">Esito</th>
    </tr></thead>
    <tbody>
    <?php if (empty($monitor)): ?>
        <tr><td colspan="6" class="uk-text-meta">Nessun orario configurato.</td></tr>
    <?php endif; ?>
    <?php foreach ($monitor as $m): [$esitoLabel, $esitoColor] = $m['esito']; ?>
        <tr>
            <td>
                <div><strong><?= fmH($m['source_name']) ?></strong></div>
                <div class="uk-text-meta" style="font-size:11px;"><?= fmH($m['label'] ?: '—') ?></div>
            </td>
            <td><span class="fm-prefix-chip"><?= fmH($m['on_calendar']) ?></span></td>
            <td class="fm-mono" style="font-size:12px;color:#666;"><?= fmH(fmFormatTs($m['last'])) ?></td>
            <td class="fm-mono" style="font-size:12px;color:#1e87f0;"><?= fmH(fmFormatTs($m['next'])) ?></td>
            <td class="fm-mono" style="font-size:12px;">
                <?php if ($m['rec']): ?>
                <span style="color:#1e87f0;"><?= fmH($m['rec']['filename_base']) ?></span>
                <span class="uk-text-meta" style="font-family:inherit;"><?= fmH($m['rec']['status']) ?></span>
                <?php else: ?>
                <span class="uk-text-meta">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="fm-status-dot" style="display:inline-block;background:<?= $esitoColor ?>;"></span>
                <span style="color:<?= $esitoColor ?>;font-size:12px;"><?= fmH($esitoLabel) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="uk-text-meta" style="font-size:11px;margin-top:6px;">
    <span uk-icon="icon: info; ratio: 0.8"></span>
    "Mancata" = il timer è scattato ma non esiste una registrazione entro pochi minuti dall'orario previsto. Aggiorna la pagina per aggiornare il monitor.
</p>

<div class="uk-alert-primary uk-margin-top" uk-alert>
    <p class="uk-margin-remove">
        <span uk-icon="icon: info"></span>
        Il campo <strong>OnCalendar</strong> usa la sintassi systemd:
        <code><?= fmH('Mon..Fri 07:02') ?></code>,
        <code><?= fmH('*-*-* 20:00') ?></code>,
        <code><?= fmH('Mon,Wed,Fri 08:30') ?></code>.
        <a href="https://www.freedesktop.org/software/systemd/man/systemd.time.html#Calendar%20Events" target="_blank" rel="noopener">Documentazione &rarr;</a>
    </p>
</div>

<div id="fm-schedule-modal" uk-modal>
    <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical fm-modal-wide">
        <button class="uk-modal-close-default" type="button" uk-close></button>
        <h2 class="uk-modal-title" id="fm-schedule-modal-title">Nuovo orario</h2>
        <form method="post" id="fm-schedule-form">
            <input type="hidden" name="id" id="fm-s-id" value="0">
            <input type="hidden" name="slot_duration" id="fm-s-slot-hidden" value="3600">
            <input type="hidden" name="segment_duration" id="fm-s-segment-hidden" value="0">

            <div class="uk-grid-small" uk-grid>
                <div class="uk-width-1-2@s">
                    <label class="uk-form-label">Sorgente <span class="fm-required">*</span></label>
                    <select class="uk-select uk-form-small" name="source_id" id="fm-s-source" required>
                        <option value="">-- seleziona --</option>
                        <?php foreach ($sources as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" data-media="<?= fmH($s['media_type']) ?>">
                            <?= fmH($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="uk-width-1-2@s">
                    <label class="uk-form-label">Etichetta</label>
                    <input class="uk-input uk-form-small" type="text" name="label" id="fm-s-label" placeholder="es. Rassegna del mattino">
                </div>

                <div class="uk-width-1-1">
                    <label class="uk-form-label">OnCalendar <span class="fm-required">*</span> <span class="uk-text-meta">(formato systemd)</span></label>
                    <input class="uk-input uk-form-small fm-mono" type="text" name="on_calendar" id="fm-s-oncalendar" placeholder="Mon..Fri 07:02" autocomplete="off" required>
                    <div class="uk-text-meta" style="font-size:11px;margin-top:6px;">
                        Esempi:
                        <a href="#" class="fm-oncalendar-example fm-mono">Mon..Fri 07:02</a> ·
                        <a href="#" class="fm-oncalendar-example fm-mono">Mon,Wed 20:00</a> ·
                        <a href="#" class="fm-oncalendar-example fm-mono">*-*-* 12:00</a>
                    </div>
                    <div id="fm-s-validation" style="font-size:12px;margin-top:6px;min-height:18px;"></div>
                </div>

                <div class="uk-width-1-2@s">
                    <label class="uk-form-label">Durata slot</label>
                    <input type="range" class="uk-range" id="fm-s-slot-range" min="5" max="240" step="5" value="60">
                    <div class="fm-mono" style="font-size:13px;font-weight:600;margin-top:4px;" id="fm-s-slot-label">1 h 0 min</div>
                </div>
                <div class="uk-width-1-2@s">
                    <label class="uk-form-label">
                        <input type="checkbox" class="uk-checkbox" id="fm-s-segment-enabled"> Segmentazione file
                    </label>
                    <input type="range" class="uk-range" id="fm-s-segment-range" min="1" max="120" step="1" value="30" disabled>
                    <div class="uk-text-meta" style="font-size:12px;margin-top:4px;" id="fm-s-segment-label">disattivata</div>
                    <div class="uk-text-meta" style="font-size:11px;" id="fm-s-segment-hint"></div>
                </div>

                <div class="uk-width-1-2@s">
                    <label class="uk-form-label">Stato</label>
                    <select class="uk-select uk-form-small" name="active" id="fm-s-active">
                        <option value="1">Attivo</option>
                        <option value="0">Inattivo</option>
                    </select>
                </div>
            </div>
            <div class="uk-flex uk-flex-right uk-grid-small uk-margin-top" uk-grid>
                <div class="uk-width-auto"><button type="button" class="uk-button uk-button-default uk-button-small" onclick="UIkit.modal('#fm-schedule-modal').hide()">Annulla</button></div>
                <div class="uk-width-auto"><button type="submit" class="uk-button uk-button-primary uk-button-small" id="fm-s-save-btn">Salva</button></div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var base = <?= json_encode(rtrim(FM_WEB_BASE, '/')) ?>;
    var onCalInput = document.getElementById('fm-s-oncalendar');
    var validationEl = document.getElementById('fm-s-validation');
    var saveBtn = document.getElementById('fm-s-save-btn');
    var validOnCalendar = false;
    var debounceTimer = null;

    function fmtSlot(min) {
        var h = Math.floor(min / 60), m = min % 60;
        return h > 0 ? (h + ' h ' + m + ' min') : (m + ' min');
    }

    function updateSlotLabel() {
        var min = parseInt(document.getElementById('fm-s-slot-range').value, 10);
        document.getElementById('fm-s-slot-label').textContent = fmtSlot(min);
        document.getElementById('fm-s-slot-hidden').value = min * 60;
        updateSegmentHint();
    }

    function updateSegmentLabel() {
        var enabled = document.getElementById('fm-s-segment-enabled').checked;
        var range = document.getElementById('fm-s-segment-range');
        range.disabled = !enabled;
        if (!enabled) {
            document.getElementById('fm-s-segment-label').textContent = 'disattivata';
            document.getElementById('fm-s-segment-hidden').value = 0;
            document.getElementById('fm-s-segment-hint').textContent = '';
            return;
        }
        var min = parseInt(range.value, 10);
        document.getElementById('fm-s-segment-label').textContent = min + ' min per file';
        document.getElementById('fm-s-segment-hidden').value = min * 60;
        updateSegmentHint();
    }

    function updateSegmentHint() {
        var enabled = document.getElementById('fm-s-segment-enabled').checked;
        var hintEl = document.getElementById('fm-s-segment-hint');
        if (!enabled) { hintEl.textContent = ''; return; }
        var slotMin = parseInt(document.getElementById('fm-s-slot-range').value, 10);
        var segMin = parseInt(document.getElementById('fm-s-segment-range').value, 10);
        if (segMin <= 0) { hintEl.textContent = ''; return; }
        var n = Math.ceil(slotMin / segMin);
        hintEl.textContent = '→ Creerà ' + n + ' file da ' + segMin + ' min ciascuno per slot.';
    }

    document.getElementById('fm-s-slot-range').addEventListener('input', updateSlotLabel);
    document.getElementById('fm-s-segment-range').addEventListener('input', updateSegmentLabel);
    document.getElementById('fm-s-segment-enabled').addEventListener('change', updateSegmentLabel);

    document.querySelectorAll('.fm-oncalendar-example').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            onCalInput.value = a.textContent;
            validateOnCalendar();
        });
    });

    function validateOnCalendar() {
        var expr = onCalInput.value.trim();
        onCalInput.style.borderColor = '';
        onCalInput.style.color = '';
        if (expr === '') {
            validationEl.innerHTML = '';
            validOnCalendar = false;
            return;
        }
        fetch(base + '/api/validate_oncalendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ expr: expr })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d.ok) {
                  onCalInput.style.borderColor = '#32d296';
                  onCalInput.style.color = '#32d296';
                  validationEl.innerHTML = '<span style="color:#32d296;">&#10003; Valido — Prossima: ' + d.next + ' (' + d.normalized + ')</span>';
                  validOnCalendar = true;
              } else {
                  onCalInput.style.borderColor = '#f0506e';
                  validationEl.innerHTML = '<span style="color:#f0506e;">&#10007; ' + d.error + '</span>';
                  validOnCalendar = false;
              }
          }).catch(function () { validationEl.innerHTML = ''; });
    }

    onCalInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(validateOnCalendar, 350);
    });

    document.getElementById('fm-schedule-form').addEventListener('submit', function (e) {
        if (!validOnCalendar) {
            e.preventDefault();
            validationEl.innerHTML = '<span style="color:#f0506e;">Inserisci un OnCalendar valido prima di salvare.</span>';
        }
    });

    window.fmOpenScheduleModal = function (s) {
        var f = s || { id: 0, source_id: '', label: '', on_calendar: '', slot_duration: 3600, segment_duration: 0, active: 1 };
        document.getElementById('fm-schedule-modal-title').textContent = s ? 'Modifica orario' : 'Nuovo orario';
        document.getElementById('fm-s-id').value = f.id || 0;
        document.getElementById('fm-s-source').value = f.source_id || '';
        document.getElementById('fm-s-label').value = f.label || '';
        document.getElementById('fm-s-active').value = String(f.active ?? 1);

        onCalInput.value = f.on_calendar || '';
        validationEl.innerHTML = '';
        validOnCalendar = !!f.on_calendar;
        if (f.on_calendar) validateOnCalendar();

        var slotMin = Math.max(5, Math.round((f.slot_duration || 3600) / 60));
        document.getElementById('fm-s-slot-range').value = slotMin;
        updateSlotLabel();

        var segSec = f.segment_duration || 0;
        document.getElementById('fm-s-segment-enabled').checked = segSec > 0;
        document.getElementById('fm-s-segment-range').value = segSec > 0 ? Math.round(segSec / 60) : 30;
        updateSegmentLabel();

        UIkit.modal('#fm-schedule-modal').show();
    };
})();
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
