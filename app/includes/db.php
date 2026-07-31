<?php
require_once __DIR__ . '/config.php';

function fmDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . FM_DB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Attende invece di fallire subito se un altro processo sta scrivendo.
        // Senza questo, con api/status.php in polling ogni 5s, remote_sync ogni 5s,
        // extract_clips ogni 30s e gli script bash, le scritture concorrenti
        // fallivano con SQLITE_BUSY perdendo l'aggiornamento (vedi vincolo 15).
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function fmJson($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fmError(string $msg, int $code = 400): void {
    fmJson(['error' => $msg], $code);
}

function fmSetting(string $key, $default = null) {
    $stmt = fmDB()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

function fmSetSetting(string $key, string $value): void {
    $stmt = fmDB()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}
