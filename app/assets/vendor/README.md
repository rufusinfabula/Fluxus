# Dipendenze dell'interfaccia

Fogli di stile, icone, player e font che l'interfaccia usa, **serviti da
Fluxus stesso** e non da una CDN.

Non è una rifinitura. Una macchina appena portata in un posto nuovo non ha
ancora una connessione, e la prima pagina che serve aprire è proprio quella che
configura la rete: se il foglio di stile arrivasse da Internet, arriverebbe
senza stile e senza icone esattamente nel momento in cui serve.

## Cosa c'è

| File | Versione | Licenza | Serve a |
|---|---|---|---|
| `uikit-3.21.6.min.css` | UIkit 3.21.6 | MIT | tutta l'interfaccia |
| `uikit-3.21.6.min.js` | UIkit 3.21.6 | MIT | modali, dropdown, notifiche |
| `uikit-icons-3.21.6.min.js` | UIkit 3.21.6 | MIT | le icone `uk-icon` |
| `hls.light-1.6.16.min.js` | hls.js 1.6.16 | Apache-2.0 | anteprima live delle sorgenti |
| `wavesurfer-7.12.11.min.js` | wavesurfer.js 7.12.11 | BSD-3-Clause | forma d'onda del ritaglio |
| `wavesurfer-regions-7.12.11.min.js` | wavesurfer.js 7.12.11 | BSD-3-Clause | selezione del ritaglio |
| `fonts/recursive-700-latin.woff2` | Recursive, peso 700, latino | OFL 1.1 | la firma nel piè di pagina |

Il testo integrale di ogni licenza è in `LICENSES/`.

Circa 950 KB in tutto, di cui hls.js è più di un terzo. Sono serviti dalla rete
locale, una volta, e poi restano nella cache del browser: i nomi contengono la
versione, quindi la regola nginx li marca come immutabili.

## Due scelte da spiegare

**hls.js è nella build `light`.** Rinuncia a DRM, tracce audio alternative e
sottotitoli: l'anteprima non ne ha nessuno — `scripts/preview.sh` produce un
HLS mpegts a variante singola, un video e un audio — e sono 180 KB in meno.

**Del font Recursive c'è il solo sottoinsieme latino**, non il font variabile
completo (oltre 1 MB): serve per due parole nel piè di pagina, e la regola
`@font-face` sta in `../style.css`.

**wavesurfer.js c'è anche se la pagina che lo usa è in quarantena**
(`edit-trim.php`, vedi *TRIM/EDIT manuale* nelle note tecniche). Il motivo è che
«nessuna dipendenza esterna» resta così una proprietà verificabile con un grep,
e riattivare quella pagina non reintroduce di nascosto una chiamata a Internet.

## Come si aggiornano

Non si scaricano a mano. L'elenco delle versioni, degli indirizzi e delle
impronte `sha256` vive in `packaging/vendor-assets.sh`:

```bash
packaging/vendor-assets.sh --check    # verifica quello che c'è
packaging/vendor-assets.sh            # riscarica e sostituisce
```

Cambiare versione vuol dire cambiare il nome del file nell'elenco, la sua
impronta e i riferimenti nelle pagine che lo caricano (`includes/head.php`,
`includes/head_dark.php`, `includes/foot.php`, `includes/preview_modal.php`,
`login.php`, `edit-trim.php`), poi aggiornare questa tabella.
