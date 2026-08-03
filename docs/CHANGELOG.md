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

## 0.3.3

Nuovo tipo di sorgente **CLOCK** (`sources.media_type='clock'`): nessun
flusso, nessun file audio/video, nessun Cue — solo un registro di marker con
orario reale, per annotare eventi anche senza una diretta in corso. Avvio e
Ferma restano manuali (come le altre sorgenti) e producono comunque una riga
vera in `recordings`, visibile in `recordings.php` e apribile in
`recording.php` con inizio/fine/durata reali ed export CSV/TXT/JSON dei
marker. Le sorgenti CLOCK sono escluse dagli Orari programmati (avvio solo
manuale) e non compaiono mai come processo `record.sh` in esecuzione.

Nessuna modifica di schema (`media_type` era già testo libero), nessuna riga
di `record.sh` toccata (non viene mai invocato per questo tipo) — fuori fase
come già 0.3.1/0.3.2. Codice registrazione con prefisso **`K`** (es. `K007`),
badge **CLOCK** (ambra). Vedi "Sorgente CLOCK — marker senza flusso" in
[NOTE-TECNICHE.md](NOTE-TECNICHE.md).

## 0.3.2

Due bug in `scripts/retention_cleanup.sh`, entrambi trovati collaudando la
rotazione dei log della 0.3.1, non a vista, ed entrambi già presenti in
produzione.

- **La retention dei cue si fermava dopo ogni cancellazione.** Una variabile
  non `local` (`SRC_ID`) veniva svuotata dentro `delete_recording()` e
  corrompeva il ciclo per-sorgente più esterno: dopo aver cancellato una
  registrazione, ogni query successiva nello stesso giro per la stessa
  sorgente falliva in silenzio con un errore SQL. Fix: tre variabili rese
  `local`.
- **I marker restavano orfani dopo una cancellazione automatica.** Il lato PHP
  attiva `PRAGMA foreign_keys = ON` per ogni connessione, quindi cancellare
  una registrazione dall'interfaccia fa sparire in cascata anche i suoi
  marker. Il bash della retention no: cancellava i file delle clip ma non le
  righe in `markers`, che restavano nel database senza una registrazione a cui
  appartenere. Trovati 3 marker orfani già in produzione, lasciati lì — sono
  innocui e non si scrive nel database di produzione per una pulizia che può
  aspettare.

Vedi [NOTE-TECNICHE.md](NOTE-TECNICHE.md), sezione *Retention automatica*, per
il dettaglio di entrambi. Nessuna modifica al comportamento visibile
dall'interfaccia.

---

## 0.3.1

Non chiude una fase: tre voci dall'elenco *Prima della 1.0*, tutte lo stesso
giorno.

- **Licenza: AGPL-3.0.** Le dipendenze vendorizzate (UIkit, hls.js,
  wavesurfer.js, il font Recursive) restano ciascuna con la propria licenza
  originale, elencate in `app/assets/vendor/LICENSES/`.
- **Rotazione dei log, decisa log per log** — non con una politica unica. I
  numeri reali su un'installazione con una settimana di vita hanno spostato
  l'attenzione da dove sembrava il problema (i quattro log "di servizio",
  poche centinaia di KB in tutto) a dove lo era davvero: `fm-record-{id}.log`,
  mai cancellato, alcuni già a 19 MB.
  - `fm-record-{id}.log` non ruota: resta finché esiste la registrazione, poi
    30 giorni di grazia dalla cancellazione — automatica (`retention_cleanup.sh`)
    o manuale (`api/recordings.php`), tenute allineate apposta.
  - Gli altri cinque li ruota `logrotate`, di sistema, installato da
    `install.sh` a partire da `config/logrotate.fluxus.in`. Trovato collaudando
    e non a vista: un logrotate recente rifiuta di default una cartella di log
    scrivibile dal gruppo se quel gruppo non è `root` — e la cartella dei log di
    Fluxus lo è, apposta, perché gli script ci scrivono senza essere root. Senza
    la direttiva `su`, la rotazione non sarebbe mai partita, in silenzio.
- **Il repository è pubblico.** Prima, la storia è stata riscritta per togliere
  un indirizzo di rete locale finito per errore in `docs/MANUALE.md`: non era
  un segreto, ma la regola del progetto (niente valori di questa macchina nel
  repository) non fa eccezioni per la cronologia.

Trovato collaudando, non incluso in questa versione: un bug preesistente in
`retention_cleanup.sh` (una variabile non `local` che, dopo la cancellazione di
una registrazione, corrompe il ciclo del giro corrente) fa fallire in silenzio
la pulizia dei cue per quella sorgente nello stesso giro. Già presente prima di
questa versione, verificato anche sull'installazione in produzione.

---

## 0.3.0

**Fluxus si installa** ([fase 3](ROADMAP.md) della roadmap).

Fino a ieri questo repository era un sorgente e basta: l'installer ereditato era
fermo a una versione molto precedente e non copiava nemmeno i file
dell'applicazione, per cui ogni installazione era nata a mano. Soprattutto, non
c'era modo di **provare** una modifica se non sulla macchina che registra tutti
i giorni.

Ora ci sono `install.sh` e il comando `fluxus`, e con loro la prima
installazione di collaudo accanto a una in produzione, sulla stessa macchina,
senza che si sfiorino.

- **`install.sh`** installa dipendenze, cartelle, applicazione, script,
  configurazione, servizi, permessi, server web, server RTMP e database, e
  stampa alla fine l'indirizzo a cui collegarsi. Opzioni per percorsi, utente,
  sottopercorso, istanza e porte; senza un terminale non fa domande, così
  funziona anche dentro uno script.
- **Rilanciarlo è il modo di aggiornare**: i valori non ripetuti si rileggono
  dalla configurazione esistente, il database non viene mai toccato — se ne
  occupano l'applicazione e le sue migrazioni — e registrazioni, clip, log e
  segreti restano dove sono.
- **`--dry-run`** mostra ogni singola azione senza compierne nessuna. Con
  un'installazione in servizio a fianco non è un lusso.
- **Le guardie**: non si scrive dentro una radice web o una cartella dati che
  non portino la firma di questa istanza, non si toccano servizi con un prefisso
  altrui (i timer storici `fm-*` sono al sicuro), ci si ferma se c'è una
  registrazione in corso, e il database si apre solo come utente di Fluxus,
  mai come root.
- **Un MediaMTX per istanza**, con porte scelte fra quelle libere e i protocolli
  che Fluxus non usa spenti: il server RTMP non si può condividere, perché i
  percorsi di ingresso sono numerati per id di sorgente e due installazioni si
  sovrapporrebbero.
- **nginx**: i blocchi `location` vanno in uno snippet per istanza, incluso nel
  vhost subito dopo `server {` — l'ordine conta, o il timeout lungo
  dell'estrazione clip non viene applicato. Con copia di sicurezza, `nginx -t` e
  ripristino automatico se la verifica non passa.
- **Il comando `fluxus`** — uno per macchina, che conosce tutte le istanze — per
  `status`, `list`, `config`, `logs`, `update`, `backup`, `restore` e
  `uninstall`. Con più installazioni e nessuna indicata si ferma ed elenca:
  un'istanza indovinata, disinstallando, sarebbe quella sbagliata.
- La disinstallazione conserva i dati (`--purge` per rimuoverli) e li lascia
  riconoscibili, così reinstallando la stessa istanza li si ritrova.

`config/mediamtx.yml` è diventato il modello `config/mediamtx.yml.in`, e si
aggiunge `systemd/mediamtx.service.in`. Nessuna modifica alla registrazione,
all'estrazione delle clip o allo schema del database.

Collaudato sul campo installando `fluxus-dev` accanto all'installazione in
produzione: registrazione da push RTMP, cue in diretta, clip estratta dal timer,
aggiornamento, backup, ripristino, disinstallazione e reinstallazione sopra i
dati conservati. L'installazione in produzione non è stata sfiorata.

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
