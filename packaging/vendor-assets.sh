#!/bin/bash
# Fluxus — rigenera app/assets/vendor/ scaricando le dipendenze dell'interfaccia.
#
#     packaging/vendor-assets.sh            scarica, verifica, sostituisce
#     packaging/vendor-assets.sh --check    verifica ciò che c'è, senza scaricare
#
# ⚠️ Questo script NON serve per installare Fluxus. I file che produce sono
# versionati nel repository, ed è questo il punto: l'interfaccia deve funzionare
# su una macchina che Internet non ce l'ha ancora — è la pagina di rete a
# dipenderne, e la si apre proprio quando la rete manca. Chi installa trova le
# dipendenze già al loro posto; qui si passa solo per aggiornarle.
#
# Ogni file porta la versione nel nome: un aggiornamento cambia l'indirizzo e
# non può essere servito dalla cache del browser al posto del nuovo.
#
# Le impronte sono fissate a mano dopo aver controllato ciò che si è scaricato.
# Se una non torna, lo script si ferma e non tocca nulla: né una CDN che cambia
# un file sotto lo stesso indirizzo né una connessione che tronca a metà devono
# poter entrare nel repository di nascosto.

set -euo pipefail
export LC_ALL=C

VENDOR_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../app/assets" && pwd)/vendor

die() { printf 'vendor-assets.sh: %s\n' "$*" >&2; exit 1; }

# ── Elenco delle dipendenze ───────────────────────────────────────────────────
# destinazione (relativa a app/assets/vendor/) | indirizzo | sha256
#
# hls.js è nella build 'light': rinuncia a DRM, tracce audio alternative e
# sottotitoli, che l'anteprima non ha e non avrà — scripts/preview.sh produce un
# HLS mpegts a variante singola. Sono 180 KB in meno della build completa.
#
# Del font Recursive si prende il solo sottoinsieme latino servito da Google
# Fonts, non il font variabile completo (oltre 1 MB): serve per due parole nel
# piè di pagina. L'indirizzo contiene la versione del ritaglio (v44) e cambia
# quando Google lo rigenera; se un giorno restituisce 404, lo si ricava di nuovo
# da https://fonts.googleapis.com/css2?family=Recursive:wght@700&display=swap
# chiedendolo con uno user agent da browser recente (altrimenti risponde con
# @font-face in ttf).
MANIFEST=$(cat <<'FINE'
uikit-3.21.6.min.css|https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css|e603f2a4d8af570acfc0cf4379f2f12c668d6342d3fbefa0815b568df25cdbb4
uikit-3.21.6.min.js|https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js|10b67b120b82a80ed88d69cebc00955a094aede9922057bc5fb094d86b3fa09a
uikit-icons-3.21.6.min.js|https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js|d851038051f2efd10490435479f6f81af29e200ba4ba140d078f8c2ac6fa851b
hls.light-1.6.16.min.js|https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.light.min.js|947b45be1392e39569d4aefc2cff9ff6ecbe6cc246cf67ab12ece0c41c24b793
wavesurfer-7.12.11.min.js|https://cdn.jsdelivr.net/npm/wavesurfer.js@7.12.11/dist/wavesurfer.min.js|a943cbe71700ae910d1c6db28fc56e06327f2a890e93fa22e4d736aa2f5797da
wavesurfer-regions-7.12.11.min.js|https://cdn.jsdelivr.net/npm/wavesurfer.js@7.12.11/dist/plugins/regions.min.js|fa36e84bd0f22e332e5ac4d0264967e350375fa00418bc18d095bb0e0116e16d
fonts/recursive-700-latin.woff2|https://fonts.gstatic.com/s/recursive/v44/8vJN7wMr0mhh-RQChyHEH06TlXhq_gukbYrFMk1QuAIcyEwG_X-dpEfaE5YaERmK-CImKsvxvU-MXGX2fSqasNfUvz2xbXfn1uEQadBllH17tQ0.woff2|12eb0f8840ec739048935d77869fa583e8a56e6ac3757e2f8d864c7d4b3218a4
LICENSES/uikit-MIT.txt|https://cdn.jsdelivr.net/npm/uikit@3.21.6/LICENSE.md|1aad791f51d28f466734553711181c9f676877a1899a760ac07171718213cee2
LICENSES/hls.js-Apache-2.0.txt|https://cdn.jsdelivr.net/npm/hls.js@1.6.16/LICENSE|ca8773cf798c7ed997d4dd7c8e23c348699f8d5b7462636694cc14de6cda12db
LICENSES/wavesurfer.js-BSD-3-Clause.txt|https://cdn.jsdelivr.net/npm/wavesurfer.js@7.12.11/LICENSE|0fc992dc27cc34dde78bfeb024b4cadff6320f0dcbee2f1228efaeb533ca1364
LICENSES/recursive-OFL-1.1.txt|https://raw.githubusercontent.com/arrowtype/recursive/main/OFL.txt|f9f539cf7549bd417159dbdb9c400943a5b60a7366c2c6fbde9f095173d82479
FINE
)

# ── Verifica di ciò che c'è già ───────────────────────────────────────────────

if [[ "${1:-}" == "--check" ]]; then
    mancanti=0
    while IFS='|' read -r dest url sha; do
        [[ -n "$dest" ]] || continue
        file="$VENDOR_DIR/$dest"
        if [[ ! -f "$file" ]]; then
            printf 'MANCA     %s\n' "$dest"; mancanti=1; continue
        fi
        if [[ "$(sha256sum "$file" | cut -d' ' -f1)" != "$sha" ]]; then
            printf 'DIVERSO   %s\n' "$dest"; mancanti=1; continue
        fi
        printf 'ok        %s\n' "$dest"
    done <<< "$MANIFEST"
    [[ $mancanti -eq 0 ]] || die "app/assets/vendor/ non corrisponde all'elenco"
    echo "Tutte le dipendenze corrispondono."
    exit 0
fi

[[ "${1:-}" == "" ]] || die "argomento non riconosciuto: $1 (solo --check)"

# ── Scaricamento ──────────────────────────────────────────────────────────────
# Prima tutto in una cartella temporanea, poi si sostituisce: un errore a metà
# elenco non lascia app/assets/vendor/ mezzo vecchio e mezzo nuovo.

command -v curl     >/dev/null || die "serve curl"
command -v sha256sum >/dev/null || die "serve sha256sum"

TMP=$(mktemp -d) || die "non riesco a creare una cartella temporanea"
trap 'rm -rf "$TMP"' EXIT

while IFS='|' read -r dest url sha; do
    [[ -n "$dest" ]] || continue
    mkdir -p "$TMP/$(dirname "$dest")"
    printf '  %s\n' "$dest"
    curl -fsSL --retry 2 "$url" -o "$TMP/$dest" || die "scaricamento fallito: $url"
    ottenuta=$(sha256sum "$TMP/$dest" | cut -d' ' -f1)
    [[ "$ottenuta" == "$sha" ]] || die "impronta diversa per $dest
  attesa:   $sha
  ottenuta: $ottenuta
  indirizzo: $url
Se l'aggiornamento è voluto, si corregge l'impronta nell'elenco qui sopra dopo
aver controllato il file; altrimenti non si tocca niente."
done <<< "$MANIFEST"

# Il README della cartella è scritto a mano e non sta nell'elenco: si mette da
# parte e si rimette al suo posto, altrimenti un aggiornamento se lo porterebbe via.
if [[ -f "$VENDOR_DIR/README.md" ]]; then
    cp -a "$VENDOR_DIR/README.md" "$TMP/README.md"
fi

rm -rf "$VENDOR_DIR"
mkdir -p "$(dirname "$VENDOR_DIR")"
cp -a "$TMP" "$VENDOR_DIR"
chmod -R a+rX "$VENDOR_DIR"

printf '\napp/assets/vendor/ rigenerata — %s in %d file.\n' \
    "$(du -sh "$VENDOR_DIR" | cut -f1)" \
    "$(find "$VENDOR_DIR" -type f | wc -l)"
echo "Se sono cambiate le versioni, il README della cartella va aggiornato a mano."
