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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css">
<?php // Icone di UIkit v2 (Font Awesome 4): usate per i tipi di disco in
      // Impostazioni e nella barra di stato. Definisce solo .fa-*, non tocca UIkit 3. ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css">
<?php // "Recursive" (peso 700): usato solo per la firma nel piè di pagina. Con
      // display=swap, se Google Fonts non è raggiungibile resta il font di
      // sistema e non si perde nulla. ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Recursive:wght@700&display=swap">
<link rel="stylesheet" href="<?= $fmWebBase ?>/assets/style.css">
</head>
<body>
<div style="position:sticky;top:0;z-index:980">
<?php include __DIR__ . '/nav.php'; ?>
</div>
<div class="uk-container uk-margin-top uk-margin-bottom">
