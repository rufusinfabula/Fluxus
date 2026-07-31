# Changelog

La numerazione **riparte da zero con questo repository**. Le versioni `2.x`
elencate più in basso sono la cronologia interna dell'installazione da cui il
progetto proviene: nessuno le ha mai installate come pacchetto, e sono
conservate qui perché spiegano *perché* il codice è fatto così. Le decisioni
tecniche che ne derivano stanno in [NOTE-TECNICHE.md](NOTE-TECNICHE.md).

Convenzione, finché si resta sotto l'1.0: **`0.X.0` per un passo importante**
(tipicamente una fase della [roadmap](ROADMAP.md) conclusa), **`0.1.X` per le
correzioni** lungo la strada.

---

## 0.1.0

Primo commit del repository. Il codice dell'applicazione, degli script di
registrazione, dei servizi e della configurazione, estratto dall'installazione
esistente e ripulito da tutto ciò che apparteneva a quella macchina: indirizzi,
etichette dei dischi, chiavi, database.

Non è ancora installabile: è il punto di partenza da cui costruire l'installer.

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
