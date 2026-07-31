<?php
/**
 * Fluxus — costanti dell'applicazione.
 *
 * Qui dentro non c'è più nessun percorso: tutto arriva dal file di
 * configurazione dell'istanza, che è ciò che permette di installare Fluxus due
 * volte sulla stessa macchina. Vedi includes/conf.php per come viene trovato,
 * e config/fluxus.conf.example per cosa contiene.
 *
 * Le costanti FM_* restano quelle di sempre, con gli stessi nomi: i percorsi
 * nel codice passano sempre da loro, mai scritti a mano.
 */

require_once __DIR__ . '/conf.php';

$_fmConf = fmInstanceConf();

define('FM_VERSION',    fmReadVersion());
define('FM_APP_NAME',   $_fmConf['FLUXUS_APP_NAME']);
define('FM_INSTANCE',   $_fmConf['FLUXUS_INSTANCE']);
define('FM_NODE_TYPE',  FM_INSTANCE);
define('FM_CONF_FILE',  $_fmConf['FLUXUS_CONF_FILE']);

define('FM_BASE',       rtrim($_fmConf['FLUXUS_DATA_DIR'], '/'));
define('FM_WEB_DIR',    rtrim($_fmConf['FLUXUS_WEB_DIR'], '/'));
define('FM_WEB_BASE',   rtrim($_fmConf['FLUXUS_WEB_BASE'], '/'));   // '' = radice del vhost

// Le cartelle sotto la radice dati non sono configurabili: sono la struttura,
// non una scelta. Il nome del file di database resta 'fluxus_media.db' anche
// per le istanze che si chiamano diversamente — sta già dentro una cartella per
// istanza, non può collidere, e cambiarlo renderebbe illeggibile ogni
// installazione esistente senza alcun vantaggio.
define('FM_DB',         FM_BASE . '/db/fluxus_media.db');
define('FM_RECORDINGS', FM_BASE . '/recordings');
define('FM_CLIPS',      FM_BASE . '/clips');
define('FM_TMP',        FM_BASE . '/tmp');
define('FM_SCRIPTS',    FM_BASE . '/scripts');
define('FM_LOGS',       FM_BASE . '/logs');

// Utente di sistema e prefisso dei servizi: servono a generare gli unit degli
// orari (schedules.php) e a spiegare in interfaccia perché un disco non è
// scrivibile.
define('FM_USER',        $_fmConf['FLUXUS_USER']);
define('FM_GROUP',       $_fmConf['FLUXUS_GROUP']);
define('FM_UNIT_PREFIX', $_fmConf['FLUXUS_UNIT_PREFIX']);

// Cartella che Fluxus crea sulla radice di un disco esterno. Porta il nome
// dell'istanza: due installazioni sulla stessa macchina possono usare lo stesso
// disco senza sovrascriversi le registrazioni.
define('FM_VOLUME_DIR', FM_INSTANCE);

// Sessione: 6 ore, la durata di uno show lungo con le sue pause. Vive in una
// save_path dedicata (FM_SESSIONS) perché il php.ini di sistema e il timer
// phpsessionclean restano invariati per gli altri siti dell'host — vedi
// fmSession() in auth.php.
define('FM_SESSIONS',    FM_BASE . '/sessions');
define('FM_SESSION_TTL', 21600);              // secondi (6h)

// Durata massima di un relay di anteprima lasciato aperto (preview.sh).
define('FM_PREVIEW_TTL', 900);                // secondi (15 min)

// Server RTMP (MediaMTX) da cui arrivano le sorgenti 'rtmp-push'.
define('FM_RTMP_HOST',    $_fmConf['FLUXUS_RTMP_HOST']);
define('FM_RTMP_PORT',    $_fmConf['FLUXUS_RTMP_PORT']);
define('FM_MEDIAMTX_API', $_fmConf['FLUXUS_MEDIAMTX_API']);

// Encoder di ripiego. Gli script di registrazione NON lo leggono: l'encoder è
// determinato dal profilo di qualità della sorgente (sources.video_quality).
define('FM_HW_ENCODER',      $_fmConf['FLUXUS_HW_ENCODER']);
define('FM_HW_ENCODER_OPTS', $_fmConf['FLUXUS_HW_ENCODER_OPTS']);

// Fluxus Remote (relay per marker/cue da fuori LAN, v2.1) — vuoto = disattivato
define('FM_REMOTE_URL',      $_fmConf['FLUXUS_REMOTE_URL']);
define('FM_REMOTE_API_KEY',  $_fmConf['FLUXUS_REMOTE_API_KEY']);
define('FM_NODE_NAME',       $_fmConf['FLUXUS_NODE_NAME']);

unset($_fmConf);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_init.php';
