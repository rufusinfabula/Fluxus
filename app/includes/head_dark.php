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
<?php // "Recursive": firma nel piè di pagina, vedi head.php. ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Recursive:wght@700&display=swap">
<link rel="stylesheet" href="<?= $fmWebBase ?>/assets/style.css">
</head>
<body class="ed-body">
<div style="position:sticky;top:0;z-index:980">
<?php include __DIR__ . '/nav.php'; ?>
</div>
<div class="ed-container">
