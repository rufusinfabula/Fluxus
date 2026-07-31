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
<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js"></script>
</body>
</html>
