<?php
require_once __DIR__ . '/db.php';

/**
 * Avvia la sessione con parametri PROPRI di Fluxus, senza toccare il php.ini
 * di sistema (condiviso con gli altri siti di questo host).
 *
 * ⚠️ La `save_path` dedicata NON è un dettaglio: è la parte che funziona
 * davvero. Il servizio di sistema `phpsessionclean.timer` passa ogni 30 minuti
 * sulla save_path di default (/var/lib/php/sessions) e cancella i file più
 * vecchi del `gc_maxlifetime` letto dal php.ini — **ignorando** l'ini_set fatto
 * a runtime da noi. Con i file di sessione lì dentro, alzare gc_maxlifetime da
 * codice non serve a nulla: passa phpsessionclean e li cancella lo stesso.
 * Spostandoli in FM_SESSIONS restano fuori dalla sua vista e a farne la
 * manutenzione è il GC di PHP, che invece il nostro gc_maxlifetime lo rispetta.
 *
 * Anche il nome del cookie è dedicato: il vhost è condiviso con altre
 * applicazioni e un PHPSESSID comune su path '/' si calpesterebbe a vicenda.
 */
function fmSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Se la directory non è utilizzabile si resta sulla save_path di default:
    // meglio una sessione più corta che nessuna sessione.
    if (!is_dir(FM_SESSIONS)) {
        @mkdir(FM_SESSIONS, 0700, true);
    }
    if (is_dir(FM_SESSIONS) && is_writable(FM_SESSIONS)) {
        ini_set('session.save_path', FM_SESSIONS);
        ini_set('session.gc_maxlifetime', (string)FM_SESSION_TTL);
        // Con una save_path tutta nostra il GC lo deve fare PHP: phpsessionclean
        // qui non arriva. 1/100 delle richieste è più che sufficiente.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    session_name('FLUXUSSESSID');
    session_set_cookie_params([
        'lifetime' => FM_SESSION_TTL,
        'path'     => rtrim(FM_WEB_BASE, '/') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // Nessun 'secure': l'accesso in LAN è in HTTP, con secure il cookie
        // non verrebbe mai inviato e il login non funzionerebbe più.
    ]);

    session_start();

    // Il cookie di sessione ha una scadenza assoluta: senza rinnovarlo, dopo 6
    // ore il browser lo butta anche se la sessione lato server è viva. Si
    // riemette a ogni richiesta, così la finestra di 6 ore è di INATTIVITÀ.
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + FM_SESSION_TTL,
            'path'     => rtrim(FM_WEB_BASE, '/') . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

fmSession();

function fmAuthEnabled(): bool {
    return fmSetting('auth_enabled', '0') === '1';
}

function fmIsLoggedIn(): bool {
    return !empty($_SESSION['fm_logged_in']);
}

/**
 * Vero se chi chiama si aspetta JSON e non una pagina HTML.
 *
 * Serve a non rispondere con un redirect a login.php sulle chiamate API: il
 * frontend farebbe `r.json()` sull'HTML della pagina di login, otterrebbe
 * un'eccezione di parsing e — prima del 2026-07-30 — la ingoiava in silenzio,
 * facendo credere all'operatore che il marker fosse stato salvato.
 */
function fmWantsJson(): bool {
    if (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false) return true;
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) return true;
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function fmRequireAuth(): void {
    if (!fmAuthEnabled()) {
        return;
    }
    if (fmIsLoggedIn()) {
        return;
    }

    if (fmWantsJson()) {
        // 401 + JSON: il frontend lo riconosce e mostra l'avviso bloccante di
        // sessione scaduta invece di perdere il marker senza dire niente.
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'              => false,
            'error'           => 'Sessione scaduta: accesso non più valido.',
            'session_expired' => true,
        ]);
        exit;
    }

    $base = rtrim(FM_WEB_BASE, '/');
    header('Location: ' . $base . '/login.php');
    exit;
}

function fmLogin(string $pass): bool {
    $hash = fmSetting('password_hash', '');
    if ($hash === '' || !password_verify($pass, $hash)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['fm_logged_in'] = true;
    return true;
}

function fmLogout(): void {
    $_SESSION = [];
    session_destroy();
}
