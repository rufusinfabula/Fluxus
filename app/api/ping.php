<?php
/**
 * Keep-alive della sessione.
 *
 * Chiamato periodicamente dalle pagine che restano aperte a lungo (dashboard e
 * recording) mentre c'è una registrazione in corso: ogni richiesta riscrive il
 * file di sessione e ne aggiorna l'mtime, che è ciò su cui si basa il garbage
 * collector. Una sessione con la pagina aperta quindi non scade; una sessione
 * davvero abbandonata (browser chiuso) scade come prima.
 *
 * Se la sessione è già scaduta risponde 401 grazie a fmRequireAuth(): è anche
 * il modo in cui il frontend se ne accorge PRIMA che l'operatore prema Marker,
 * invece di scoprirlo perdendo un cue.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
fmRequireAuth();

fmJson([
    'ok'           => true,
    'auth_enabled' => fmAuthEnabled(),
    'ttl'          => FM_SESSION_TTL,
    'server_time'  => date('c'),
]);
