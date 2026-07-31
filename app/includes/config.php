<?php
define('FM_VERSION',    '2.5.2');
define('FM_APP_NAME',   'Fluxus');
define('FM_NODE_TYPE',  'fluxus-media');
define('FM_BASE',       '/var/lib/fluxus-media');
define('FM_DB',         FM_BASE . '/db/fluxus_media.db');
define('FM_RECORDINGS', FM_BASE . '/recordings');
define('FM_CLIPS',      FM_BASE . '/clips');
define('FM_TMP',        FM_BASE . '/tmp');
define('FM_SCRIPTS',    FM_BASE . '/scripts');
define('FM_LOGS',       FM_BASE . '/logs');
define('FM_WEB_BASE',   '/fluxus-media');     // '' = root, '/fluxus-media' se subpath

// Sessione: 6 ore, la durata di uno show lungo con le sue pause. Vive in una
// save_path dedicata (FM_SESSIONS) perché il php.ini di sistema e il timer
// phpsessionclean restano invariati per gli altri siti dell'host — vedi
// fmSession() in auth.php.
define('FM_SESSIONS',    FM_BASE . '/sessions');
define('FM_SESSION_TTL', 21600);              // secondi (6h)

// Encoder hardware (da setup.sh)
$_env = @parse_ini_file('/etc/fluxus-media.env') ?: [];
define('FM_HW_ENCODER',      $_env['FM_HW_ENCODER']      ?? 'libx264');
define('FM_HW_ENCODER_OPTS', $_env['FM_HW_ENCODER_OPTS'] ?? '-preset fast -crf 23');

// Fluxus Remote (relay per marker/cue da fuori LAN, v2.1) — vuoto = disattivato
$_remoteEnv = @parse_ini_file('/etc/fluxus-remote.env') ?: [];
define('FM_REMOTE_URL',      rtrim($_remoteEnv['FM_REMOTE_URL'] ?? '', '/'));
define('FM_REMOTE_API_KEY',  $_remoteEnv['FM_REMOTE_API_KEY'] ?? '');
define('FM_NODE_NAME',       $_remoteEnv['FM_NODE_NAME'] ?? (gethostname() ?: 'fluxus'));

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_init.php';
