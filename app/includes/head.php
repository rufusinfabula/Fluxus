<?php
$pageTitle = $pageTitle ?? FM_APP_NAME;
$fmWebBase = rtrim(FM_WEB_BASE, '/');
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= fmH($pageTitle) ?> — <?= fmH(FM_APP_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $fmWebBase ?>/icons/fluxus-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $fmWebBase ?>/icons/fluxus-64.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $fmWebBase ?>/icons/fluxus-180.png">
<?php // Tutto ciò che serve alla pagina è servito da qui: niente CDN, l'interfaccia
      // deve funzionare su una macchina che non ha ancora una connessione.
      // Versioni, licenze e come si aggiornano: assets/vendor/README.md. ?>
<link rel="stylesheet" href="<?= $fmWebBase ?>/assets/vendor/uikit-3.21.6.min.css">
<?php // Il font della firma nel piè di pagina è dichiarato in style.css. ?>
<link rel="stylesheet" href="<?= $fmWebBase ?>/assets/style.css">
</head>
<body>
<div id="fm-topbar" style="position:sticky;top:0;z-index:980">
<?php include __DIR__ . '/nav.php'; ?>
</div>
<div class="uk-container uk-margin-top uk-margin-bottom">
