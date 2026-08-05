CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);

-- Volumi di archiviazione: la riga id=1 è il volume interno (FM_BASE) e non è
-- eliminabile. Ogni volume replica la struttura recordings/{source_id} e
-- clips/{source_id} sotto la propria radice.
CREATE TABLE IF NOT EXISTS storage_volumes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    path       TEXT NOT NULL UNIQUE,
    is_default INTEGER DEFAULT 0,
    active     INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS sources (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    media_type            TEXT NOT NULL DEFAULT 'audio',  -- audio | video | clock
    url                   TEXT,                           -- NULL per v4l2 e per clock
    type                  TEXT NOT NULL,
    device                TEXT,
    file_prefix           TEXT,
    audio_quality         TEXT DEFAULT '2',
    resolution            TEXT DEFAULT '1920x1080',   -- solo v4l2 (cattura del device)
    fps                   INTEGER DEFAULT 25,         -- solo v4l2 (cattura del device)
    video_quality         TEXT DEFAULT 'copy',        -- copy | alta | media | bassa
    video_codec           TEXT DEFAULT 'copy',        -- DEPRECATA: sostituita da video_quality
    audio_codec           TEXT DEFAULT 'copy',        -- DEPRECATA: sostituita da video_quality
    extra_opts            TEXT,
    max_recordings        INTEGER DEFAULT 30,
    max_days_recordings   INTEGER DEFAULT 45,
    max_clips_per_marker  INTEGER DEFAULT 100,
    max_days_clips        INTEGER DEFAULT 20,
    storage_volume_id     INTEGER REFERENCES storage_volumes(id),  -- NULL = default del media_type
    federated_from        INTEGER REFERENCES federation_peers(id),
    active                INTEGER DEFAULT 1,
    created_at            TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS schedules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id        INTEGER NOT NULL REFERENCES sources(id),
    label            TEXT,
    on_calendar      TEXT NOT NULL,
    slot_duration    INTEGER DEFAULT 3600,
    segment_duration INTEGER DEFAULT 0,
    federated_from   INTEGER REFERENCES federation_peers(id),
    active           INTEGER DEFAULT 1,
    created_at       TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS recordings (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id        INTEGER REFERENCES sources(id) ON DELETE SET NULL,
    schedule_id      INTEGER REFERENCES schedules(id) ON DELETE SET NULL,
    source_name      TEXT NOT NULL,
    media_type       TEXT NOT NULL DEFAULT 'audio',
    filename_base    TEXT NOT NULL,
    output_dir       TEXT NOT NULL,
    clips_dir        TEXT,           -- NULL = FM_CLIPS/{source_id} (righe storiche)
    start_time       TEXT,
    end_time         TEXT,
    duration_seconds INTEGER,
    status           TEXT DEFAULT 'recording',
    trigger_type     TEXT DEFAULT 'scheduled',
    slot_duration    INTEGER DEFAULT 0,
    segment_duration INTEGER DEFAULT 0,
    width            INTEGER,
    height           INTEGER,
    fps_actual       INTEGER,
    ffmpeg_pid       INTEGER,
    notes            TEXT,
    clock_origin     INTEGER DEFAULT 0,  -- 1 = audio caricato a posteriori su una sessione CLOCK
    created_at       TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS markers (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    recording_id       INTEGER NOT NULL REFERENCES recordings(id) ON DELETE CASCADE,
    elapsed_seconds    INTEGER NOT NULL,
    elapsed_hms        TEXT NOT NULL,
    absolute_time      TEXT NOT NULL,
    label              TEXT,
    type               TEXT NOT NULL DEFAULT 'cue',
    clip_status        TEXT DEFAULT 'n/a',
    clip_filename      TEXT,
    clip_trim_filename TEXT,
    origin             TEXT NOT NULL DEFAULT 'local',
    origin_label       TEXT,  -- nome della console (via Fluxus Connect), se noto
    created_at         TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS manual_clips (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    recording_id     INTEGER NOT NULL REFERENCES recordings(id) ON DELETE CASCADE,
    segment_index    INTEGER,
    start_seconds    REAL NOT NULL,
    end_seconds      REAL NOT NULL,
    duration_seconds REAL NOT NULL,
    label            TEXT,
    clip_filename    TEXT,
    created_at       TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS federation_peers (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    url                 TEXT NOT NULL,
    api_key             TEXT NOT NULL,
    node_id             TEXT,
    node_type           TEXT,
    last_sync           TEXT,
    auto_sync_sources   INTEGER DEFAULT 1,
    auto_sync_schedules INTEGER DEFAULT 0,
    active              INTEGER DEFAULT 1,
    created_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS federation_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    peer_id    INTEGER REFERENCES federation_peers(id),
    action     TEXT,
    status     TEXT,
    message    TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_recordings_source ON recordings(source_id);
CREATE INDEX IF NOT EXISTS idx_recordings_status ON recordings(status);
CREATE INDEX IF NOT EXISTS idx_markers_recording ON markers(recording_id);
CREATE INDEX IF NOT EXISTS idx_markers_clip_status ON markers(clip_status);
CREATE INDEX IF NOT EXISTS idx_manual_clips_recording ON manual_clips(recording_id);
CREATE INDEX IF NOT EXISTS idx_schedules_source ON schedules(source_id);
