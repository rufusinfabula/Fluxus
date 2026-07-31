<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
fmTimezone();

if (fmIsLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if (fmLogin($pass)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Password errata.';
}

$webBase = rtrim(FM_WEB_BASE, '/');
$pageTitle = 'Login';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= fmH(FM_APP_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $webBase ?>/icons/fluxus-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $webBase ?>/icons/fluxus-64.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $webBase ?>/icons/fluxus-180.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css">
<?php // "Recursive": firma nel piè di pagina, vedi includes/head.php. ?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Recursive:wght@700&display=swap">
<link rel="stylesheet" href="<?= $webBase ?>/assets/style.css">
</head>
<body class="uk-background-muted">
<div class="uk-flex uk-flex-center uk-flex-middle fm-login-wrap">
    <div class="uk-width-medium">
        <div class="uk-text-center uk-margin-bottom">
            <img src="<?= $webBase ?>/icons/fluxus-256.png" alt="<?= fmH(FM_APP_NAME) ?>" class="fm-login-logo">
            <h2 class="uk-margin-small-top uk-margin-remove-bottom"><?= fmH(FM_APP_NAME) ?></h2>
            <div class="uk-text-meta">v<?= fmH(FM_VERSION) ?></div>
        </div>
        <div class="uk-card uk-card-default uk-card-body uk-border-rounded">
            <?php if ($error): ?><div class="uk-alert-danger" uk-alert><span uk-icon="icon: warning"></span> <?= fmH($error) ?></div><?php endif; ?>
            <form method="post">
                <div class="uk-margin">
                    <input class="uk-input" type="password" name="password" placeholder="Password" autofocus required>
                </div>
                <button type="submit" class="uk-button fm-login-btn uk-width-1-1">Accedi</button>
            </form>
        </div>
        <p class="uk-text-center fm-login-note uk-margin-small-top">
            Nodo <code><?= fmH(fmSetting('node_name', FM_APP_NAME)) ?></code>
        </p>
        <?php /* Versione e copyright come nel piè di pagina delle altre pagine
                 (qui non c'è foot.php: la schermata di login è a sé). */ ?>
        <p class="uk-text-center fm-login-note">&copy; <?= date('Y') ?> &middot; a <span class="fm-signature">Fabio Ranfi</span> solution</p>
    </div>
</div>
</body>
</html>
