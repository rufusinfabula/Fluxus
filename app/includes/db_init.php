<?php
// Auto-inizializzazione DB al primo accesso.

$_fm_needs_init = !file_exists(FM_DB);

if (!$_fm_needs_init) {
    try {
        $_fm_check = new PDO('sqlite:' . FM_DB);
        $_fm_check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $_fm_row = $_fm_check->query("SELECT value FROM settings WHERE key='node_id'")->fetchColumn();
        if ($_fm_row === false) {
            $_fm_needs_init = true;
        }
    } catch (Exception $e) {
        $_fm_needs_init = true;
    }
    unset($_fm_check, $_fm_row);
}

if ($_fm_needs_init) {
    foreach ([FM_BASE, dirname(FM_DB), FM_RECORDINGS, FM_CLIPS, FM_TMP, FM_SCRIPTS, FM_LOGS] as $_fm_dir) {
        if (!is_dir($_fm_dir)) {
            mkdir($_fm_dir, 0775, true);
        }
    }

    $_db = new PDO('sqlite:' . FM_DB);
    $_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $_db->exec('PRAGMA journal_mode = WAL');
    $_db->exec('PRAGMA busy_timeout = 5000');
    $_db->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('node_id', '" . fmGenerateUUID() . "')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('node_name', '" . FM_APP_NAME . "')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('timezone', 'Europe/Rome')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('auth_enabled', '0')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('password_hash', '')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES
        ('federation_api_key', '" . bin2hex(random_bytes(24)) . "')");
    $_db->exec("INSERT OR IGNORE INTO settings VALUES ('schema_version', '2')");
    unset($_db);
    @chmod(FM_DB, 0664);
}

// Migrazioni incrementali per DB già esistenti (aggiunta colonne senza perdere dati).
$_fm_schema_version = 6;
try {
    $_fm_mdb = new PDO('sqlite:' . FM_DB);
    $_fm_mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $_fm_cur = (int)($_fm_mdb->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn() ?: 1);

    if ($_fm_cur < 2) {
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(sources)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $_fm_add = [
            'file_prefix'          => "ALTER TABLE sources ADD COLUMN file_prefix TEXT",
            'max_recordings'       => "ALTER TABLE sources ADD COLUMN max_recordings INTEGER DEFAULT 30",
            'max_days_recordings'  => "ALTER TABLE sources ADD COLUMN max_days_recordings INTEGER DEFAULT 45",
            'max_clips_per_marker' => "ALTER TABLE sources ADD COLUMN max_clips_per_marker INTEGER DEFAULT 100",
            'max_days_clips'       => "ALTER TABLE sources ADD COLUMN max_days_clips INTEGER DEFAULT 20",
        ];
        foreach ($_fm_add as $_fm_col => $_fm_sql) {
            if (!in_array($_fm_col, $_fm_cols, true)) {
                $_fm_mdb->exec($_fm_sql);
            }
        }
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '2')
            ON CONFLICT(key) DO UPDATE SET value = '2'");
    }

    if ($_fm_cur < 3) {
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(markers)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('origin', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE markers ADD COLUMN origin TEXT NOT NULL DEFAULT 'local'");
        }
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '3')
            ON CONFLICT(key) DO UPDATE SET value = '3'");
    }
    if ($_fm_cur < 4) {
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(sources)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('video_quality', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE sources ADD COLUMN video_quality TEXT DEFAULT 'copy'");
        }
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '4')
            ON CONFLICT(key) DO UPDATE SET value = '4'");
    }
    if ($_fm_cur < 5) {
        // Archiviazione su più volumi: elenco dei volumi, override per sorgente e
        // cartella dei cue persistita per registrazione (vedi vincolo 21).
        $_fm_mdb->exec("CREATE TABLE IF NOT EXISTS storage_volumes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            label      TEXT NOT NULL,
            path       TEXT NOT NULL UNIQUE,
            is_default INTEGER DEFAULT 0,
            active     INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $_fm_mdb->exec("INSERT OR IGNORE INTO storage_volumes (id, label, path, is_default, active)
            VALUES (1, 'Interno (microSD)', '" . FM_BASE . "', 1, 1)");

        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(sources)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('storage_volume_id', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE sources ADD COLUMN storage_volume_id INTEGER");
        }
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(recordings)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('clips_dir', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE recordings ADD COLUMN clips_dir TEXT");
        }

        $_fm_mdb->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('storage_volume_audio', '1')");
        $_fm_mdb->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('storage_volume_video', '1')");
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '5')
            ON CONFLICT(key) DO UPDATE SET value = '5'");
    }
    if ($_fm_cur < 6) {
        // Upload audio a posteriori su una sessione CLOCK conclusa: la riga
        // recordings passa da media_type 'clock' ad 'audio' (vedi api/clock_upload.php),
        // e questa colonna serve solo a mostrare un badge secondario "da CLOCK" —
        // nessuna logica applicativa ne dipende.
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(recordings)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('clock_origin', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE recordings ADD COLUMN clock_origin INTEGER DEFAULT 0");
        }
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '6')
            ON CONFLICT(key) DO UPDATE SET value = '6'");
    }

    if ($_fm_cur < 7) {
        // Nome della console che ha creato un marker via Fluxus Connect (arriva
        // come subkey_name dalla coda comandi) — solo un'etichetta per il badge
        // in markers_table.php, nessuna logica applicativa ne dipende.
        $_fm_cols = array_column($_fm_mdb->query("PRAGMA table_info(markers)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('origin_label', $_fm_cols, true)) {
            $_fm_mdb->exec("ALTER TABLE markers ADD COLUMN origin_label TEXT");
        }
        $_fm_mdb->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '7')
            ON CONFLICT(key) DO UPDATE SET value = '7'");
    }

    unset($_fm_mdb, $_fm_cur, $_fm_cols, $_fm_add, $_fm_col, $_fm_sql);
} catch (Exception $e) {
    // silenzioso: se la migrazione fallisce, le pagine che usano le nuove colonne segnaleranno l'errore
}

// WAL sui DB già esistenti: in modalità 'delete' un lettore blocca uno scrittore, e
// con il polling di api/status.php e fm-remote-sync le scritture di record.sh /
// stop_recording.sh fallivano con SQLITE_BUSY perdendosi in silenzio (vincolo 15).
// journal_mode è persistente nell'header del DB: si applica una volta sola.
try {
    $_fm_wdb = new PDO('sqlite:' . FM_DB);
    $_fm_wdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $_fm_wdb->exec('PRAGMA busy_timeout = 5000');
    if (strtolower((string)$_fm_wdb->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal') {
        $_fm_wdb->exec('PRAGMA journal_mode = WAL');
    }
    unset($_fm_wdb);
} catch (Exception $e) {
    // silenzioso: il DB resta usabile in modalità rollback journal
}

unset($_fm_needs_init, $_fm_dir, $_fm_schema_version);
