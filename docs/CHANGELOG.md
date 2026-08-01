# Changelog

La numerazione **riparte da zero con questo repository**. Le versioni `2.x`
elencate più in basso sono la cronologia interna dell'installazione da cui il
progetto proviene: nessuno le ha mai installate come pacchetto, e sono
conservate qui perché spiegano *perché* il codice è fatto così. Le decisioni
tecniche che ne derivano stanno in [NOTE-TECNICHE.md](NOTE-TECNICHE.md).

Convenzione, finché si resta sotto l'1.0: **il numero di versione è il numero
della fase**. La fase 1 della [roadmap](ROADMAP.md) chiude sulla `0.1.0`, la
fase 2 sulla `0.2.0`, e così via fino alla fase 6 e alla `1.0.0`. Le correzioni
lungo la strada prendono la terza cifra: `0.2.1`, `0.2.2`.

La `0.0.1` sta fuori dal conteggio: è l'estrazione del sorgente
dall'installazione unica, il lavoro che ha reso possibile la roadmap ma che
nessuna fase copre.

---

## 0.2.0

**L'interfaccia funziona senza Internet** ([fase 2](ROADMAP.md) della roadmap).

Prima, aprire una pagina di Fluxus voleva dire chiedere sei file a tre CDN
diverse: il foglio di stile e il JavaScript di UIkit, le sue icone, Font
Awesome, il font della firma, il player dell'anteprima. Su una macchina senza
connessione arrivava una pagina senza stile e senza icone — ed è esattamente la
condizione in cui si trova un Raspberry Pi appena portato in un posto nuovo,
cioè quando servirà la pagina di rete della fase 4.

Ora quei file li serve Fluxus, da `app/assets/vendor/`. Sono versionati nel
repository, non scaricati al momento dell'installazione: installare non deve
richiedere che una CDN sia raggiungibile, e l'immagine SD della fase 6 deve
poter funzionare appena accesa.

Nel dettaglio:

- UIkit 3.21.6 (stile, JavaScript e icone), hls.js 1.6.16 e il font *Recursive*
  serviti in locale; **wavesurfer.js 7.12.11** incluso anche se la pagina che lo
  usa è in quarantena, così «nessuna dipendenza esterna» resta una proprietà che
  si verifica con un grep;
- **Font Awesome eliminato**: serviva per due icone, quella del disco interno e
  quella del disco esterno, ed erano 106 KB fra foglio di stile e font. Ora sono
  disegnate in linea da `fmVolumeIcon()`, un solo punto per la pagina
  Impostazioni e per la barra dei volumi;
- di hls.js si usa la build `light`: l'anteprima è un flusso HLS a variante
  singola prodotto in casa, e le tracce audio alternative, i sottotitoli e il
  DRM che la build completa porta con sé non servono a nulla — 180 KB in meno;
- del font *Recursive* solo il sottoinsieme latino al peso 700, 23 KB: serve per
  la firma nel piè di pagina;
- ogni file porta la versione nel nome e nginx li dichiara immutabili: la cache
  del browser non può servire il file vecchio dopo un aggiornamento;
- `packaging/vendor-assets.sh` tiene l'elenco di versioni, indirizzi e impronte
  `sha256`, e rifà la cartella quando si aggiorna qualcosa.

In tutto circa 950 KB, serviti dalla rete locale una volta sola.

Nessuna modifica alla registrazione, all'estrazione delle clip o allo schema del
database.

---

## 0.1.0

**Un solo punto di verità per percorsi e nomi** ([fase 1](ROADMAP.md) della
roadmap).

Prima, ogni percorso era scritto nel codice e ripetuto in più punti: la cartella
dati compariva nell'applicazione e poi di nuovo in ognuno dei sei script,
l'utente di sistema e l'indirizzo del server RTMP in una decina di file fra
servizi, regole del server web e permessi. Ora tutto arriva da **un file di
configurazione per installazione**, letto sia dall'applicazione sia dagli
script.

Il file dichiara il **nome dell'istanza**, e da quello si ricava tutto il resto:
cartelle, sottopercorso web, nomi dei servizi, cartella creata sui dischi
esterni. È ciò che permette di installare Fluxus **due volte sulla stessa
macchina** — una in produzione e una di collaudo — senza che si tocchino, e
quindi di collaudare il pacchetto senza rischiare l'installazione che registra
tutti i giorni.

Nel dettaglio:

- configurazione dell'istanza in `/etc/fluxus/<istanza>.conf`, con le chiavi e i
  loro valori derivati documentati in
  [config/fluxus.conf.example](../config/fluxus.conf.example);
- gli script trovano la propria configurazione **partendo da dove si trovano**:
  lanciati a mano, da un timer o dall'applicazione, finiscono sempre
  sull'istanza a cui appartengono;
- servizi systemd, regole del server web e permessi diventano **modelli** con
  segnaposto, pronti per l'installer della fase 3;
- la chiave del relay esterno passa in un file separato con permessi più
  stretti, anch'esso per istanza;
- il numero di **versione** è ora uno solo: l'interfaccia mostra quello del file
  `VERSION`, non più una costante scritta a mano nel codice;
- tolto dalle Impostazioni il campo *Web base path*, che salvava un valore che
  nessuno leggeva; al suo posto la pagina mostra istanza, sottopercorso e file
  di configurazione in uso.

Nessuna modifica al comportamento della registrazione, all'estrazione delle clip
o allo schema del database. Con istanza `fluxus-media` ogni percorso coincide
con quelli di prima.

---

## 0.0.1

Primo commit del repository, **fuori dal conteggio delle fasi**. Il codice
dell'applicazione, degli script di registrazione, dei servizi e della
configurazione, estratto dall'installazione esistente e ripulito da tutto ciò
che apparteneva a quella macchina: indirizzi, etichette dei dischi, chiavi,
database.

Non è ancora installabile: è il punto di partenza da cui costruire l'installer.

> Questa voce e la successiva sono state **rinumerate a posteriori**, quando si
> è deciso che numero di fase e numero di versione dovessero coincidere. Prima
> l'estrazione era la `0.1.0` e ogni fase chiudeva sulla versione successiva al
> proprio numero, il che rendeva impossibile capire a che punto si fosse
> guardando un numero di versione da solo. Nessuna versione era stata
> etichettata né distribuita, quindi non c'era niente da rompere.

---

# Cronologia interna precedente

### 2.5.2
Anteprima dei segmenti video **in linea** nella riga sotto quella cliccata,
invece che in una finestra che copriva proprio il nome del file. Durata reale
di ogni segmento audio in elenco. Barra dei marker a posteriori compattata in
fondo alla scheda dei file. Zona pericolosa spostata in fondo alla pagina.
Piè di pagina con versione e firma.

### 2.5.1
Il percorso di ingresso delle sorgenti push **non si assume più ma si chiede al
server RTMP**: gli encoder con i campi "indirizzo" e "stream" separati li
concatenano sempre, e pubblicavano dove Fluxus non guardava.

Fine delle perdite silenziose di marker: la finestra non si chiude più prima di
sapere l'esito, non esiste più alcun errore ingoiato, le API rispondono con un
codice di errore invece che con una pagina di login, la sessione dura sei ore in
un'area propria, e i marker si possono inserire **a posteriori** indicando
l'istante.

### 2.5.0
**Archiviazione su più volumi**: registrazioni e clip possono andare su un
disco esterno, con destinazioni distinte per audio e video e possibilità di
sovrascriverle per singola sorgente. Elenco dei dischi trascinabile, disco USB
abilitabile dal browser.

### 2.4.4
Nuovo profilo di qualità video `hd` e forbice fra i profili allargata: prima
fra il più alto e il più basso c'era un fattore 2,5 di peso con qualità quasi
indistinguibili, ora il fattore è circa 5,8.

### 2.4.3
`duration_seconds` è la durata del **contenuto** e non il tempo trascorso, e le
clip vengono tagliate nel punto giusto anche quando la registrazione ha dei
buchi (stream partito in ritardo, riprese dopo una caduta).

### 2.4.2
**Watchdog di stallo**: uno stream che si congela senza chiudere la connessione
lasciava il processo di registrazione appeso a tempo indefinito, oltre la fine
dello slot e sordo al comando di stop. Corretta anche la perdita dei segmenti
non chiusi, che erano irrecuperabili.

### 2.4.1
Anteprima live riscritta su un relay locale: quella basata sul server RTMP non
ha mai funzionato su nessuna sorgente reale. Estesa anche alle sorgenti audio e
disponibile durante la registrazione. Pulsante di verifica raggiungibilità di
una sorgente.

### 2.4.0
**Ripresa automatica** della registrazione dopo una caduta dello stream, e fine
delle scritture perse per contesa sul database.

### 2.3.0
**Profili di qualità video per sorgente**, che rendono finalmente controllabile
il peso delle registrazioni: prima erano sempre in copia diretta e il peso lo
decideva interamente l'encoder a monte.

### 2.2.1
Nomi predefiniti dei marker lato server, barra di avanzamento
dell'autosalvataggio, durata dell'autosalvataggio configurabile, fix del
segmento finale vuoto quando la durata dello slot è un multiplo esatto di
quella dei segmenti.

### 2.2.0
Margini prima e dopo il click configurabili dall'interfaccia, prima erano
scritti nel codice.

### 2.1.0
**Marker da fuori LAN** tramite un relay esterno opzionale: la macchina non
riceve mai connessioni in ingresso, è lei a chiedere.

### 2.0
Supporto **video** oltre all'audio, mantenendo architettura, interfaccia e
logica dei marker. Ogni sorgente ha un tipo che determina formato di output,
parametri di codifica e funzioni disponibili.

### 1.0
Registratore **solo audio** con palinsesto, marker e ritaglio manuale delle
clip.
