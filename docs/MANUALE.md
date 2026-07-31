# Fluxus — Manuale d'uso

Guida per chi usa il sistema, non per chi lo sviluppa. Nessuna conoscenza
tecnica richiesta: si fa tutto dal browser.

Se stai provando Fluxus per la prima volta, salta alla sezione
**[Prova guidata](#prova-guidata-20-minuti)** in fondo: è un percorso completo in
una ventina di minuti.

---

## Indice

- [Cos'è Fluxus](#cosè-fluxus)
- [Accedere](#accedere)
- [La barra in alto](#la-barra-in-alto)
- [Dashboard](#dashboard)
- [1. Creare una sorgente](#1-creare-una-sorgente)
- [2. Verificare che la sorgente sia raggiungibile](#2-verificare-che-la-sorgente-sia-raggiungibile)
- [3. Guardare o ascoltare l'anteprima](#3-guardare-o-ascoltare-lanteprima)
- [4. Registrare subito](#4-registrare-subito)
- [5. Marker e Cue](#5-marker-e-cue)
- [6. Programmare le registrazioni](#6-programmare-le-registrazioni)
- [7. Rivedere e scaricare](#7-rivedere-e-scaricare)
- [8. Impostazioni](#8-impostazioni)
- [Domande frequenti](#domande-frequenti)
- [Prova guidata](#prova-guidata-20-minuti)
- [Segnalare un problema](#segnalare-un-problema)

---

## Cos'è Fluxus

Fluxus registra audio e video da una **sorgente** — una radio in streaming, una
telecamera, un encoder — e ti permette di **marcare i momenti interessanti
mentre stai ascoltando o guardando**. Al posto di riascoltare due ore di
registrazione per ritrovare un passaggio, premi un pulsante nel momento in cui
lo senti e Fluxus ti prepara da solo un file breve con quel pezzo.

Le quattro parole da conoscere:

| | |
|---|---|
| **Sorgente** | da dove arriva l'audio o il video. Si configura una volta e si riusa |
| **Registrazione** | una sessione, dall'inizio alla fine. Ha un codice tipo `A046` (audio) o `V047` (video) |
| **Marker** | un segnalibro. Segna solo l'istante: "qui è successo qualcosa" |
| **Cue** | un segnalibro che genera **anche un file audio/video ritagliato** attorno a quell'istante |

La differenza fra marker e cue è tutta lì: il marker è un appunto, il cue
produce un file da scaricare.

---

## Accedere

Apri il browser su:

```
http://<indirizzo-del-pi>/fluxus-media/
```

Su questa installazione l'indirizzo è **`http://<indirizzo-della-macchina>/fluxus-media/`**.
Funziona da qualunque dispositivo sulla stessa rete: computer, tablet, telefono.

**Non serve una password**, perché l'autenticazione è attualmente disattivata —
il sistema si fida della rete locale. Se viene accesa (Impostazioni → Nodo),
comparirà una pagina di login.

> Fluxus **non è raggiungibile da Internet**, per scelta. Se devi premere marker
> mentre sei fuori casa esiste *Fluxus Remote*, una pulsantiera separata su un
> server esterno: chiedi a chi gestisce il sistema se è configurata.

---

## La barra in alto

È sempre presente, in ogni pagina.

- **Dashboard · Sorgenti · Orari · Registrazioni** — le quattro sezioni.
- **L'ingranaggio** a destra apre le Impostazioni.
- **L'orologio** mostra l'ora del Pi nel fuso configurato. Se non coincide con
  la tua, gli orari programmati seguono *quella* del Pi, non la tua.
- **Le barrette CPU / RAM / Disco** si aggiornano ogni 10 secondi. La parte
  Disco elenca i volumi di archiviazione: fino a due si vedono affiancati, da
  tre in su compare una freccetta che apre l'elenco completo. **Guardala prima
  di registrare a lungo**: il video occupa fra 0,2 e 2 GB per ora.

---

## Dashboard

È la pagina di lavoro. Contiene una **card per ogni sorgente**, con badge
`AUDIO` (blu) o `VIDEO` (viola), l'indirizzo dello stream, l'ultima
registrazione fatta e gli orari programmati.

I tre pulsanti in alto — **Tutti · Audio · Video** — filtrano la vista, utile
quando le sorgenti sono molte.

Su ogni card:

| Comando | Cosa fa |
|---|---|
| **REC** rosso | avvia subito una registrazione |
| **REC** rosso *pulsante* | la sorgente sta già registrando: cliccalo per aprire la registrazione in corso |
| **Marker** / **Cue** | compaiono solo mentre registra |
| icona microfono o telecamera | anteprima live |
| icona fulmine | "Check": verifica se la sorgente risponde |
| icona album | tutte le registrazioni di quella sorgente |

In basso trovi le **ultime registrazioni completate**, audio e video insieme.
La pagina si aggiorna da sola ogni 5 secondi.

---

## 1. Creare una sorgente

**Sorgenti → Nuova sorgente.**

In cima ci sono due campi, **entrambi obbligatori**:

- **Nome** — come la chiami tu. Compare in tutta l'interfaccia.
- **Prefisso file** (max 12 caratteri) — le lettere che aprono il nome di ogni
  file. Con prefisso `KR` i file diventano `KR_2026-07-30_09-30.mp3`. Tienilo
  breve e riconoscibile: è quello che vedrai nei download fra sei mesi.

Poi la scelta che decide tutto il resto del modale:

### Modalità sorgente

- **Preleva stream esistente (pull)** — il caso normale. Fluxus si collega lui a
  qualcosa che esiste già: una radio in streaming, una telecamera IP, un server
  RTMP. Devi conoscerne l'indirizzo.
- **Ricevi stream in ingresso (push)** — il contrario: è un encoder o una
  telecamera esterna a collegarsi *a questo Pi*. Serve quando l'apparecchio non
  espone un indirizzo a cui collegarsi, ma sa "trasmettere verso".

### Se hai scelto pull

1. **Tipo media**: Audio o Video. Decide se il file finale sarà MP3 o MP4.
2. **Protocollo**: `http` per le radio in streaming, `rtmp`/`rtsp` per le
   telecamere e i server di streaming, `srt` per i collegamenti professionali,
   `v4l2` per una webcam attaccata al Pi.
3. **URL stream**: l'indirizzo completo, incollato dalla fonte. Per `v4l2` al
   suo posto compare **Device** (es. `/dev/video0`).

**Se è audio**, imposta la **Qualità MP3** (scala 0-9: 0 è la migliore, 2 è un
buon compromesso).

**Se è video**, imposta la **Qualità registrazione** — è la scelta che decide
quanto spazio consumi:

| Profilo | Quando usarlo | Spazio |
|---|---|---|
| **copy** | non ritocca niente, registra lo stream così com'è. Qualità identica alla fonte, ma il peso lo decide la fonte | può essere ~2 GB/h |
| **hd** | massima qualità con peso controllato | ~0,9 GB/h |
| **alta** | consigliato per l'archivio | ~0,6 GB/h |
| **media** | consultazione, verifiche | ~0,26 GB/h |
| **bassa** | solo ascolto/controllo | ~0,16 GB/h |

Il box sotto il menù ti mostra la stima aggiornata mentre scegli.

> **La risoluzione non cambia mai.** Nessun profilo rimpicciolisce l'immagine:
> se la sorgente è in Full HD, il file è in Full HD in tutti i profili. Cambia
> solo quanti dati vengono usati per descriverla. I campi *Risoluzione* e *FPS*
> compaiono solo per le webcam collegate al Pi (`v4l2`), dove sono parametri
> della telecamera.
>
> **Un solo video in Full HD occupa quasi tutto il Pi** quando non è in `copy`.
> Non contare di ricodificare tre o quattro sorgenti Full HD insieme.

### Se hai scelto push

Tipo media e protocollo vengono impostati da Fluxus. **Salva prima**: l'indirizzo
su cui far trasmettere l'encoder contiene il numero della sorgente, che esiste
solo dopo il salvataggio. Fluxus riapre da solo il modale mostrandoti
l'indirizzo vero (`rtmp://<indirizzo-della-macchina>:1935/17`) con un pulsante **Copia**.
Quell'indirizzo si vede anche nell'elenco sorgenti.

### Cancellazione automatica (retention)

In fondo al modale, quattro limiti che tengono lo spazio sotto controllo da soli.
**`0` significa nessun limite.**

| Campo | Significato | Predefinito |
|---|---|---|
| Max registrazioni | tiene le N più recenti | 30 |
| Max giorni registr. | cancella quelle più vecchie di N giorni | 45 |
| Max clip per marker | tiene le N clip più recenti | 100 |
| Max giorni clip | cancella le clip più vecchie di N giorni | 20 |

La pulizia gira ogni mezz'ora. **Cancella davvero i file**: se una registrazione
ti serve, scaricala prima che i limiti la raggiungano.

### Volume di archiviazione

Scegli su quale disco salvare, o lascia *"Predefinito per tipo media"* per usare
quello impostato nelle Impostazioni.

### Avanzate

Un blocco chiuso con opzioni ffmpeg aggiuntive. **Lascialo stare** se non sai
cosa metterci: un valore sbagliato fa fallire le registrazioni.

---

## 2. Verificare che la sorgente sia raggiungibile

**L'icona a fulmine sulla card, in Dashboard.** È il primo comando da usare
quando qualcosa non va, prima di dare la colpa a Fluxus.

Il risultato compare sotto la card:

- **Verde** con il riassunto dei formati
  (`audio: aac 44kHz · video: h264 1920x1080`) → la sorgente c'è e trasmette.
- **Rosso** con l'errore → non risponde, indirizzo sbagliato, o non sta
  trasmettendo nessuno.

Per le sorgenti in **push**, il Check non prova un indirizzo — non ne esiste uno
— ma verifica se qualcuno sta effettivamente trasmettendo verso il Pi.

---

## 3. Guardare o ascoltare l'anteprima

**L'icona microfono/telecamera sulla card**, oppure il pulsante **Anteprima**
dentro una registrazione in corso.

Serve a rispondere a "sto registrando la cosa giusta?". Si apre una finestra con
il player: qualche secondo di attesa (c'è un contatore), poi parte.

- Funziona per audio **e** video.
- **Non disturba la registrazione in corso**: è un collegamento separato.
- **Chiudi la finestra quando hai finito.** L'anteprima consuma banda verso la
  sorgente e si spegne da sola solo dopo 15 minuti.
- Un ritardo di qualche secondo rispetto al vero è normale: è come funziona
  questo tipo di streaming.

**Se dopo 20 secondi dice che non riesce a collegarsi**, quasi sempre la
sorgente è spenta: nessuno sta trasmettendo. Usa il **Check** per confermarlo.

---

## 4. Registrare subito

**Il pulsante REC rosso** sulla card in Dashboard. Parte immediatamente.

Cosa succede: la card passa a REC pulsante, appaiono **Marker** e **Cue**, e la
registrazione compare in Registrazioni con stato *in corso*.

> **La registrazione manuale dura 1 ora** e poi si chiude da sola. Se ti serve
> più a lungo, usa un orario programmato con la durata che vuoi (fino a 4 ore).

### Fermarla

Clicca REC per aprire la registrazione, poi **Ferma**. Ci vogliono un paio di
secondi: Fluxus chiude il file in modo pulito, altrimenti sarebbe illeggibile.

**Non chiudere il browser aspettandoti che si fermi**: la registrazione va
avanti sul Pi, per progetto. È quello che le permette di continuare mentre tu
spegni il computer.

---

## 5. Marker e Cue

Il cuore di Fluxus. Funzionano **solo mentre la registrazione è in corso**, dalla
Dashboard o dalla pagina della registrazione.

| | |
|---|---|
| **Marker** — tasto `M` | segna l'istante. Nessun file |
| **Cue** — tasto `C` | segna l'istante **e** prepara un file ritagliato attorno a lui |

**I tasti `M` e `C` funzionano da tastiera**, senza cliccare niente: è il modo
per non perdere il momento mentre ascolti.

### Il modale

Premendo M o C si apre una finestrella con un campo **etichetta** e una **barra
che si accorcia**. Hai tre possibilità:

1. **Scrivi l'etichetta e premi Invio** → salvato subito.
2. **Non fai niente** → allo scadere della barra (5 secondi in questa
   installazione) viene salvato comunque, con un nome automatico
   (`marker_12`, `cue_13`).
3. **Annulli** → niente marker.

Il punto importante: **l'istante registrato è quello in cui hai premuto il
tasto**, non quello in cui salvi. Prendi tutto il tempo che vuoi per scrivere
l'etichetta senza rovinare la precisione.

### Quando arriva la clip del cue

Non subito, ed è normale. Il cue ritaglia un pezzo che comprende **anche i
secondi dopo il tuo click** — quei secondi devono ancora essere registrati.

In questa installazione la clip contiene:

```
   60 secondi prima        il tuo click        90 secondi dopo
 ├───────────────────────────────┼─────────────────────────────┤
                     clip di 2 minuti e 30
```

Quindi la clip compare **circa un minuto e mezzo dopo** il click, più fino a
30 secondi di attesa del controllo periodico. Nella tabella dei marker lo stato
passa da *in attesa* a *pronta*, e appaiono play e download.

Pre-roll e post-roll si cambiano in Impostazioni → Marker & Cue.

### Marker premuti da fuori

Se *Fluxus Remote* è configurato, i marker premuti da fuori casa arrivano qui
con una spunta nella colonna **Remote**, e con l'istante esatto del click sulla
pulsantiera remota — non quello in cui il Pi li ha ricevuti.

---

## 6. Programmare le registrazioni

**Orari → Nuovo orario.** È il modo di registrare ogni giorno senza esserci.

1. **Sorgente** — quale registrare.
2. **Etichetta** — a cosa serve, per te (es. "Rassegna del mattino").
3. **OnCalendar** — quando. È il formato di systemd:

| Espressione | Significato |
|---|---|
| `Mon..Fri 07:02` | dal lunedì al venerdì alle 7:02 |
| `daily` | ogni giorno a mezzanotte |
| `Mon,Wed,Fri 21:00` | lunedì, mercoledì e venerdì alle 21 |
| `*-*-01 12:00` | il primo di ogni mese a mezzogiorno |
| `Sat,Sun 10:30` | nel fine settimana alle 10:30 |

**Mentre scrivi, Fluxus valida l'espressione** e ti mostra sotto il prossimo
avvio previsto. Se resta rosso, l'espressione non è valida: correggila prima di
salvare, altrimenti quell'orario non scatterà mai.

4. **Durata slot** — quanto dura ogni registrazione: cursore da 5 minuti a
   4 ore.
5. **Segmentazione file** — se attiva, spezza la registrazione in file più
   piccoli (es. 4 file da 30 minuti anziché uno da 2 ore). Utile per file lunghi:
   più comodi da scaricare e da maneggiare. Marker e cue continuano a funzionare
   normalmente, Fluxus ricuce i pezzi da solo quando serve.
6. **Stato** — *Inattivo* sospende l'orario senza cancellarlo.

Gli orari programmati compaiono anche in fondo alla card della sorgente, in
Dashboard.

### Cosa aspettarsi da una registrazione programmata

- Parte **anche se il browser è chiuso** e nessuno è presente.
- **Se lo stream cade a metà, riprende da sola** e va avanti fino all'ora di
  fine prevista. Trovi più file (`_000`, `_001`…) invece di uno: è normale, sono
  i tronconi. Le interruzioni sono annotate nella registrazione.
- **Se lo stream si congela** (resta collegato ma non manda più niente), Fluxus
  se ne accorge dopo un minuto e riparte.
- **La durata mostrata è quella di quanto c'è dentro**, non quella dello slot.
  Se lo stream è partito con 5 minuti di ritardo, uno slot di 2 ore mostra
  1h55m. Non è un errore: è la misura giusta.

---

## 7. Rivedere e scaricare

### L'elenco

**Registrazioni.** Una riga per registrazione, con codice (`A046`, `V047`),
sorgente, inizio, nome del file, numero di clip pronte, numero di marker,
durata, dimensione e stato.

- **Clicca una riga** per aprirla.
- **Il pallino a sinistra** seleziona. Selezionandone una o più, appare in basso
  una barra con **Elimina selezionate**: la conferma ti elenca i codici, così
  non cancelli per sbaglio le righe sbagliate. Le registrazioni **in corso** non
  sono selezionabili.
- Un'**icona di avviso** al posto della dimensione significa che i file sono su
  un disco esterno attualmente scollegato.

### La pagina di una registrazione

- **Il player** per ascoltare o guardare direttamente nel browser. Se la
  registrazione è segmentata, un player per segmento, con **durata e peso** di
  ciascuno a destra.
- **Per il video**, i file sono in tabella (data, orario, durata, risoluzione,
  fps, dimensione). Clicca una riga — o la sua freccetta di play — e **il video
  si apre lì sotto**, restando attaccato alla riga del file che stai guardando.
  Un altro click sulla stessa riga lo chiude; aprendone un altro, il precedente
  si chiude da solo. Il video comincia a scaricarsi solo quando lo apri, e se
  torni su un segmento già visto riprende dal punto in cui l'avevi lasciato.
- **Aggiungere un marker o un cue dopo**, dalla barra in fondo alla lista dei
  file: scegli se è un marker (predefinito) o un cue, scrivi l'istante
  (`1:23:45`, `23:45` o i secondi) e l'etichetta, e premi Aggiungi. Serve quando
  un marker è sfuggito in diretta: la registrazione è integrale, quindi
  riascoltandola qui puoi sempre recuperarlo, e per i cue la clip viene
  ritagliata da sola entro un paio di minuti.
- **La tabella dei marker**: istante (relativo e assoluto), etichetta, tipo,
  origine. Per i cue pronti, play e download.
- **Il box Download**, con tre formati dell'elenco marker:

| | |
|---|---|
| **CSV** | per Excel o Numbers |
| **TXT** | leggibile così com'è, per prendere appunti |
| **JSON** | strutturato, per chi deve elaborarlo con un programma |

- **Elimina registrazione**, nella "Zona pericolosa" **in fondo alla pagina**,
  dopo l'elenco dei marker. Cancella i file e la riga. Non si annulla.
- Mentre registra, qui trovi **Marker**, **Cue**, **Ferma** e **Anteprima**.

> Gli elenchi marker restano leggibili **anche con il disco esterno
> scollegato**: sono informazioni, non filmati, e stanno sempre sul Pi. In quel
> caso la pagina si apre normalmente e ti avvisa in cima con il nome del disco
> che manca, al posto dei player.

---

## 8. Impostazioni

L'ingranaggio in alto a destra. Tre riquadri.

### Marker & Cue

- **Pre-roll** (0-120 s) — quanto prendere *prima* del click.
- **Post-roll** (1-240 s) — quanto prendere *dopo*.
- **Salvataggio automatico del modale** (3-60 s) — quanto dura la barra.

Ogni valore ha cursore e casella numerica: usa quella che preferisci.

> Pre-roll e post-roll valgono **solo per i cue creati dopo il salvataggio**. Le
> clip già pronte non vengono rifatte.
>
> Un pre-roll generoso è quasi sempre la scelta giusta: quando ci si accorge che
> "questo va salvato", la frase è già cominciata.

### Archiviazione

Una **card per ogni disco** collegato al Pi, con nome, dispositivo, tipo e spazio
libero. La microSD interna è sempre presente.

Al centro di ogni card, due **caselle tratteggiate**: `AUDIO` e `VIDEO`. Sono le
destinazioni.

**Per spostare una destinazione** hai due modi:

1. **Trascina il tag** `AUDIO` o `VIDEO` nella casella del disco che vuoi.
2. **Clicca una casella vuota**: il tag di quel tipo arriva lì da dove si
   trovava. Più rapido, e funziona bene da telefono.

Il cerchio a sinistra di ogni card cambia colore secondo cosa riceve: blu per
audio, viola per video, metà e metà se entrambi, grigio se non riceve niente.

**Trascinando la maniglia** riordini le card, e quell'ordine vale anche per la
barra di stato in alto. **Ogni movimento si salva da solo**: non c'è nessun
pulsante Salva, ed è normale.

**Il pulsante Abilita** compare sui dischi che Fluxus non può ancora usare
(tipicamente quelli montati dal desktop, dove non ha i permessi). Preparalo con
un click: Fluxus lo rimonta come si deve e crea le cartelle che gli servono.

> **I dati sul disco non vengono toccati.** La conferma te lo dice sempre in
> chiaro, indicando quanti GB ci sono già. Fluxus **non formatta mai**. Se un
> disco richiedesse la formattazione per essere usato, Fluxus si limita ad
> avvisarti e non fa niente: va fatto a mano, dopo aver messo al sicuro il
> contenuto.
>
> Quando un disco esterno viene scollegato, le registrazioni programmate **non
> si perdono**: finiscono sulla microSD interna, con una nota che spiega perché.

### Nodo

- **Nome nodo** — come si chiama questo Fluxus, utile se ce n'è più di uno.
- **Timezone** — il fuso degli orari (`Europe/Rome`). Cambiarlo cambia quando
  scattano le registrazioni programmate.
- **Web base path** — solo per chi sposta l'installazione.
- **Richiedi autenticazione** + **Nuova password** — accende il login. Da fare
  se il Pi sta su una rete condivisa.

---

## Domande frequenti

**La clip del cue non c'è.**
Aspetta. Compare circa un minuto e mezzo dopo il click (post-roll), più fino a
30 secondi del controllo periodico. Nella tabella dei marker lo stato dice *in
attesa*. Se dopo qualche minuto è *fallita*, segnalalo con il codice della
registrazione.

**L'anteprima gira e non parte.**
Nove volte su dieci la sorgente è spenta. Premi il **Check** (fulmine): se è
rosso, non è colpa di Fluxus.

**La durata non corrisponde allo slot.**
È voluto: viene mostrata la durata di quanto c'è nel file. Uno stream partito in
ritardo o caduto a metà produce meno contenuto dei minuti trascorsi. Le
interruzioni sono annotate nella registrazione.

**Trovo più file invece di uno.**
Due possibilità: la segmentazione è attiva nell'orario, oppure lo stream è caduto
e Fluxus ha ripreso. In entrambi i casi i file sono tronconi consecutivi, e
marker e cue continuano a funzionare correttamente.

**Ho premuto Ferma e non si ferma subito.**
Sono necessari un paio di secondi per chiudere il file in modo leggibile. Se
dopo 10 secondi è ancora "in corso", segnalalo.

**Il video è grosso.**
Probabilmente la sorgente è su `copy`, che registra lo stream tale e quale
(~2 GB/h). Passa a `alta` o `media` nelle proprietà della sorgente: la
risoluzione non cambia.

**Ho cancellato una registrazione per sbaglio.**
Non è recuperabile. Non c'è cestino.

**Le mie registrazioni sono sparite.**
Controlla i limiti di cancellazione automatica nelle proprietà della sorgente:
per impostazione predefinita restano le 30 più recenti e nulla oltre 45 giorni.
Metti `0` per togliere un limite.

**Posso usarlo dal telefono?**
Sì, l'interfaccia si adatta. Marker e Cue funzionano al tocco (i tasti da
tastiera ovviamente no).

**Possiamo usarlo in due contemporaneamente?**
Sì. Attenzione solo a non premere marker in doppio senza accordarvi: finiscono
tutti nell'elenco.

**Dov'è la funzione per ritagliare a mano un cue?**
Momentaneamente disattivata, per concentrare il lavoro altrove. I download delle
clip già pronte funzionano normalmente.

---

## Prova guidata (20 minuti)

Percorso per collaudare tutto. Serve una sorgente che stia trasmettendo davvero
— la più facile è una radio in streaming HTTP.

| | Passo | Cosa deve succedere |
|---|---|---|
| 1 | Apri `http://<indirizzo-della-macchina>/fluxus-media/` | la Dashboard si carica, la barra in alto mostra CPU/RAM/Disco |
| 2 | Sorgenti → Nuova, tipo **Audio**, protocollo **http**, incolla l'URL di una radio, salva | la sorgente appare in elenco con badge `AUDIO` |
| 3 | Dashboard → **fulmine** sulla card | riquadro **verde** con i formati |
| 4 | **icona microfono** (anteprima) | il player parte entro qualche secondo. Chiudi la finestra |
| 5 | **REC** | la card passa a REC pulsante, appaiono Marker e Cue |
| 6 | Aspetta ~1 minuto, poi premi **`M`**, scrivi "prova marker", Invio | il marker appare nella registrazione |
| 7 | Premi **`C`**, lascia scadere la barra senza scrivere | salvato come `cue_<numero>`, stato *in attesa* |
| 8 | Aspetta ~2 minuti | il cue diventa **pronta**: play e download attivi |
| 9 | Scarica la clip e ascoltala | dura 2 min 30 s e contiene il momento del click, con circa un minuto prima |
| 10 | Apri la registrazione → **Ferma** | si chiude in un paio di secondi, stato *completata* |
| 11 | Box Download → **CSV** | il file si apre in Excel/Numbers con i tuoi due marker |
| 12 | Registrazioni → **pallino** sulla riga → **Elimina selezionate** | la conferma elenca il codice (es. `A048`); confermando, la riga sparisce |

Se vuoi provare anche il programmato: crea un orario con OnCalendar a **due
minuti da adesso** (es. `*-*-* 15:42`), durata 5 minuti, e verifica che parta da
solo senza toccare niente. Poi mettilo *Inattivo*, o si ripeterà ogni giorno a
quell'ora.

Per il video, ripeti dal passo 2 scegliendo **Video** e qualità **media**: pesa
poco e si scarica in fretta durante una prova.

---

## Segnalare un problema

Quello che serve per capire cosa è successo, in ordine di utilità:

1. **Il codice della registrazione** (`A046`, `V047`) o il nome della sorgente.
2. **L'ora**, almeno al minuto.
3. **Cosa ti aspettavi e cosa hai visto.**
4. **L'esito del Check** (fulmine) su quella sorgente.
5. Un messaggio d'errore, se compare: copialo o fai uno screenshot.

Utile sapere anche se il problema **si ripete** o è capitato una volta sola, e
se riguarda una sola sorgente o tutte.

> La cosa più utile che puoi fare: **prova il Check prima di segnalare**. Molte
> segnalazioni si risolvono lì, ed è la differenza fra "Fluxus non funziona" e
> "la sorgente non stava trasmettendo".
