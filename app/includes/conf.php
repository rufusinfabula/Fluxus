<?php
/**
 * Fluxus — configurazione dell'istanza, per l'applicazione.
 *
 * Gemello PHP di scripts/fluxus-env.sh: stesso file, stesso formato, stesse
 * regole di derivazione. Se si cambia qualcosa qui va cambiato anche là.
 *
 * ── Come trova la configurazione ─────────────────────────────────────────────
 *
 * 1. la variabile d'ambiente FLUXUS_CONF, se c'è: la impostano gli script che
 *    caricano l'applicazione da riga di comando (remote_sync.php) e nginx, se
 *    lo si vuole dichiarare nel vhost;
 * 2. altrimenti includes/instance.php, un file di una riga scritto
 *    dall'installer, che dice a quale istanza appartiene questa copia
 *    dell'applicazione (non è versionato: dipende dall'installazione);
 * 3. altrimenti il nome della cartella che contiene l'applicazione:
 *    /var/www/fluxus-dev  →  istanza 'fluxus-dev'  →  /etc/fluxus/fluxus-dev.conf.
 *
 * Se non la trova si ferma con un errore leggibile. Un percorso indovinato, su
 * una macchina con due installazioni, scriverebbe nella cartella dell'altra.
 */

const FM_CONF_DIR = '/etc/fluxus';

/** Si ferma con un messaggio comprensibile, in pagina e nel log. */
function fmConfFail(string $message): void {
    $text = "Fluxus: configurazione non trovata.\n" . $message . "\n";
    error_log(str_replace("\n", ' ', $text));
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $text;
    exit(1);
}

/** Il file di configurazione di questa istanza, secondo l'ordine descritto sopra. */
function fmConfFile(): string {
    $env = getenv('FLUXUS_CONF');
    if (!is_string($env) || $env === '') {
        $env = $_SERVER['FLUXUS_CONF'] ?? '';
    }
    if (is_string($env) && $env !== '') {
        return $env;
    }

    $declared = __DIR__ . '/instance.php';
    if (is_file($declared)) {
        $instance = require $declared;
        if (is_string($instance) && trim($instance) !== '') {
            return FM_CONF_DIR . '/' . trim($instance) . '.conf';
        }
    }

    // La radice web è la cartella che contiene includes/.
    $instance = basename(dirname(__DIR__));
    if ($instance === '' || $instance === '.' || $instance === '/') {
        fmConfFail('Non riesco a dedurre il nome dell\'istanza dalla posizione dell\'applicazione.');
    }
    return FM_CONF_DIR . '/' . $instance . '.conf';
}

/**
 * Legge un file CHIAVE=valore. Commenti con '#', valore letterale fino a fine
 * riga, virgolette esterne opzionali (servono solo per gli spazi in testa o in
 * coda). Nessuna sostituzione di variabili.
 *
 * Volutamente non usa parse_ini_file(): quella, davanti a un commento scritto
 * con '#' invece che con ';', fallisce sull'intero file e restituisce false
 * senza dire niente. È già costato una diagnosi.
 *
 * @return array<string,string>
 */
function fmReadConfFile(string $file): array {
    $out = [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = ltrim(rtrim($line, "\r"));
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = rtrim(substr($line, 0, $pos));
        if (!preg_match('/^FLUXUS_[A-Z0-9_]+$/', $key)) continue;
        $value = trim(substr($line, $pos + 1));
        $len = strlen($value);
        if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"')
                       || ($value[0] === "'" && $value[$len - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        $out[$key] = $value;
    }
    return $out;
}

/**
 * La configurazione completa: ciò che il file dichiara, più i valori derivati
 * dal nome dell'istanza per tutto quello che tace.
 *
 * @return array<string,string>
 */
function fmInstanceConf(): array {
    $file = fmConfFile();
    if (!is_readable($file)) {
        fmConfFail("Il file $file non esiste o non è leggibile.\n"
            . "Se questa è un'installazione nuova, va creato dall'installer;\n"
            . "un esempio commentato è in config/fluxus.conf.example.");
    }

    $c = fmReadConfFile($file);
    $c['FLUXUS_CONF_FILE'] = $file;

    if (($c['FLUXUS_INSTANCE'] ?? '') === '') {
        fmConfFail("In $file manca FLUXUS_INSTANCE.");
    }
    $i = $c['FLUXUS_INSTANCE'];

    $c['FLUXUS_APP_NAME']    = $c['FLUXUS_APP_NAME']    ?? 'Fluxus';
    $c['FLUXUS_DATA_DIR']    = $c['FLUXUS_DATA_DIR']    ?? '/var/lib/' . $i;
    $c['FLUXUS_WEB_DIR']     = $c['FLUXUS_WEB_DIR']     ?? '/var/www/' . $i;
    $c['FLUXUS_WEB_BASE']    = $c['FLUXUS_WEB_BASE']    ?? '/' . $i;   // può essere vuoto
    $c['FLUXUS_USER']        = $c['FLUXUS_USER']        ?? 'www-data';
    $c['FLUXUS_GROUP']       = $c['FLUXUS_GROUP']       ?? 'www-data';
    $c['FLUXUS_UNIT_PREFIX'] = $c['FLUXUS_UNIT_PREFIX'] ?? $i;
    $c['FLUXUS_RTMP_HOST']   = $c['FLUXUS_RTMP_HOST']   ?? '127.0.0.1';
    $c['FLUXUS_RTMP_PORT']   = $c['FLUXUS_RTMP_PORT']   ?? '1935';
    $c['FLUXUS_MEDIAMTX_API']     = rtrim($c['FLUXUS_MEDIAMTX_API'] ?? 'http://127.0.0.1:9997', '/');
    $c['FLUXUS_HW_ENCODER']       = $c['FLUXUS_HW_ENCODER']      ?? 'libx264';
    $c['FLUXUS_HW_ENCODER_OPTS']  = $c['FLUXUS_HW_ENCODER_OPTS'] ?? '-preset veryfast -crf 23';

    // I segreti del relay stanno accanto, in un file con permessi più stretti.
    $remote = dirname($file) . '/' . $i . '.remote.conf';
    $r = is_readable($remote) ? fmReadConfFile($remote) : [];
    $c['FLUXUS_REMOTE_URL']     = rtrim($r['FLUXUS_REMOTE_URL'] ?? '', '/');
    $c['FLUXUS_REMOTE_API_KEY'] = $r['FLUXUS_REMOTE_API_KEY'] ?? '';
    $c['FLUXUS_NODE_NAME']      = ($r['FLUXUS_NODE_NAME'] ?? '') !== ''
        ? $r['FLUXUS_NODE_NAME']
        : (gethostname() ?: $i);

    // Stesso trattamento, stesso file a parte, per il token di Fluxus Connect.
    $connect = dirname($file) . '/' . $i . '.connect.conf';
    $k = is_readable($connect) ? fmReadConfFile($connect) : [];
    $c['FLUXUS_CONNECT_URL']   = rtrim($k['FLUXUS_CONNECT_URL'] ?? '', '/');
    $c['FLUXUS_CONNECT_TOKEN'] = $k['FLUXUS_CONNECT_TOKEN'] ?? '';

    return $c;
}

/**
 * La versione: la dice il file VERSION, unica fonte in tutto il progetto.
 * Nel repository sta nella radice, in un'installazione viene copiato dentro la
 * radice web accanto alle pagine.
 */
function fmReadVersion(): string {
    foreach ([dirname(__DIR__) . '/VERSION', dirname(__DIR__, 2) . '/VERSION'] as $f) {
        if (is_readable($f)) {
            $v = trim((string)file_get_contents($f));
            if ($v !== '') return $v;
        }
    }
    return 'sconosciuta';
}
