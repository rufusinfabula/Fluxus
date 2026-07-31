</div>
<?php /* Piè di pagina comune a tutte le pagine: copyright con l'anno corrente,
         versione in chiaro (utile quando si segnala un problema) e firma. */ ?>
<footer class="fm-footer">
    <span>&copy; <?= date('Y') ?></span>
    <span class="fm-footer-sep">·</span>
    <span><?= fmH(FM_APP_NAME) ?> <span class="fm-mono">v<?= fmH(FM_VERSION) ?></span></span>
    <span class="fm-footer-sep">·</span>
    <span>a <span class="fm-signature">Fabio Ranfi</span> solution</span>
</footer>
<?php /* Serviti da Fluxus, non da CDN: vedi head.php e assets/vendor/README.md.
         Il percorso si ricalcola qui invece di riusare la variabile della
         testata: così questo file non dipende da chi lo include. */
      $fmFootWebBase = rtrim(FM_WEB_BASE, '/'); ?>
<script src="<?= $fmFootWebBase ?>/assets/vendor/uikit-3.21.6.min.js"></script>
<script src="<?= $fmFootWebBase ?>/assets/vendor/uikit-icons-3.21.6.min.js"></script>
</body>
</html>
