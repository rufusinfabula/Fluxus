# Note tecniche di Fluxus

Questo documento è la memoria tecnica del progetto: come è fatto, **perché** è
fatto così, e soprattutto quali strade sono già state provate e non funzionano.
La sezione più importante sono i **vincoli critici** in fondo: ognuno nasce da
un guasto reale, spesso costato ore di diagnosi o registrazioni perse. Prima di
cambiare qualcosa in quelle aree, leggere il vincolo corrispondente.

⚠️ **Le versioni citate qui (2.x) sono la cronologia interna** dell'installazione
da cui il progetto proviene, dove è maturato tutto ciò che segue. Il pacchetto
distribuibile riparte da `0.0.1` verso `1.0.0`, **una versione per ogni fase
della roadmap** — vedi `CHANGELOG.md`. I numeri 2.x restano nel testo perché
servono a datare le decisioni fra loro.

⚠️ **`settings.schema_version` non è la versione del prodotto** e non va
allineata ad essa. È il contatore su cui `db_init.php` decide quali migrazioni
applicare: riportarla indietro "per coerenza" farebbe rigiocare migrazioni già
applicate — `ALTER TABLE` su colonne esistenti — e il database non si aprirebbe
più. Vedi "Inizializzazione DB e migrazioni".

## Cos'è

**Fluxus** è un sistema unificato di registrazione audio e video per Raspberry
Pi. Nasce come registratore audio e viene poi esteso al video mantenendo la
stessa architettura, la stessa UI e la stessa logica Marker/Cue — ma le sorgenti
possono essere audio O video.

Regola fondamentale: ogni sorgente ha un `media_type` (audio|video) che
determina il formato di output, i parametri ffmpeg, e le funzionalità UI
disponibili (EDIT è solo per i cue audio).

## Stack

- Web: nginx (vhost di default, porta 80/443) sul sottopercorso dell'istanza, PHP 8.2-FPM, UIkit 3.21.6 servito da Fluxus
- DB: SQLite — `<cartella dati>/db/fluxus_media.db`
- Recording: script bash + ffmpeg
- RTMP/RTSP: MediaMTX (systemd, porta 1935 RTMP, 8554 RTSP)
- Scheduling: unit systemd generate dinamicamente
- User di sistema: `FLUXUS_USER`, in pratica www-data (nessun utente dedicato — pool PHP-FPM condivisa con gli altri siti dell'host)

## Percorsi

⚠️ **Dalla 0.1.0 nessuno di questi percorsi è scritto nel codice**: cartella
dati, radice web e sottopercorso arrivano dalla configurazione dell'istanza —
vedi "Configurazione dell'istanza". Qui sotto sono nella forma che assumono per
l'istanza `fluxus-media`, cioè quella da cui il progetto proviene, che è anche
ciò che si ottiene per derivazione dal solo nome dell'istanza.

⚠️ **I percorsi qui sotto sono quelli del volume interno.** Dalla v2.5.0 le
registrazioni e i cue possono stare su un disco esterno, che replica la stessa
struttura sotto la propria radice — vedi "Archiviazione su più volumi".

```
/var/lib/fluxus-media/
  fluxus.conf                                         → /etc/fluxus/fluxus-media.conf
  db/fluxus_media.db
  recordings/{source_id}/{filename_base}.mp3          # audio singolo
  recordings/{source_id}/{filename_base}.mp4          # video singolo
  recordings/{source_id}/{filename_base}_{NNN}.mp3    # audio segmentato
  recordings/{source_id}/{filename_base}_{NNN}.mp4    # video segmentato
  clips/{source_id}/{filename_base}_m{id}.mp3         # cue audio
  clips/{source_id}/{filename_base}_m{id}.mp4         # cue video
  clips/{source_id}/{filename_base}_m{id}_trim.mp3    # trim audio (da EDIT, in quarantena)
  tmp/
  tmp/preview/{source_id}/index.m3u8, seg%05d.ts, pid   # anteprima live effimera
  logs/fm-record-{recording_id}.log, fm-extract-clips.log,
       fm-remote-sync.log, fm-retention.log, fm-preview-{source_id}.log,
       fm-schedule.log
  scripts/
    fluxus-env.sh                                       ← caricatore della configurazione
    record.sh, run_schedule.sh, extract_clips.sh, stop_recording.sh,
    retention_cleanup.sh, remote_sync.php, preview.sh

/var/www/fluxus-media/
  index.php              → redirect a dashboard.php
  dashboard.php
  recording.php
  recordings.php
  sources.php
  schedules.php
  edit.php               ← IN QUARANTENA (vedi sezione dedicata) — pagina disattivata
  edit-trim.php          ← IN QUARANTENA (vedi sezione dedicata) — redirect a edit.php
  settings.php
  login.php / logout.php
  VERSION                ← unica fonte del numero di versione
  includes/
    conf.php               ← trova e legge la configurazione dell'istanza
    instance.php           ← scritto dall'installer, non versionato: il nome dell'istanza
    config.php, db.php, db_init.php, auth.php, helpers.php,
    nav.php, head.php, head_dark.php, foot.php,
    markers_table.php, marker_modal.php, preview_modal.php,
    session_modal.php                     ← avviso bloccante sessione scaduta + fmApiPost
  api/
    status.php, start.php, stop.php, recordings.php
    marker.php, trim.php, download.php, system.php, test_source.php  ← trim.php IN QUARANTENA
    preview_start.php, preview_stop.php   ← anteprima live video via MediaMTX/HLS
    validate_oncalendar.php               ← valida espressioni OnCalendar (systemd-analyze)
    volume_order.php                      ← ordine dei volumi (drag&drop in settings)
    ping.php                              ← keep-alive della sessione (401 se scaduta)
  db/schema.sql
  assets/
    style.css
    vendor/     ← UIkit, hls.js, wavesurfer, il font della firma (0.2.0)
```

⚠️ **Federazione multi-nodo: PENDING/FUTURO, non implementata.** Lo schema DB
(`federation_peers`, `federation_log`, colonna `federated_from`, setting
`federation_api_key`) esiste ed è pronto, ma non esiste alcuna pagina
`federation.php` né alcun endpoint `api/federation/*`, e nessuna voce in
navbar. Vedi sezione dedicata più sotto.

## Configurazione dell'istanza (0.1.0, 2026-07-31)

Fino alla 0.0.1 ogni percorso era scritto nel codice, e in più punti: `config.php`
dichiarava `FM_BASE`, e poi ognuno dei sei script bash la ridichiarava per conto
proprio, insieme al nome del database. L'utente di sistema, il sottopercorso web
e l'indirizzo del server RTMP comparivano a loro volta in una decina di file fra
applicazione, unit systemd, regole nginx e sudoers.

Non era solo brutto: rendeva **impossibile una seconda installazione sulla stessa
macchina**, e quindi impossibile collaudare un pacchetto senza rischiare
l'installazione che registra tutti i giorni.

### Un file, due lettori

Il punto di verità è un file per istanza, `/etc/fluxus/<istanza>.conf`, in forma
`CHIAVE=valore` con i commenti che cominciano per `#`. Lo leggono in due:

| Chi | Come |
|---|---|
| gli script bash | `scripts/fluxus-env.sh`, che ogni script carica come prima riga |
| l'applicazione | `includes/conf.php`, caricato da `config.php` |

I due lettori sono gemelli e devono restare tali: stesso formato, stesse regole di
derivazione. Se si cambia uno va cambiato l'altro.

⚠️ **Il file viene letto, non eseguito.** Il lato bash non fa `source`: una riga
come `FLUXUS_HW_ENCODER_OPTS=-preset veryfast -crf 23` gli farebbe eseguire il
comando `-crf`, e ogni valore con uno spazio andrebbe messo fra virgolette.
Leggendolo riga per riga i valori restano letterali da entrambi i lati.

⚠️ **Il lato PHP non usa `parse_ini_file()`**, ed è una scelta, non una svista:
quella funzione, davanti a un commento scritto con `#` invece che con `;`,
fallisce sull'**intero file** e torna `false` senza dire niente. È già costato una
diagnosi ai tempi di `/etc/fluxus-media.env`. Con un lettore proprio la trappola
non esiste più, e i commenti si scrivono nel modo che tutti si aspettano.

### Come ciascuno trova la propria configurazione

Il problema vero non è leggere il file: è sapere **quale** file, su una macchina
dove le installazioni possono essere due.

**Gli script si localizzano da soli.** Vivono in `<cartella dati>/scripts`,
quindi risalgono di un livello e trovano `<cartella dati>/fluxus.conf`, che
l'installer crea come collegamento a `/etc/fluxus/<istanza>.conf`. È il motivo
per cui uno script lanciato a mano da un terminale, uno lanciato da un timer e
uno lanciato da PHP finiscono sempre sull'istanza a cui appartengono, senza
dipendere dall'ambiente. `FLUXUS_CONF` nell'ambiente ha comunque la precedenza,
e la impostano sia gli unit systemd sia l'applicazione quando lancia uno script.

**L'applicazione** prova nell'ordine: `FLUXUS_CONF` dall'ambiente,
`includes/instance.php` (una riga scritta dall'installer, non versionata), il
nome della cartella che la contiene (`/var/www/fluxus-dev` → istanza
`fluxus-dev`).

**Se nessuno dei due trova la configurazione, si ferma.** Non esiste un percorso
predefinito di ripiego: su una macchina con due installazioni, un percorso
indovinato scriverebbe nella cartella dell'altra. Lo script esce con 78
(`EX_CONFIG`) e l'applicazione risponde 500 con il nome del file che si aspettava.

### Cosa sta nel file e cosa no

Nel file sta **solo ciò che non si può dedurre**. Le cartelle sotto la radice
dati (`db/`, `recordings/`, `clips/`, `tmp/`, `logs/`, `scripts/`, `sessions/`)
restano derivate in codice: sono la struttura, non una scelta.

Ogni chiave assente si ricava dal nome dell'istanza — `/var/lib/<istanza>`,
`/var/www/<istanza>`, `/<istanza>`, prefisso dei servizi `<istanza>` — quindi il
file minimo utile è una riga sola. L'installer le scrive comunque tutte per
esteso: un file esplicito è più facile da leggere fra sei mesi.

⚠️ **Il nome del file di database resta `fluxus_media.db`** anche per le istanze
che si chiamano diversamente. Sta già dentro una cartella dati per istanza, non
può collidere con niente, e cambiarlo renderebbe illeggibile ogni installazione
esistente in cambio di nulla.

⚠️ **I segreti stanno in un file a parte**, `/etc/fluxus/<istanza>.remote.conf`
(`root:<gruppo>` 0640): la configurazione principale è leggibile da tutti, e la
chiave del relay non deve esserlo.

⚠️ **Il prefisso dei servizi è una chiave a sé** (`FLUXUS_UNIT_PREFIX`), con
default uguale al nome dell'istanza. L'installazione da cui il progetto proviene
usa il prefisso storico `fm`: se un giorno la si porta sull'installer, quella
chiave va messa a `fm`, o i suoi timer risultano sconosciuti al nuovo codice.

### Il nome dell'istanza compare anche fuori dai percorsi

Due punti facili da dimenticare, entrambi già sistemati:

- **la cartella creata sui dischi esterni** (`<mount>/<istanza>/`, prima era
  `fluxus-media` fissa): due installazioni che usano lo stesso disco USB si
  sarebbero sovrascritte le registrazioni;
- **i nomi degli alias di sudo** nel file `/etc/sudoers.d/<istanza>`: i
  `Cmnd_Alias` vivono in uno spazio di nomi unico per tutto sudo, e due file con
  gli stessi nomi rendono illeggibile l'intero sudoers, non solo il proprio.

I nomi dei **file di log** restano invece `fm-*`: vivono già dentro una cartella
per istanza, non c'è collisione da risolvere e rinominarli avrebbe reso false
tutte le tracce citate in questo documento.

### `fluxus-enable-volume.sh` è unico per la macchina

Lo script privilegiato sta in `/usr/local/bin` e non appartiene a nessuna
istanza: cartella, utente e gruppo gli arrivano quindi come **argomenti**. Non
legge una configurazione, e non deve farlo — gira come root su richiesta di un
processo che root non è. È la regola sudo a fissare quegli argomenti ai valori
dell'istanza; lo script si limita a controllare che siano sensati (nome di
cartella senza percorsi, utente e gruppo esistenti).

## Installazione (0.3.0, 2026-08-01)

Fino alla 0.2.0 Fluxus non era installabile: l'installer ereditato era fermo a
una versione molto precedente e non copiava nemmeno i file dell'applicazione.
Ogni installazione era nata a mano, e non esisteva modo di **provare** una
modifica se non sulla macchina che registra tutti i giorni.

Ora sono due file: `install.sh`, che installa, e `bin/fluxus`, che governa ciò
che è installato. Il primo si occupa di pacchetti, cartelle, applicazione,
script, configurazione, servizi, permessi, server web, server RTMP e database, e
finisce stampando l'indirizzo a cui collegarsi.

### Rieseguibile, perché aggiornare è reinstallare

Rilanciare `install.sh` sulla stessa istanza è il modo normale di aggiornarla —
è esattamente ciò che fa `fluxus update`. Perché sia possibile:

- i valori che non vengono ripetuti sulla riga di comando **si rileggono dalla
  configurazione esistente**, non tornano a quelli di fabbrica. Un aggiornamento
  che riporta di nascosto la radice web al valore predefinito sposterebbe
  l'installazione senza dirlo;
- l'applicazione si copia con `rsync --delete`, così i file di una versione
  precedente spariscono, ma con due eccezioni: `includes/instance.php` e
  `VERSION`, che li scrive l'installer e non il sorgente;
- **il database non si tocca mai.** Non c'è uno schema da applicare a mano: si
  carica una volta l'applicazione da riga di comando, come utente di Fluxus, e
  sono `db_init.php` e le sue migrazioni a fare tutto. Un installer che sapesse
  creare le tabelle per conto suo sarebbe un terzo lettore dello schema da
  tenere allineato agli altri due;
- l'inclusione dentro il vhost di nginx è racchiusa fra marcatori e viene
  aggiunta una volta sola;
- i segreti del relay (`<istanza>.remote.conf`) si scrivono solo se non ci sono:
  un aggiornamento non deve poter cancellare una chiave.

### Le guardie

Sono la parte più importante, perché l'installer di collaudo lavora **accanto a
un'installazione che registra davvero**:

| Guardia | Perché |
|---|---|
| radice web e cartella dati devono essere vuote o già di questa istanza | un errore di battitura nel nome dell'istanza non può finire dentro un'installazione in servizio |
| non si toccano unit systemd con un prefisso non installato da noi | protegge i timer `fm-*` dell'installazione storica: due istanze con lo stesso prefisso si spengono i timer a vicenda |
| ci si ferma se l'istanza ha una registrazione in corso | riscrivere gli script e riavviare i timer mentre ffmpeg scrive è il modo migliore per perdere una diretta |
| il database si apre **solo** come utente di Fluxus | è in WAL: aperto da root anche in sola lettura, i file `-wal`/`-shm` nascono di root e PHP-FPM non scrive più. È già successo |
| nginx: copia di sicurezza, `nginx -t`, ripristino automatico | quel vhost serve anche gli altri siti dell'host; e si ricarica, non si riavvia |
| `--dry-run` | con un'installazione in servizio a fianco, poter vedere prima ogni singola azione non è un lusso |

Come si riconosce un'installazione propria: la radice web contiene
`includes/instance.php` che dichiara quel nome, e la cartella dati contiene il
collegamento `fluxus.conf` che punta a `/etc/fluxus/<istanza>.conf`.

⚠️ **Il collegamento `fluxus.conf` è anche la firma dei dati**, e per questo la
disinstallazione che conserva i dati **lo lascia dov'è**, benché punti a un file
che non esiste più. Senza, reinstallando la stessa istanza l'installer non
riconoscerebbe più come propria quella cartella e si rifiuterebbe di
riprendersela — cioè il caso per cui i dati erano stati conservati. Per lo
stesso motivo il collegamento si legge alla lettera (`readlink`) e non si
risolve.

### L'ordine dentro nginx non è un dettaglio

L'inclusione va inserita **subito dopo la riga `server {`**, non in fondo al
blocco. nginx valuta le `location` con espressione regolare nell'ordine in cui
compaiono: se il `location ~ \.php$` generico del vhost venisse prima di quello
di Fluxus, il `fastcgi_read_timeout 300` non verrebbe applicato e l'estrazione
di una clip lunga morirebbe a metà. È lo stesso vincolo scritto in cima al
modello `nginx/locations-fluxus.conf.in`.

### Un MediaMTX per istanza

Il server RTMP non si condivide: i percorsi di ingresso sono numerati per id di
sorgente, e due installazioni sullo stesso MediaMTX avrebbero la sorgente 3
dell'una e la 3 dell'altra allo stesso indirizzo. Ogni istanza ha quindi il suo
servizio `<prefisso>-mediamtx`, la sua configurazione in
`/etc/fluxus/<istanza>.mediamtx.yml` e le sue porte, scelte dall'installer fra
quelle libere a partire da 1935 (RTMP) e 9997 (API).

Nella configurazione per istanza **RTSP, HLS, WebRTC e SRT restano spenti**:
Fluxus non li usa — le sorgenti RTSP le apre ffmpeg per conto suo e l'anteprima
è un HLS prodotto da `preview.sh` — e ogni protocollo acceso sarebbe un'altra
porta da far litigare con l'istanza accanto (RTSP ne vuole tre: TCP più RTP e
RTCP su UDP). L'eseguibile invece è uno solo per la macchina: se manca,
l'installer lo dice e prosegue, perché tutto il resto di Fluxus funziona lo
stesso.

### Le due copie del lettore di configurazione

`scripts/fluxus-env.sh` finisce in **due posti diversi**, con proprietari
diversi:

| Dove | Di chi | Chi lo carica |
|---|---|---|
| `<cartella dati>/scripts/fluxus-env.sh` | utente di Fluxus | gli script di registrazione, che girano come lui |
| `/usr/local/lib/fluxus/fluxus-env.sh` | `root:root` | il comando `fluxus`, che gira come root |

Non è una duplicazione per distrazione: il comando `fluxus` gira come root, e se
caricasse il file che sta nella cartella dell'utente di Fluxus, quell'utente
potrebbe riscriverlo e ottenere root al primo `fluxus status` dell'amministratore.
È lo stesso ragionamento per cui `fluxus-enable-volume.sh` sta in
`/usr/local/bin` e non fra gli script dell'istanza. Il sorgente resta uno solo.

### Ciò che l'installer non si prende la libertà di fare

- **Non sovrascrive `/usr/local/bin/fluxus-enable-volume.sh` se è diverso**: è
  unico per la macchina e lo condividono tutte le istanze, quindi sostituirlo
  cambierebbe il comportamento anche di quelle che non si stanno installando.
  Avvisa e prosegue; `--volume-helper overwrite` lo sostituisce, con copia di
  sicurezza, quando si è certi che nessun'altra istanza dipenda dalla versione
  vecchia.
- **Non attiva il timer di `remote-sync` se Fluxus Remote non è configurato**:
  sarebbe un servizio che si sveglia ogni 5 secondi per uscire subito.
- **Non crea utenti di sistema** (tranne quello di MediaMTX, che non è di
  nessuna istanza): l'utente di Fluxus deve esistere già.
- **Non installa pacchetti che ci sono già**: su una macchina che ospita
  un'installazione in servizio, un `apt install` inutile è un rischio inutile.

### Il manifesto dell'installazione

`/etc/fluxus/<istanza>.install` non è configurazione: è **memoria**. Registra
versione e data, da quale sorgente si è installato, quali unit sono stati
scritti, quale vhost è stato toccato, dove sta lo snippet, come si è deciso per
MediaMTX. Serve a `fluxus update` per sapere da dove aggiornare e a
`fluxus uninstall` per sapere che cosa disfare, senza andarlo a indovinare.

### `curl … | sudo bash`

La forma della roadmap non è ancora possibile: il repository è privato, e non
c'è niente da scaricare senza credenziali. `install.sh` lavora quindi sul
sorgente in cui si trova, o su quello indicato da `--source`. Senza un terminale
— in una pipe, da uno script, da cron — non fa domande e prosegue con i valori
predefiniti, che è poi la modalità non interattiva richiesta: quando la forma
scaricata arriverà, funzionerà già.

### Il comando `fluxus`

Unico per la macchina, conosce tutte le istanze perché le legge da
`/etc/fluxus/*.conf`. Con una sola installata lavora su quella; **con più d'una
e nessuna indicata si ferma ed elenca**, non ne indovina una: `fluxus uninstall`
che tira a indovinare cancella l'installazione sbagliata.

Due dettagli che vengono da guasti già noti:

- il backup del database si fa con `.backup` di sqlite, non con `cp`: un file in
  WAL copiato con `cp` si porta dietro metà di una transazione. E si legge come
  utente di Fluxus, come tutto il resto;
- `restore` si segna **quali timer erano in moto** prima di fermarli, e alla
  fine riaccende quelli e soltanto quelli: un timer spento di proposito deve
  restare spento.

### Rotazione dei log (0.3.1, 2026-08-01)

Prima non c'era: era nell'elenco delle cose da fare prima della 1.0. I numeri
reali su un'installazione con una settimana di vita hanno chiarito dove sta
davvero il problema — non dove sembrava a prima vista.

I quattro log "di servizio" (`fm-extract-clips.log`, `fm-retention.log`,
`fm-remote-sync.log`, `fm-schedule.log`) pesavano insieme meno di 200 KB dopo
una settimana: `extract_clips.sh` scrive solo quando estrae davvero una clip,
non a ogni giro del timer. Non erano l'urgenza.

⚠️ **Il vero peso erano i `fm-record-{id}.log`**, mai cancellati: alcuni erano
già a 19 MB. `retention_cleanup.sh` cancellava il file multimediale di una
registrazione vecchia insieme alla sua riga nel database, ma non il suo log —
che restava per sempre, indipendente dai limiti di retention già configurati
per ogni sorgente.

La soluzione non è quindi una sola, ed è stata decisa log per log — non una
politica unica calata su tutti:

| Log | Politica | Perché |
|---|---|---|
| `fm-record-{id}.log` | resta finché esiste la registrazione, poi **30 giorni di grazia** dalla cancellazione | è un file per registrazione: la sua fine naturale è quella della registrazione, ma un mese in più aiuta a capire cos'è successo a qualcosa che non c'è più |
| `fm-extract-clips.log` | settimanale, 12 copie | operativo: ~3 mesi bastano |
| `fm-preview-*.log` | settimanale, 12 copie | stessa natura di extract-clips, anche se il contenuto è già "effimero" per conto suo |
| `fm-retention.log` | settimanale, **26** copie | è un registro d'archivio — l'unico modo di ricostruire "dove è finito quel file" mesi dopo — non un log operativo: vale la pena tenerlo più a lungo |
| `fm-schedule.log` | settimanale, **26** copie | stesso ragionamento: chi ha lanciato una registrazione programmata e con quale esito, utile mesi dopo |
| `fm-remote-sync.log` | **giornaliera, 30 copie, `maxsize 8M`** | il più chiacchierone: interroga ogni 5 secondi se Fluxus Remote è attivo |

**`fm-record-{id}.log` non ruota, ha un ciclo di vita a sé.** Sia
`retention_cleanup.sh` (cancellazione automatica) sia `api/recordings.php`
(cancellazione manuale dall'interfaccia) — due punti diversi, tenuti allineati
apposta — al momento di cancellare una registrazione non toccano più il suo
log: lo **`touch`ano**. Il tocco segna l'istante della cancellazione, non
quello — spesso molto più vecchio — dell'ultima riga scritta da `record.sh`: è
da lì che deve contare il mese di grazia, non da quando la registrazione è
finita di registrare. Una funzione a parte,
`cleanup_orphaned_record_logs()` in `retention_cleanup.sh`, cerca ogni giro i
log più vecchi di 30 giorni **il cui id non esiste più nella tabella
`recordings`** e solo quelli cancella davvero — un log il cui id esiste ancora
(magari perché sta su un volume esterno capiente, conservato più a lungo) non
si tocca, quale che sia la sua età per data di modifica.

**Gli altri cinque ruotano con `logrotate`**, di sistema (il timer c'è già su
Debian). Il modello è
[config/logrotate.fluxus.in](../config/logrotate.fluxus.in), reso e installato
da `install.sh` in `/etc/logrotate.d/<istanza>`, verificato con `logrotate -d`
prima di essere messo al suo posto — stesso principio di `nginx -t` e
`visudo -cqf`.

⚠️ **La direttiva `su <utente> <gruppo>` non è decorativa.** Un logrotate
recente rifiuta di default una cartella di log scrivibile dal gruppo se quel
gruppo non è `root` ("insecure permissions") — e `<cartella dati>/logs` è
`0775 <utente-di-Fluxus>:<gruppo>` apposta, perché gli script ci scrivono senza
essere root. Senza `su`, la rotazione non parte mai, e non lo dice a nessuno:
fallisce in silenzio, ogni settimana, per sempre. Trovato collaudando
sull'istanza di prova, non a vista.

La rotazione per rinomina (non `copytruncate`) è sicura perché ogni scrittore
apre il proprio log in append (`>>`, `O_APPEND`): un processo che tenesse la
scrittura aperta nell'istante della rotazione — `preview.sh`, l'unico dei
quattro non a esecuzione breve — continuerebbe a scrivere sul file rinominato
senza perdere un byte, e riaprirebbe il percorso vero al giro successivo.

## config.php — Costanti

```php
<?php
require_once __DIR__ . '/conf.php';
$_fmConf = fmInstanceConf();          // legge /etc/fluxus/<istanza>.conf

define('FM_VERSION',    fmReadVersion());   // dal file VERSION, unica fonte
define('FM_APP_NAME',   $_fmConf['FLUXUS_APP_NAME']);   // in UI — NON "Fluxus-Media"
define('FM_INSTANCE',   $_fmConf['FLUXUS_INSTANCE']);
define('FM_NODE_TYPE',  FM_INSTANCE);
define('FM_CONF_FILE',  $_fmConf['FLUXUS_CONF_FILE']);

define('FM_BASE',       rtrim($_fmConf['FLUXUS_DATA_DIR'], '/'));
define('FM_WEB_DIR',    rtrim($_fmConf['FLUXUS_WEB_DIR'], '/'));
define('FM_WEB_BASE',   rtrim($_fmConf['FLUXUS_WEB_BASE'], '/'));   // '' = radice del vhost
define('FM_DB',         FM_BASE . '/db/fluxus_media.db');
define('FM_RECORDINGS', FM_BASE . '/recordings');
define('FM_CLIPS',      FM_BASE . '/clips');
define('FM_TMP',        FM_BASE . '/tmp');
define('FM_SCRIPTS',    FM_BASE . '/scripts');
define('FM_LOGS',       FM_BASE . '/logs');

define('FM_USER',        $_fmConf['FLUXUS_USER']);        // unit generati, messaggi UI
define('FM_GROUP',       $_fmConf['FLUXUS_GROUP']);
define('FM_UNIT_PREFIX', $_fmConf['FLUXUS_UNIT_PREFIX']); // <prefisso>-sched-{id}
define('FM_VOLUME_DIR',  FM_INSTANCE);                    // cartella sui dischi esterni

// Sessione dedicata a Fluxus: NON tocca il php.ini di sistema (vedi Auth)
define('FM_SESSIONS',    FM_BASE . '/sessions');
define('FM_SESSION_TTL', 21600);              // 6 ore
define('FM_PREVIEW_TTL', 900);                // relay di anteprima lasciato aperto

// Server RTMP (MediaMTX)
define('FM_RTMP_HOST',    $_fmConf['FLUXUS_RTMP_HOST']);
define('FM_RTMP_PORT',    $_fmConf['FLUXUS_RTMP_PORT']);
define('FM_MEDIAMTX_API', $_fmConf['FLUXUS_MEDIAMTX_API']);

// Encoder di ripiego: la registrazione NON passa di qui (decide video_quality)
define('FM_HW_ENCODER',      $_fmConf['FLUXUS_HW_ENCODER']);
define('FM_HW_ENCODER_OPTS', $_fmConf['FLUXUS_HW_ENCODER_OPTS']);

// Fluxus Remote — relay marker/cue da fuori LAN (v2.1), vuoto = disattivato
define('FM_REMOTE_URL',     $_fmConf['FLUXUS_REMOTE_URL']);
define('FM_REMOTE_API_KEY', $_fmConf['FLUXUS_REMOTE_API_KEY']);
define('FM_NODE_NAME',      $_fmConf['FLUXUS_NODE_NAME']);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db_init.php';   // auto-init + migrazioni incrementali, vedi sezione dedicata
```

⚠️ `FM_VERSION` legge il file `VERSION`, che è l'unica fonte della versione in
tutto il progetto. Prima era una costante scritta a mano, e conviveva con un
secondo numero nel repository: due contatori da tenere allineati a memoria.
`settings.schema_version` non c'entra nulla — è il contatore delle migrazioni
del database.

⚠️ Non esiste più alcuna impostazione `web_base` nel database. Ce n'era una,
salvabile da Impostazioni, che **nessuno leggeva**: il sottopercorso veniva
comunque dalla costante. Cambiarlo a caldo non avrebbe potuto funzionare —
l'autorità vera è nginx — quindi il campo è stato tolto e al suo posto la pagina
mostra, in sola lettura, istanza, sottopercorso e file di configurazione in uso.

**Storico versioni**: 2.1.0 = Fluxus Remote (v2.1). 2.2.0 (2026-07-27) =
pre-roll/post-roll dei cue configurabili da UI (box "Cue" in settings.php),
prima erano hardcoded in extract_clips.sh — minor bump, nessuna rottura di
compatibilità. 2.2.1 (2026-07-27) = nomi di default marker/cue lato server
(`marker_ID`/`cue_ID`), barra di avanzamento nel modale Marker/Cue per
l'autosalvataggio, durata dell'autosalvataggio configurabile da UI (box
"Marker & Cue" in settings.php, con slider+campo numerico), fix del
segmento finale vuoto (0s) in record.sh quando slot_duration è multiplo
esatto di segment_duration (vedi vincolo 13) — patch bump, nessuna rottura
di compatibilità. 2.3.0 (2026-07-27) = profili di qualità video per sorgente
(`sources.video_quality`), che rendono finalmente controllabile il peso delle
registrazioni video; modale sorgente ripulito dai campi morti/fuorvianti;
colonna "Dimensione" in recordings.php — minor bump, nessuna rottura di
compatibilità (le sorgenti esistenti restano su `copy`, comportamento
identico a prima). Vedi "Qualità video per sorgente". 2.4.0 (2026-07-27) =
ripresa automatica della registrazione dopo una caduta dello stream (ciclo
supervisore in record.sh) e fine delle scritture DB perse per SQLITE_BUSY
(WAL + busy_timeout + un solo scrittore per evento) — minor bump: nuova
funzionalità di resilienza, più il fix delle durate mancanti/assurde. Vedi
"Ripresa automatica e robustezza DB". 2.4.1 (2026-07-27) = anteprima live
riparata e riscritta su un relay ffmpeg→HLS locale (quella basata sul muxer
HLS di MediaMTX non ha mai funzionato, vincolo 17), estesa alle sorgenti audio
e disponibile anche in `recording.php` durante la registrazione; pulsante
"Check" di raggiungibilità sorgente in dashboard; badge AUDIO/VIDEO nelle card
sorgente — patch bump per scelta esplicita dell'utente, nessuna rottura di
compatibilità. Vedi "Anteprima live — audio e video". 2.4.2 (2026-07-27) =
watchdog di stallo in record.sh (uno stream che si congela senza chiudere la
connessione lasciava ffmpeg appeso a tempo indefinito, oltre la fine dello slot
e sordo allo stop dall'UI) e `-movflags` finalmente propagati al muxer mp4
figlio in modalità segmentata, che rendevano irrecuperabile ogni segmento non
chiuso — patch bump: completa la resilienza annunciata dalla 2.4.0 e corregge
un bug di corruzione, nessuna nuova UI. Vedi "Watchdog di stallo". 2.4.3
(2026-07-28) = `duration_seconds` è ora la durata del **contenuto** e non il tempo
trascorso, e i cue vengono tagliati nel punto giusto anche quando la
registrazione ha dei buchi (stream partito in ritardo, riprese dopo una caduta)
— patch bump: due comportamenti sbagliati corretti, nessuna nuova funzione né
modifica di schema. Vedi "Durata e cue allineati al contenuto". 2.4.4 (2026-07-29)
= nuovo profilo di qualità video `hd` (`veryfast -crf 17`) e forbice fra i profili
allargata (`media` crf 25→28, `bassa` crf 28→32; audio 128k su hd/alta/media, 96k
su bassa): prima fra `alta` e `bassa` c'era un fattore 2,5 di peso e SSIM quasi
indistinguibili, ora il fattore è ~5,8. Nessuna modifica di schema (`video_quality`
è già una stringa libera, `hd` è solo un nuovo valore) — patch bump per scelta
esplicita dell'utente. 2.5.0 (2026-07-30) = archiviazione su più volumi:
registrazioni e cue possono andare su un disco esterno, con destinazioni distinte
per audio e video e override per sorgente; migrazione di schema v4→v5
(`storage_volumes`, `sources.storage_volume_id`, `recordings.clips_dir`), elenco
volumi con drag&drop in Impostazioni e menù a tendina nella barra di stato —
minor bump: nessuna rottura, tutto l'esistente resta sul volume interno con
comportamento identico. Nella stessa occasione è stato corretto un difetto della
2.4.4: `hd` mancava nella whitelist di `video_quality` in `sources.php`, quindi
il profilo era selezionabile ma veniva salvato come `copy`. Vedi "Qualità video per sorgente".
2.5.1 (2026-07-30) = due lotti indipendenti. (a) Il percorso di ingresso delle
sorgenti `rtmp-push` non si assume più ma si chiede a MediaMTX, così gli encoder
che concatenano "indirizzo" e "stream" (Wirecast pubblica su `21/21`) funzionano
senza configurazioni particolari — vedi "Percorso di ingresso rtmp-push".
(b) Fine delle perdite silenziose di marker/cue: il modale non si chiude più
prima di sapere l'esito e non esiste più alcun `catch` vuoto, le API rispondono
401 invece di un redirect, la sessione ha una `save_path` e un TTL propri (6h)
senza toccare il php.ini di sistema, c'è un keep-alive, e i marker/cue si possono
inserire **a posteriori** su una registrazione conclusa indicando l'istante —
vedi "Marker/cue: niente più perdite silenziose". Nella stessa occasione le
registrazioni sono state rinumerate da `V90004…` a `V048…V057`: prove fatte sul DB
di produzione avevano spostato `sqlite_sequence`, vedi "Numerazione delle
registrazioni". Patch bump per scelta esplicita dell'utente: nessuna modifica di
schema e nessuna rottura di compatibilità.
2.5.2 (2026-07-30) = due ritocchi alla pagina della singola registrazione. (a)
L'anteprima di un segmento video (o del file unico) non si apre più in un modale
ma **in linea, nella riga subito sotto quella cliccata**, così resta visibile a
quale file appartiene ciò che si sta guardando. (b) Il riquadro "marker/cue a
posteriori" è diventato una **barra compatta di una riga attaccata in fondo alla
card dei file** invece di una card a sé, e il menù a tendina ha ora **Marker**
come valore predefinito al posto di Cue. Nella stessa occasione l'elenco dei
segmenti **audio** mostra la durata reale di ogni file accanto alla dimensione
(nuovo helper `fmProbeDuration()`), che il video aveva già; la **"Zona
pericolosa"** è passata dalla colonna di destra al fondo della pagina, dopo
l'elenco marker; e tutte le pagine hanno ora un **piè di pagina** con `© <anno>`,
versione e la firma `a Fabio Ranfi solution` (nome in *Recursive* 700, da Google
Fonts). Solo UI: nessuna modifica di schema, di API o di
script, nessuna rottura di compatibilità. Vedi "UI: recording.php" e "Piè di
pagina".

## DB Schema (db/schema.sql)

```sql
CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
-- Keys: node_id (UUID v4), node_name, timezone, password_hash,
--       auth_enabled (0/1), web_base, federation_api_key, schema_version,
--       cue_pre_roll (secondi, default 30, 0-120), cue_post_roll (secondi, default 90, 1-240),
--       storage_volume_audio / storage_volume_video (id volume, default 1),
--       storage_volume_order (ordine dei volumi, un mount point per riga),
--       storage_volume_hidden (volumi tolti dall'elenco, uno per riga)

-- Volumi di archiviazione (v2.5.0): la riga id=1 è il volume interno (FM_BASE),
-- non eliminabile. Vedi "Archiviazione su più volumi".
CREATE TABLE storage_volumes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    label      TEXT NOT NULL,
    path       TEXT NOT NULL UNIQUE,
    is_default INTEGER DEFAULT 0,
    active     INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now'))
);
-- federation_api_key esiste già in DB (generata al primo avvio) ma non è ancora
-- esposta in nessuna UI né usata da alcun endpoint — vedi "API di Federazione" (PENDING/FUTURO).

CREATE TABLE sources (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    media_type            TEXT NOT NULL DEFAULT 'audio',  -- audio | video
    url                   TEXT,           -- NULL per v4l2
    type                  TEXT NOT NULL,  -- http | rtmp | rtsp | srt | v4l2 | rtmp-push
    device                TEXT,           -- /dev/video0 per v4l2
    file_prefix           TEXT,           -- prefisso breve opzionale usato in filename_base (es. "KR")
    -- Audio-specific
    audio_quality         TEXT DEFAULT '2',   -- libmp3lame -q:a (0=best, 9=worst)
    -- Video-specific
    resolution            TEXT DEFAULT '1920x1080',  -- SOLO v4l2 (cattura del device)
    fps                   INTEGER DEFAULT 25,        -- SOLO v4l2 (cattura del device)
    video_quality         TEXT DEFAULT 'copy',   -- copy | hd | alta | media | bassa (vedi sezione dedicata)
    video_codec           TEXT DEFAULT 'copy',   -- DEPRECATA (v2.3.0): sostituita da video_quality
    audio_codec           TEXT DEFAULT 'copy',   -- DEPRECATA (v2.3.0): sostituita da video_quality
    extra_opts            TEXT,                  -- flag ffmpeg extra, ora realmente applicate
    -- Retention automatica per sorgente (0 = nessun limite), vedi scripts/retention_cleanup.sh
    max_recordings        INTEGER DEFAULT 30,   -- tiene solo le N registrazioni più recenti
    max_days_recordings   INTEGER DEFAULT 45,   -- elimina registrazioni più vecchie di N giorni
    max_clips_per_marker  INTEGER DEFAULT 100,  -- tiene solo gli N cue più recenti
    max_days_clips        INTEGER DEFAULT 20,   -- elimina cue più vecchi di N giorni
    storage_volume_id     INTEGER REFERENCES storage_volumes(id),  -- NULL = default del media_type (v2.5.0)
    federated_from        INTEGER REFERENCES federation_peers(id),
    active                INTEGER DEFAULT 1,
    created_at            TEXT DEFAULT (datetime('now'))
);

CREATE TABLE schedules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id        INTEGER NOT NULL REFERENCES sources(id),
    label            TEXT,
    on_calendar      TEXT NOT NULL,  -- formato systemd OnCalendar
    slot_duration    INTEGER DEFAULT 3600,
    segment_duration INTEGER DEFAULT 0,  -- 0 = no segmentazione
    federated_from   INTEGER REFERENCES federation_peers(id),
    active           INTEGER DEFAULT 1,
    created_at       TEXT DEFAULT (datetime('now'))
);

CREATE TABLE recordings (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id        INTEGER REFERENCES sources(id),
    schedule_id      INTEGER REFERENCES schedules(id),
    source_name      TEXT NOT NULL,
    media_type       TEXT NOT NULL DEFAULT 'audio',  -- audio | video
    filename_base    TEXT NOT NULL,
    output_dir       TEXT NOT NULL,
    clips_dir        TEXT,           -- cartella dei cue (v2.5.0); NULL = FM_CLIPS/{source_id}
    start_time       TEXT,
    end_time         TEXT,
    duration_seconds INTEGER,
    status           TEXT DEFAULT 'recording',  -- recording | completed | failed
    trigger_type     TEXT DEFAULT 'scheduled',  -- scheduled | manual
    slot_duration    INTEGER DEFAULT 0,
    segment_duration INTEGER DEFAULT 0,
    -- Video metadata (NULL per registrazioni audio)
    width            INTEGER,
    height           INTEGER,
    fps_actual       INTEGER,
    ffmpeg_pid       INTEGER,
    notes            TEXT,
    created_at       TEXT DEFAULT (datetime('now'))
);

CREATE TABLE markers (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    recording_id       INTEGER NOT NULL REFERENCES recordings(id),
    elapsed_seconds    INTEGER NOT NULL,
    elapsed_hms        TEXT NOT NULL,
    absolute_time      TEXT NOT NULL,
    label              TEXT,
    type               TEXT NOT NULL DEFAULT 'cue',  -- marker | cue
    clip_status        TEXT DEFAULT 'n/a',  -- n/a | pending | ready | failed
    clip_filename      TEXT,
    clip_trim_filename TEXT,   -- solo per cue AUDIO (da EDIT)
    origin             TEXT NOT NULL DEFAULT 'local',  -- local | remote (Fluxus Remote, v2.1)
    created_at         TEXT DEFAULT (datetime('now'))
);

CREATE TABLE manual_clips (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    recording_id     INTEGER NOT NULL REFERENCES recordings(id),
    segment_index    INTEGER,
    start_seconds    REAL NOT NULL,
    end_seconds      REAL NOT NULL,
    duration_seconds REAL NOT NULL,
    label            TEXT,
    clip_filename    TEXT,
    created_at       TEXT DEFAULT (datetime('now'))
);

-- Tabelle predisposte per la federazione multi-nodo: schema pronto, ma
-- nessuna API/UI le usa ancora — PENDING/FUTURO, vedi "API di Federazione".
CREATE TABLE federation_peers (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    name                TEXT NOT NULL,
    url                 TEXT NOT NULL,
    api_key             TEXT NOT NULL,
    node_id             TEXT,
    node_type           TEXT,   -- audio | fluxus-media
    last_sync           TEXT,
    auto_sync_sources   INTEGER DEFAULT 1,
    auto_sync_schedules INTEGER DEFAULT 0,
    active              INTEGER DEFAULT 1,
    created_at          TEXT DEFAULT (datetime('now'))
);

CREATE TABLE federation_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    peer_id    INTEGER REFERENCES federation_peers(id),
    action     TEXT,
    status     TEXT,
    message    TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);
```

## scripts/record.sh

Argomenti: `record.sh <recording_id> <source_id> <duration_sec> [segment_sec]`

Leggi dal DB: media_type, url, type, device, video_codec, audio_codec,
audio_quality, resolution, fps, extra_opts.

### Output in base a media_type

**AUDIO** → file MP3
```bash
# type=http: -reconnect* solo qui (AVOption esclusiva di http/https):
ffmpeg -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 30 \
    -i "$URL" -vn -c:a libmp3lame -q:a "$AUDIO_QUALITY" \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp3"

# type=rtmp|rtsp: niente -reconnect* (non sono AVOption di questi protocolli,
# ffmpeg abortirebbe subito con "Option reconnect not found"):
ffmpeg -i "$URL" -vn -c:a libmp3lame -q:a "$AUDIO_QUALITY" \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp3"

# Segmentato (stessa distinzione per type):
ffmpeg -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 30 \
    -i "$URL" -vn -c:a libmp3lame -q:a "$AUDIO_QUALITY" \
    -f segment -segment_time $SEG \
    "$OUTPUT_DIR/${FBASE}_%03d.mp3"
```

**VIDEO** → file MP4
```bash
# http (stream copy) — -reconnect* solo qui, AVOption esclusiva di http/https:
ffmpeg -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 30 \
    -i "$URL" -vcodec copy -acodec copy \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp4"

# rtmp/rtsp/srt (stream copy) — niente -reconnect*: non sono AVOption di questi
# protocolli, ffmpeg abortirebbe subito con "Option reconnect not found":
ffmpeg -i "$URL" -vcodec copy -acodec copy \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp4"

# rtmp-push (MediaMTX locale) — stesso motivo, niente -reconnect*:
ffmpeg -i "rtmp://127.0.0.1:1935/$SOURCE_ID" \
    -vcodec copy -acodec copy \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp4"

# v4l2 (webcam/CSI locale):
ffmpeg -f v4l2 -framerate $FPS -video_size $RESOLUTION -i "$DEVICE" \
    -f alsa -i default \
    -vcodec $FM_HW_ENCODER $FM_HW_ENCODER_OPTS \
    -acodec aac -b:a 128k \
    -t $DURATION "$OUTPUT_DIR/${FBASE}.mp4"

# Segmentato: aggiungi -f segment -segment_format mp4 -segment_time $SEG
#   -reset_timestamps 1 "$OUTPUT_DIR/${FBASE}_%03d.mp4"
# NB: qui i -movflags NON vanno sull'output (andrebbero al muxer segment, che non
# li propaga al muxer mp4 figlio) ma in
#   -segment_format_options movflags=frag_keyframe+empty_moov+default_base_moof
# altrimenti un segmento interrotto resta senza moov e illeggibile — vincolo 19.
```

### Logica comune

1. Crea output_dir se non esiste: $FM_RECORDINGS/$SOURCE_ID/
2. Prima di avviare ffmpeg:
   `UPDATE recordings SET status='recording', start_time=datetime('now'), ffmpeg_pid=PID`
3. Avvia ffmpeg (nohup o in primo piano, attendi con wait)
4. Dopo `wait $FFMPEG_PID` e prima di aggiornare lo status: se segmentata
   (`SEGMENT -gt 0`), controlla via `ffprobe` la durata dell'ultimo file
   `${FBASE}_NNN.{mp3,mp4}` e lo elimina se dura meno di 1s (o se ffprobe
   fallisce a leggerlo) — vedi vincolo 13 più sotto.
5. Al termine:
   `UPDATE recordings SET status='completed'|'failed', end_time=datetime('now'), duration_seconds=...`
6. Log: $FM_LOGS/fm-record-{recording_id}.log

⚠️ **I comandi video qui sopra descrivono il profilo `copy`** (default). Se la
sorgente ha un `video_quality` diverso, `-vcodec copy -acodec copy` è sostituito
dai flag del profilo — vedi "Qualità video per sorgente" più sotto.

**IMPORTANTE: non modificare record.sh dopo la creazione iniziale.**

⚠️ Nota operativa: se una registrazione è in corso, sostituire lo script con un
**rename atomico** (`mv` da un file già scritto), non riscrivendolo sul posto.
`bash` legge lo script mentre lo esegue e ne tiene aperto l'offset: riscrivere
lo stesso inode sotto un record.sh vivo può fargli leggere byte diversi da quelli
attesi quando arriva alla finalizzazione. Con `mv` il processo in corso continua
a leggere il vecchio inode fino alla fine, indisturbato.

Eccezioni fatte finora (su richiesta esplicita):
1. (2026-07-26) fix del bug `-reconnect`/`-reconnect_streamed`/`-reconnect_delay_max`
   applicate a URL `rtmp://` — vedi vincolo 1 più sotto.
2. (2026-07-26) `-movflags frag_keyframe+empty_moov+default_base_moof` sui 3 output
   mp4 video (v4l2, rtmp-push, pull http/rtmp/rtsp/srt), per far funzionare i cue
   video come i cue audio — vedi vincolo 12 più sotto.
3. (2026-07-27) rimozione del segmento finale vuoto (0s) quando DURATION è
   multiplo esatto di SEGMENT — vedi vincolo 13 più sotto.
4. (2026-07-27) profili di qualità video per sorgente + `extra_opts` finalmente
   applicate + rimozione della lettura di `/etc/fluxus-media.env` — vedi
   vincolo 14 e la sezione "Qualità video per sorgente".
5. (2026-07-27) ciclo supervisore che riprende la registrazione dopo una caduta
   dello stream, `busy_timeout` su tutte le query e calcolo della durata
   blindato — vedi vincoli 15 e 16 e la sezione "Ripresa automatica e
   robustezza DB".
6. (2026-07-27) watchdog di stallo (lo stream che si congela senza chiudere la
   connessione) e `-movflags` propagati al muxer mp4 figlio in modalità
   segmentata — vedi vincoli 18 e 19 e la sezione "Watchdog di stallo".
7. (2026-07-28) `duration_seconds` scritta come durata del contenuto
   (`content_duration()`, somma ffprobe dei file) invece del tempo trascorso —
   vedi vincolo 20 e la sezione "Durata e cue allineati al contenuto".
8. (2026-07-30) il ramo `rtmp-push` non usa più l'URL fisso
   `rtmp://127.0.0.1:1935/$SOURCE_ID` ma chiede a MediaMTX su quale percorso
   stia realmente arrivando lo stream (`push_input_url()`) — vedi vincolo 8 e
   la sezione "Percorso di ingresso rtmp-push".
9. (2026-07-31, 0.1.0) le quattro righe che dichiaravano `FM_BASE`, `FM_DB`,
   `FM_LOGS` e `FM_TMP` sono state sostituite da un `source` di
   `fluxus-env.sh`, e l'indirizzo del server RTMP arriva dalla configurazione
   invece che dal codice. Nient'altro è cambiato: la logica di registrazione, il
   supervisore, il watchdog e il calcolo della durata sono intatti.
   **Perché è stata fatta**: finché la cartella dati era scritta dentro questo
   file, ogni copia di Fluxus sulla stessa macchina registrava nella stessa
   cartella e sullo stesso database. Non c'era modo di collaudare il pacchetto
   accanto all'installazione che registra tutti i giorni — cioè di verificarlo
   affatto. Vedi vincolo 24 e la sezione "Configurazione dell'istanza".

## Ripresa automatica e robustezza DB (v2.4.0, 2026-07-27)

Due problemi distinti, trovati indagando registrazioni video molto più corte
dello slot. **Attenzione a non ripetere la diagnosi sbagliata**: le
registrazioni 20 e 23 non erano troncate da una caduta dello stream, erano
`trigger_type='manual'` fermate a mano (`Exiting normally, received signal 15`
nel log). Il difetto vero stava sotto.

### A. Scritture DB perse per SQLITE_BUSY

`Error: in prepare, database is locked (5)` su 4 registrazioni su 14 (id 18,
20, 23, 24), tutte con `duration_seconds` NULL. Tre cause sovrapposte:

1. `journal_mode=delete`: un lettore blocca uno scrittore. Con `api/status.php`
   in polling ogni 5s per ogni tab aperta, `fm-remote-sync` ogni 5s,
   `fm-extract-clips` ogni 30s e la retention, la contesa era continua.
2. `busy_timeout=0` ovunque (né in `fmDB()` né nelle chiamate `sqlite3` degli
   script): fallimento istantaneo invece di attesa, e **nessuno controllava
   l'exit code di sqlite3**, quindi la scrittura si perdeva in silenzio.
3. `record.sh` e `stop_recording.sh` scrivevano **le stesse colonne della stessa
   riga** a millisecondi di distanza, sullo stesso evento di stop.

Correzioni: `PRAGMA journal_mode=WAL` (persistente, applicato una volta e
riasserito da `db_init.php`), `busy_timeout=5000` in `fmDB()` e su ogni chiamata
`sqlite3` degli script, e `stop_recording.sh` non finalizza più — vedi sotto.

⚠️ Nelle chiamate `sqlite3` da bash usare **`-cmd ".timeout 5000"`**, mai
`-cmd "PRAGMA busy_timeout=5000"`: il PRAGMA **stampa il proprio risultato**, che
finisce nella prima variabile letta con `IFS='|' read` e rompe ogni script
(errore introdotto e corretto il 2026-07-27: record.sh non trovava più la
registrazione perché `FBASE` valeva "5000"). Il dot-command è silenzioso.

⚠️ Con WAL il DB non è più un file solo: il backup deve includere `-wal`/`-shm`
o passare da un checkpoint. I file `-wal`/`-shm` vanno creati da **www-data**
(se li crea root, PHP-FPM non riesce più a scrivere).

⚠️ **Mai aprire il DB di produzione con un utente diverso da www-data, nemmeno
in sola lettura.** In WAL anche una `SELECT` crea `-wal`/`-shm` se non esistono,
con l'owner di chi ha lanciato il comando: da quel momento PHP-FPM vede
`SQLSTATE[HY000]: General error: 8 attempt to write a readonly database` su ogni
scrittura, e tutta la UI smette di salvare pur continuando a leggere
normalmente. Successo il 2026-07-27: un `sqlite3` di diagnosi lanciato come `pi`
ha lasciato un `-wal` `pi:pi` e ha bloccato il salvataggio degli orari in
`schedules.php`. Forma corretta:

```bash
sudo -u www-data sqlite3 -cmd ".timeout 5000" /var/lib/fluxus-media/db/fluxus_media.db "..."
```

Se il danno è già fatto: `sudo chown www-data:www-data <db>-wal <db>-shm` (il
`-wal` in sospeso non va cancellato, contiene transazioni non ancora
checkpointate).

### B. Nessuna resilienza alla caduta dello stream

Per `rtmp`/`rtsp`/`srt` non esiste riconnessione lato ffmpeg (`-reconnect*` sono
AVOption del solo http/https, vincolo 1). Una caduta a metà slot chiudeva la
registrazione in anticipo marcandola `completed`, **indistinguibile da uno stop
volontario**.

`record.sh` ora ha un **ciclo supervisore** che resta in piedi fino alla
scadenza dello slot (deadline su orologio di parete), rilanciando ffmpeg con
`-t` pari al tempo residuo:

- **Stop voluto vs caduta**: `stop_recording.sh` crea `FM_TMP/stop-{id}` *prima*
  del kill; il supervisore la vede (o legge rc=255, ffmpeg ucciso da segnale) e
  non riprende. Qualunque altra uscita è una caduta → nuovo tentativo dopo 10s.
- **Nomi dei file**: il primo tentativo di una registrazione non segmentata
  scrive `FBASE.ext` **esattamente come prima**. Alla prima caduta il file viene
  rinominato in `FBASE_000.ext` (sotto il lock `clip_queue.lock`, perché
  extract_clips.sh potrebbe star leggendo) e i tentativi successivi proseguono
  con `_001`, `_002`… Per le registrazioni segmentate si usa
  `-segment_start_number` per continuare la numerazione senza buchi.
- **Stato finale** dedotto da cosa c'è su disco, non dall'rc dell'ultimo
  tentativo (che con le riprese non descrive più l'esito): nessun file →
  `failed`. Le interruzioni finiscono in `recordings.notes`.
- `start_time` non viene mai riscritto sulle riprese: resta l'inizio dello slot.
- La pulizia del segmento finale vuoto (vincolo 13) gira ora dopo **ogni**
  tentativo, non solo alla fine.

**Rilevamento multi-file dal disco**: `extract_clips.sh` e `recording.php` non
decidono più "segmentata sì/no" da `segment_duration`, ma da quali file esistono
(`FBASE_NNN.ext`), altrimenti i file di continuazione sarebbero invisibili. Il
ramo concat di `extract_clips.sh` misurava già le durate reali con ffprobe,
quindi regge segmenti di lunghezza disomogenea senza modifiche.

**Calcolo durata blindato**: `DUR=$(( END_TS - START_TS ))` con `START_TS` vuoto
(SELECT fallita) dava l'epoch Unix come durata — **1.785.066.816s = 56 anni**,
visto nei log di 20 e 23. Ora c'è il fallback su `$SECONDS` (cronometro di bash,
non richiede il DB) e lo scarto di ogni valore negativo o oltre `slot+1h`.

## Durata e cue allineati al contenuto (v2.4.3, 2026-07-28)

Con la ripresa automatica (2.4.0) e il watchdog (2.4.2) una registrazione può
contenere **buchi**: lo stream che parte in ritardo rispetto all'orario dello
slot, o che cade e viene ripreso. Da lì due difetti, entrambi riprodotti in
sandbox su uno slot di 240s con publisher partito a +95s.

### A. La durata mostrata era quella dello slot

`record.sh` scriveva `duration_seconds = end_time - start_time`, cioè il tempo
trascorso, che con un buco di 95s dava **262s in elenco per 167s di video reale**.
Ora c'è `content_duration()`: somma con ffprobe le durate dei file prodotti
(`FBASE.ext` + `FBASE_NNN.ext`) e scrive quella. Il tempo trascorso resta il
fallback se ffprobe non risponde, e quando i due valori divergono di oltre 5s il
log lo dice: `Durata: 167s di contenuto su 262s trascorsi (buchi: 95s)`.

Effetto collaterale gradito: il GB/h in `recordings.php` ora è calcolato sulla
durata giusta.

### B. I cue tagliavano nel punto sbagliato

`markers.elapsed_seconds` è tempo di parete dall'inizio dello slot, ma
`extract_clips.sh` lo usava come posizione **dentro il file**: i due coincidono
solo se non ci sono buchi. Con 95s persi all'inizio, un cue premuto a +140s
veniva estratto a 140s di contenuto, cioè **95s più avanti** di quanto si era
visto premendo il pulsante.

`content_position()` ricostruisce la linea del tempo reale **dai file**: ognuno
copre `[mtime - durata, mtime]` e il suo offset nel contenuto è la somma delle
durate precedenti; l'istante del click è `start_time + elapsed_seconds`. Tutto in
epoch, quindi nessuna questione di fuso orario — `start_time` è UTC mentre
`absolute_time` è ora locale, e mescolarli sarebbe un errore di due ore.

Casi coperti dalla funzione: click prima dell'inizio del contenuto → 0; click
dentro un buco → inizio del file successivo; click oltre l'ultimo file → fine del
contenuto.

- **Sotto i 3s di scarto si usa `elapsed_seconds` come prima**: è il caso normale
  e non ha senso far dipendere il taglio da una misura di `mtime`. La correzione
  entra in gioco solo quando serve davvero, e quando entra lo scrive nel log:
  `posizione corretta: 140s di parete -> 45s di contenuto (95s di buchi)`.
- Vale anche per le interruzioni multiple a metà registrazione, non solo per il
  ritardo iniziale.

**Verificato in sandbox**, con un pattern video che porta un cronometro impresso
nell'immagine: cue premuto a +140s su uno stream partito a +95s, primo fotogramma
della clip estratta = **15s** (45s del click − 30s di pre-roll), esattamente il
punto giusto; senza la correzione sarebbe stato 110s. Regressione su una
registrazione sana: nessuna correzione applicata, primo fotogramma a 10s su un
cue a +40s, durata scritta = durata dello slot.

## Watchdog di stallo (v2.4.2, 2026-07-27)

Il ciclo supervisore della 2.4.0 copre il caso in cui ffmpeg **esce**. Non copre
quello in cui lo stream **si congela**: la connessione TCP resta aperta e non
arriva più un byte. Lì ffmpeg non vede alcun errore e resta in `read()` per
sempre — e `-t` non scatta mai, perché conta i timestamp del media, non
l'orologio. Diagnosi fatta sulla registrazione 47 (Spazio Umano, rtmp, slot 2h):
stream fermo alle 18:56 dopo `time=01:50:49`, ffmpeg ancora vivo alle 20:34
(3h31m), `wait $FFMPEG_PID` mai tornato, quindi la deadline dello slot mai
valutata. Tre conseguenze, tutte osservate su quella registrazione:

1. **Durata sbagliata in UI** (3h14m su uno slot di 2h): non l'ha scritta
   record.sh ma il *fallback* di `stop_recording.sh`, che dopo 6s di attesa
   chiude la riga con `now - start_time`. Il log di record.sh conteneva solo la
   riga di start: prova che non ha mai finalizzato.
2. **Stop dall'UI senza effetto**: ffmpeg bloccato in `read()` cattura il
   SIGTERM (`SigCgt` lo include) ma non arriva mai a processarlo.
3. **Secondo segmento corrotto** — causa indipendente, vedi vincolo 19.

`record.sh` ha ora un watchdog che affianca l'attesa di ffmpeg
(`wait_with_watchdog` sostituisce `wait $FFMPEG_PID`, stessa semantica su
`LAST_RC`). Campiona ogni `POLL_SEC=2` la **somma dei byte** di tutti i file
prodotti (`output_size`); se non cresce per `STALL_SEC=60` lo stream è fermo.

| trigger_type | comportamento allo stallo |
|---|---|
| `manual` | la registrazione **si chiude**: non c'è un'ora di fine da rispettare e non ha senso tenere occupata la sorgente |
| `scheduled` | ffmpeg viene chiuso e **la registrazione riprende**, come da una caduta qualunque, fino all'**ora di fine prevista** |

Altre regole:

- `kill_ffmpeg()` manda SIGTERM e, se dopo 3s il processo è ancora vivo, SIGKILL:
  su uno stream congelato solo il secondo funziona.
- Il flag `KILLED_BY_WATCHDOG` distingue un `rc=255` causato dal watchdog da uno
  stop voluto, altrimenti una registrazione programmata non riprenderebbe.
- Il watchdog reagisce anche a `STOP_FLAG` (stop dall'UI): è ciò che rende di
  nuovo efficace il pulsante Stop su una sorgente congelata.
- Rete di sicurezza indipendente: se ffmpeg è ancora vivo oltre
  `DEADLINE + DEADLINE_GRACE` (30s), viene chiuso comunque. Il margine evita di
  ucciderlo mentre sta legittimamente finalizzando il file alla scadenza di `-t`.
- `POLL_SEC` è anche la latenza di reazione allo stop: sommata alla grazia del
  SIGTERM deve restare sotto l'attesa di `stop_recording.sh` (6s), altrimenti
  scatta il suo fallback e si torna ad avere due scrittori sulla stessa riga
  (vincolo 15). Misurato: finalizzazione in 3s.
- `STALLS` e `INTERRUPTIONS` non coincidono e finiscono separati in
  `recordings.notes`: un blocco rilevato a slot ormai esaurito, o che chiude una
  manuale, non produce alcuna ripresa.

**Verificato in sandbox** (DB e directory separate, stream su FIFO interrotta a
metà per simulare il congelamento): manuale chiusa a 100s su slot 180s con la
nota giusta; programmata ripresa e conclusa a 173s su slot 180s; registrazione
sana da 30s invariata (3 segmenti, nessuno stallo, nessun falso positivo); stop
dall'UI su stream congelato finalizzato in 3s.

## Qualità video per sorgente (v2.3.0, 2026-07-27; forbice allargata in v2.4.4, 2026-07-29)

Fino alla 2.2.1 le registrazioni video erano **sempre** stream copy: il peso lo
decideva interamente l'encoder a monte e Fluxus non aveva alcuna leva. Su una
sorgente rtmp reale (720p30, 4,09 Mbps video + 256k AAC) questo significava
**1,96 GB/ora**, cioè ~3,9 GB per uno slot da 2h.

`sources.video_quality` (colonna aggiunta dalla migrazione v3→v4) sceglie il
profilo per singola sorgente. Il `case "$VIDEO_QUALITY"` in record.sh è
l'autorità: la tabella `$fmVideoQualities` in `sources.php` contiene **solo le
etichette UI** e va tenuta allineata a mano.

| video_quality | flag ffmpeg | video | audio | GB/h | SSIM | velocità |
|---|---|---|---|---|---|---|
| `copy` (default) | `-vcodec copy -acodec copy` | come sorgente | come sorgente | come sorgente | — | — |
| `hd`    | `libx264 -preset veryfast -crf 17` + `aac 128k` | ~4,2 Mbps | 128k | ~0,92 | 0,9944 | 1,27x |
| `alta`  | `libx264 -preset veryfast -crf 21` + `aac 128k` | ~2,6 Mbps | 128k | ~0,58 | 0,9923 | 1,31x |
| `media` | `libx264 -preset veryfast -crf 28` + `aac 128k` | ~1,1 Mbps | 128k | ~0,26 | 0,9836 | 1,45x |
| `bassa` | `libx264 -preset veryfast -crf 32` + `aac 96k`  | ~690 kbps | 96k  | ~0,16 | 0,9750 | 1,48x |

Numeri misurati sul Pi 5 su sorgente **1080p30** (2026-07-29): **scalano con la
risoluzione**. Le misure sono state prese con ~1 core già occupato da
un altro processo: sono lo scenario peggiore realistico, non il caso ideale.

### Perché il preset resta `veryfast` su TUTTI i profili (2026-07-29)

È l'unico preset che regge il tempo reale su questo host. Sotto 1,0x ffmpeg non
sta dietro alla diretta e **perde frame**. Misurato a 1080p30, macchina non in
throttling (`throttled=0x0`, 2,4 GHz, 57-71°C), best-of-2:

| preset | velocità | bitrate a crf 21 | SSIM a crf 21 |
|---|---|---|---|
| `veryfast` | **1,78x** ✅ | 2,63 Mbps | 0,99230 |
| `faster`   | 1,21x ⚠️ | 2,92 Mbps | 0,99372 |
| `fast`     | 0,96x ❌ | 3,07 Mbps | 0,99427 |
| `medium`   | 0,77x ❌ (a crf 19) | 3,83 Mbps | 0,99521 |

⚠️ **Misurare solo a macchina scarica.** Una prima tornata di misure con un altro
processo che saturava un core dava valori depressi del ~30%
(veryfast 1,29x, fast 0,70x) e ha portato a conclusioni sbagliate. Controllare
sempre `vcgencmd get_throttled` e il carico prima di decidere.

**A qualità pari il preset lento è più efficiente**, come da teoria: `fast`/crf 21
fa SSIM 0,99427 con 3,07 Mbps, contro `veryfast`/crf 17 a 0,99444 con 4,24 Mbps —
**28% di byte in meno a parità di qualità**. Non lo usiamo solo perché a 0,96x non
sta in tempo reale. Se si libera CPU sulla macchina, `fast` diventa la
scelta migliore per `alta`/`hd`, ed è il primo posto dove tornare a guardare.

Il profilo `hd` nasce da questo vincolo: `veryfast -crf 17` è il modo di comprare
qualità restando sopra il tempo reale, spendendo bit invece di cicli CPU.

### Whitepaper Raspberry Pi sull'encoding H.264 — cosa conferma e cosa no (2026-07-29)

[«H.264 encoding performance on Raspberry Pi 5-series computers»](https://pip-assets.raspberrypi.com/categories/685-app-notes-guides-whitepapers/documents/RP-010033-WP-1-H.264%20encoding%20performance%20on%20Raspberry%20Pi%205_series%20computers.pdf),
Raspberry Pi Ltd, release 1 del 28 apr 2026.

**Conferma** quanto già scritto qui: il Pi 5 non ha encoder H.264 hardware
(`h264_v4l2m2m` è l'encoder del **Pi 4**), libx264 è l'unica strada, distribuisce
il lavoro su tutti i core ed è ottimizzato Neon, e 1080p30 in tempo reale è
fattibile.

⚠️ **Le sue configurazioni non vanno però adottate qui**, ed è stato verificato
sul nostro materiale, non dedotto. Il loro preset "high quality" è
`superfast -bf 1 -x264-params "partitions=i8x8,i4x4:weightp=0:weightb=0:me=dia:
scenecut=0:rc-lookahead=0:mixed-refs=0:merange=16:subme=0"`: un preset veloce a
cui si riattivano CABAC, B-frame e partizioni, tenendo la ricerca di movimento al
minimo. Misurato sul nostro file 1080p30, **con CRF**:

| config | velocità | bitrate | SSIM |
|---|---|---|---|
| nostro `veryfast -crf 21` | 1,78x | **2,63 Mbps** | 0,99230 |
| RPi high-quality `-crf 21` | 2,45x | **6,33 Mbps** | 0,99313 |
| RPi high-quality `-crf 28` | 2,67x | 2,38 Mbps | 0,98716 |
| RPi low-latency `-crf 21` | 2,41x | 9,95 Mbps | 0,99100 |

A bitrate confrontabile (2,63 vs 2,38 Mbps) la nostra config ha SSIM **0,99230
contro 0,98716**: vince nettamente in qualità per byte. Il motivo è che
`subme=0`/`me=dia` producono predizioni di movimento scadenti, e in CRF —
dove il bersaglio è la *qualità* — l'encoder compensa spendendo molti più bit.
Le loro scelte sono ottime per il **loro** obiettivo (cattura da camera, ABR a
bitrate fisso, minimo consumo di CPU per lasciare spazio alla pipeline camera e
all'ISP), che non è il nostro (registrazione su disco, minimizzare i byte a
qualità data). Obiettivo diverso → ottimo diverso.

Altri due punti da non confondere:

- **Le loro percentuali di CPU non sono confrontabili con le nostre.** Il
  whitepaper encoda da **frame YUV grezzi** (`-f rawvideo`), quindi non paga
  alcuna decodifica; noi decodifichiamo uno stream H.264 in ingresso e
  codifichiamo anche l'audio. I loro 60-90% (low latency) e 100-150%
  (high quality) di un core sono il costo del solo encoder.
- **Idea del whitepaper che sarebbe applicabile**: usare ABR/CBR (`-b:v`) invece
  di CRF. Loro misurano un'aderenza al bitrate richiesto entro il 2-3%, il che
  renderebbe le stime GB/h mostrate in `sources.php` **garantite** invece che
  indicative (con CRF il peso dipende dalla complessità della scena). Non è stato
  fatto: cambierebbe il significato dei profili esistenti. Da valutare se un
  giorno servisse un tetto di spazio prevedibile per slot.

⚠️ **Non riproporre libx265/HEVC né AV1.** Misurato sullo stesso file 1080p30:
`libx265 -preset ultrafast` **0,27x**, `superfast` 0,22x, `veryfast` 0,15x —
da 4 a 7 volte troppo lento, con tutti e 4 i core saturi. In più HEVC in MP4
**non si riproduce in modo affidabile nel browser** (Chrome solo con decodifica
hardware, Firefox a macchia di leopardo): i player di `recording.php` e del
modale cue mostrerebbero schermo nero. Restiamo su H.264/libx264.

Regole invarianti:

- **Risoluzione e fps non vengono MAI toccati**: nessun profilo ridimensiona,
  il file esce con la geometria dello stream in ingresso. `resolution`/`fps` in
  `sources` restano parametri di *cattura* del solo ramo v4l2.
- I profili che ricodificano aggiungono `-force_key_frames "expr:gte(t,n_forced*2)"`
  (GOP 2s indipendente dagli fps): tiene la precisione del taglio dei cue, che
  in `extract_clips.sh` restano `-c copy`, e fa cadere i confini dei segmenti su
  keyframe. `-movflags frag_keyframe+...` resta su tutti gli output (vincolo 12).
- Qualunque valore non riconosciuto (vuoto, NULL, stringa ignota) ricade su
  `copy`, cioè sul comportamento storico. Il flag interno `TRANSCODE` distingue
  i profili che ricodificano davvero: `-force_key_frames` non va mai aggiunto a
  uno stream copy.
- **Ramo v4l2**: un device raw non è copiabile. Se la sorgente è su `copy`, il
  ramo v4l2 ricade sul profilo `alta`.
- `extra_opts` viene ora realmente appeso alla riga ffmpeg (video **e** audio),
  dopo i flag di codifica. Prima della 2.3.0 era salvato in DB e ignorato.

**Costo CPU**: a 720p30 una transcodifica costava **~73% di un core** su 4
(`speed=1.03x`, load 0,26, 59°C). A **1080p30** — la risoluzione effettiva della
sorgente in produzione — il margine è molto più stretto: `veryfast` sta fra 1,27x
e 1,48x a seconda del profilo, cioè **una sola transcodifica 1080p usa quasi
tutti i core disponibili**. Non contare più su 3-4 transcodifiche simultanee a
1080p. `speed=` sotto 1.0x nel log significa frame persi: è la soglia critica da
guardare, e a 1080p non è lontana.

**Nessun encoder H.264 hardware sul Pi 5.** Il Pi 5 ha rimosso l'encoder
hardware presente sul Pi 4: `/dev/video19` è un *decoder* HEVC, e
`h264_v4l2m2m`/`h264_vaapi`/`h264_nvenc` compaiono in `ffmpeg -encoders` solo
come wrapper compilati, senza device dietro. L'unica strada è software
(`libx264`). `/etc/fluxus-media.env` conteneva `FM_HW_ENCODER=h264_v4l2m2m`, che
non ha mai potuto funzionare: corretto a `libx264` e **non più letto da
record.sh** (l'encoder ora viene dal profilo). Il file resta solo come default
delle costanti `FM_HW_ENCODER*` di config.php, a loro volta mai usate in PHP.
⚠️ I suoi commenti devono usare `;` e non `#`: `parse_ini_file()` di PHP fallisce
sull'intero file con `#`, e config.php usa `@` quindi l'errore sarebbe silenzioso.

## scripts/extract_clips.sh

Query DB: markers con clip_status='pending' e created_at+WAIT_SEC <= now, dove
WAIT_SEC = POST_ROLL + 10 (vedi sotto — non più fisso a 65s).
JOIN con recordings per ottenere media_type, output_dir, filename_base, source_id.

Lockfile: $FM_TMP/clip_queue.lock (flock -n), esci silenziosamente se locked.

PRE_ROLL e POST_ROLL **non sono più hardcoded** (2026-07-27): letti da
`settings.cue_pre_roll` / `settings.cue_post_roll` a ogni esecuzione dello
script (fallback 30/90 se le chiavi non esistono ancora in DB). Configurabili
in Impostazioni → box "Marker & Cue" (vedi sezione UI: settings.php). Stesso valore
per audio e video, si applica solo ai cue creati/estratti dopo il salvataggio
— non c'è ri-estrazione dei cue già `ready`. CLIP_DURATION = PRE_ROLL + POST_ROLL.

**Soglia di attesa prima dell'estrazione**: prima era fissa (created_at+65s),
tarata sul vecchio post-roll fisso di 90s. Ora che POST_ROLL è configurabile
(fino a 240s), la soglia deve scalare di conseguenza — altrimenti con un
post-roll alto lo script proverebbe a estrarre la clip prima che la coda del
file (i secondi dopo il click) sia stata effettivamente scritta su disco,
producendo clip troncate. Nuova soglia: `WAIT_SEC = POST_ROLL + 10` (margine
di sicurezza fisso di 10s, invariato dal valore di POST_ROLL).

⚠️ Dal 2026-07-28 `START_SEC` **non** si calcola più da `ELAPSED` direttamente,
ma dalla posizione del click nel contenuto (`content_position()`): con una
registrazione che ha buchi i due valori divergono. Vedi "Durata e cue allineati
al contenuto" e vincolo 20 — gli snippet qui sotto restano validi per tutto il
resto (`START_SEC` va letto come "posizione nel contenuto − pre-roll").

### Per marker con media_type='audio'

```bash
START_SEC=$(( POS - PRE_ROLL )); [[ $START_SEC -lt 0 ]] && START_SEC=0
CLIP_OUT="$FM_CLIPS/$SOURCE_ID/${FBASE}_m${MID}.mp3"

# Singolo file:
ffmpeg -nostdin -y -i "$SOURCE_FILE" \
    -af "atrim=start=${START_SEC}:end=$((START_SEC+CLIP_DURATION)),asetpts=PTS-STARTPTS" \
    -c:a libmp3lame -q:a 2 "$CLIP_OUT"

# Segmentato: costruisci concat list come in Audio Recorder 1.0
```
Aggiorna: clip_status='ready', clip_filename.

### Per marker con media_type='video'

```bash
START_SEC=$(( POS - PRE_ROLL )); [[ $START_SEC -lt 0 ]] && START_SEC=0
CLIP_OUT="$FM_CLIPS/$SOURCE_ID/${FBASE}_m${MID}.mp4"

# Singolo file (accurate seek + stream copy):
ffmpeg -nostdin -y -accurate_seek -ss $START_SEC -i "$SOURCE_FILE" \
    -t $CLIP_DURATION -c copy "$CLIP_OUT"

# Segmentato: concat list poi ffmpeg -c copy
```
Aggiorna: clip_status='ready', clip_filename.
Per i video, NON generare clip_trim_filename (EDIT non disponibile per video).

## Fluxus Remote — marker/cue da fuori LAN (v2.1)

Problema: creare marker/cue mentre si è fuori casa, senza aprire porte sul
router né esporre il Pi a Internet. Soluzione: **il Pi non riceve mai
connessioni in ingresso**. Un prodotto satellite separato, **Fluxus
Remote** (repo/deploy indipendente, versionato a parte, v1.0.0), gira su
una VM esterna dell'utente e fa da relay:

1. Ogni 5s `scripts/remote_sync.php` (via `fm-remote-sync.timer`) invia al
   relay, in POST `/api/sync`, `node_name` (da `FM_NODE_NAME`, o hostname
   se non configurato) + l'elenco delle registrazioni con
   `status='recording'` — inviato anche a elenco vuoto, è il modo con cui
   il relay sa che il Pi è vivo pur non registrando.
2. Il relay mostra una pulsantiera (login con password) con un bottone
   MARKER/CUE per ogni registrazione attiva. Ogni click va in una coda con
   il **timestamp preciso del click** (orologio della VM).
3. `remote_sync.php` legge la coda pending, e per ogni voce chiama
   `fmCreateMarker($recordingId, $type, $label, $when, 'remote')`
   (`includes/helpers.php`) usando il timestamp del click per calcolare
   `elapsed_seconds` — la precisione non dipende da quanto il polling è in
   ritardo. Poi conferma (`ack`) gli id processati al relay.
4. `fmCreateMarker()` è la stessa funzione usata da `api/marker.php` per i
   marker locali (estratta da lì): scrive su `markers` e, per le cue,
   anche su `clip_queue.json` — `extract_clips.sh` pesca comunque dalla
   tabella `markers`, quindi le cue remote vengono estratte in automatico
   come quelle locali.

Marker/cue creati da remoto hanno `origin='remote'` e in
`markers_table.php` mostrano un flag (icona `check`, UIkit) in una colonna
dedicata "Remote" tra Tipo e Cue.

### Configurazione (Pi)
- `/etc/fluxus/<istanza>.remote.conf` (root:`<gruppo>`, 640 — file separato da
  quello principale proprio perché contiene una chiave): `FLUXUS_REMOTE_URL`
  (base URL del relay) + `FLUXUS_REMOTE_API_KEY` (token condiviso) +
  `FLUXUS_NODE_NAME` (opzionale, max 60 caratteri, testo semplice — se assente
  si usa l'hostname). Se URL/API key vuoti, la feature è disattivata
  (`remote_sync.php` esce subito, nessuna chiamata di rete). Fino alla 0.0.1
  era `/etc/fluxus-remote.env` con le chiavi `FM_*`, uno per macchina anziché
  uno per istanza.
- `scripts/remote_sync.php`: gira come l'utente di Fluxus via
  `<prefisso>-remote-sync.service`/`.timer` (`OnUnitActiveSec=5s`), stesso
  pattern di `<prefisso>-extract-clips`. Log: `FM_LOGS/fm-remote-sync.log`.
  ⚠️ È l'unico PHP che gira fuori dalla radice web: trova la configurazione
  risalendo da `<cartella dati>/scripts` come gli script bash, e da lì la
  radice web da cui caricare l'applicazione. Prima aveva il percorso
  `/var/www/fluxus-media/includes/db.php` scritto dentro.

### Modello di sicurezza
- Pi → relay: solo outbound HTTPS, header `Authorization: Bearer
  <FM_REMOTE_API_KEY>`. Il Pi non è mai in ascolto su Internet.
- Browser → relay: login password (bcrypt) + HTTPS.
- Compromissione del relay nel caso peggiore: marker/cue falsi in coda —
  nessuna via di rientro verso Pi/LAN, perché il Pi tira i dati, non li
  riceve mai in ingresso.

## scripts/stop_recording.sh

Argomenti: `stop_recording.sh <recording_id>`
1. `touch FM_TMP/stop-{id}` — sentinella letta dal supervisore di record.sh,
   creata **prima** del kill: distingue lo stop voluto da una caduta di rete,
   così il supervisore non riprende la registrazione.
2. Leggi ffmpeg_pid dal DB, `kill $PID 2>/dev/null || true`
3. Attendi fino a ~6s che record.sh finalizzi (status != 'recording').
   **record.sh è l'unico a scrivere l'esito**: solo lui conosce
   `duration_seconds`. Prima scrivevano entrambi e uno perdeva la scrittura per
   SQLITE_BUSY (vincolo 15).
4. Solo se record.sh non ha finalizzato (processo orfano/riavvio), chiudi tu la
   riga con `... WHERE id=? AND status='recording'`, poi rimuovi la sentinella.

## Scheduling systemd

Ogni schedule attivo genera due file in /etc/systemd/system/, creati da
`schedules.php` (non da un `api/schedules.php` dedicato) via `shell_exec`:

`fm-sched-{id}.timer` → OnCalendar={on_calendar}
`fm-sched-{id}.service` → ExecStart=scripts/run_schedule.sh {id}

Al timer scatta, `run_schedule.sh {schedule_id}`:
1. Legge `source_id`, `slot_duration`, `segment_duration`, `active` dallo
   schedule (esce se non attivo) e `name`, `media_type`, `active`,
   `file_prefix` dalla sorgente (esce se non attiva).
2. Costruisce `filename_base` come `{slug}_{YYYY-MM-DD_HH-MM}`, dove `slug`
   è `file_prefix` se impostato, altrimenti il nome sorgente slugificato
   (`iconv` + sostituzione caratteri non alfanumerici con `_`).
3. Inserisce la riga in `recordings` con `status='pending'`.
4. Lancia in background `record.sh {recording_id} {source_id} {slot_duration}
   {segment_duration}`, che poi aggiorna `status='recording'`.

### Log degli avvii programmati — `FM_LOGS/fm-schedule.log` (2026-07-27)

`run_schedule.sh` scrive una riga per ogni invocazione: chi l'ha invocato (pid,
ppid con la sua cmdline, `INVOCATION_ID` di systemd, utente), i parametri dello
schedule, ed **esito esplicito** anche quando non registra nulla (schedule
inesistente, non attivo, sorgente non attiva, INSERT fallita). Prima l'output
finiva solo nel journal del service, che su questo host risulta vuoto.

Ogni riga contiene anche una valutazione `orario:` — `ATTESO` / `FUORI ORARIO` /
`non valutabile` — ottenuta chiedendo a `systemd-analyze calendar` se l'istante
dell'avvio è riconducibile all'`on_calendar` dello schedule (finestra di 180s,
che copre l'`AccuracySec` di systemd, 1 minuto di default). **È puramente
diagnostica: non blocca nulla.**

Motivo: il 2026-07-27 alle 20:52:24 la registrazione 48 (schedule 8) è partita
nell'istante esatto di un `POST /schedules.php`, e non è stato possibile
stabilire chi avesse avviato il service. `LastTriggerUSec` del timer non è una
prova, perché systemd lo aggiorna anche quando l'unit triggerata viene attivata
per altra via. La sequenza di `fmWriteScheduleUnits()` (install unit →
`daemon-reload` → `enable --now` sul timer) **non ha riprodotto l'avvio** in tre
scenari di test (timer nuovo; cambio d'orario a timer già attivo; cambio da
orario futuro a orario passato), quindi la causa resta aperta e il log serve a
non ripartire da zero al prossimo episodio. Ipotesi non verificabile a
posteriori: il timer con l'orario *precedente* scattato dentro la finestra di
`AccuracySec` proprio in quel minuto, con il file .timer poi sovrascritto dal
salvataggio.

⚠️ Un guard che *rifiuti* l'avvio fuori orario è stato valutato e **scartato**
dall'utente per ora. Se lo si riprende: le registrazioni manuali non ne
sarebbero toccate (passano da `api/start.php` → `record.sh`, mai da
`run_schedule.sh`), ma il rischio vero è il falso negativo — una tolleranza
troppo stretta fa **perdere** registrazioni programmate se il timer scatta in
ritardo.

`api/validate_oncalendar.php` valida lato server un'espressione OnCalendar
digitata nel form (via `systemd-analyze calendar`), ritornando la forma
normalizzata e il prossimo elapse, o l'errore di parsing.

## Retention automatica (scripts/retention_cleanup.sh)

Pulizia periodica per sorgente, pilotata da `fm-retention-cleanup.timer`
(systemd, log in `FM_LOGS/fm-retention.log`), lockfile
`$FM_TMP/retention.lock` (flock -n, esce silenziosamente se già in corso).

Per ogni sorgente attiva legge i 4 limiti da `sources` (0 = nessun limite):
- `max_days_recordings`: elimina le registrazioni (file + riga DB, inclusi
  i cue collegati) con `start_time` più vecchio di N giorni.
- `max_recordings`: tiene solo le N registrazioni più recenti (non
  `status='recording'`), elimina le eccedenti più vecchie.
- `max_days_clips`: elimina i cue (`type='cue'`, `clip_status='ready'`) con
  `created_at` più vecchio di N giorni (file clip + eventuale trim + riga
  `markers`).
- `max_clips_per_marker`: tiene solo gli N cue più recenti per sorgente,
  elimina gli eccedenti.

I valori sono configurabili per sorgente nel form di `sources.php` (campi
"Max registrazioni", "Max giorni registrazioni", "Max cue", "Max giorni
cue"), default 30/45/100/20 rispettivamente (vedi schema `sources`).

### Due bug corretti (0.3.2, 2026-08-01)

Trovati collaudando la rotazione dei log della 0.3.1, non a vista — entrambi
già presenti in produzione da tempo.

**1. `SRC_ID` non locale, corrompeva il ciclo per-sorgente.** Dentro
`delete_recording()`, il ciclo che cancella i *file* delle clip di una
registrazione leggeva in una variabile chiamata `SRC_ID` — la stessa del ciclo
per-sorgente più esterno, senza `local`. Alla fine del ciclo, l'ultima `read`
sull'input esaurito svuotava quella variabile, che restava condivisa: dopo la
cancellazione di *qualunque* registrazione, il `SRC_ID` esterno si ritrovava
vuoto, e ogni query successiva nello stesso giro per la stessa sorgente
(`max_days_clips`, `max_clips_per_marker`) falliva in silenzio con
`source_id= AND ...`. La retention dei cue per quella sorgente si fermava lì,
ogni volta. Fix: `local SRC_ID CLIPF TRIMF` prima del ciclo.

**2. `PRAGMA foreign_keys` spenta lato bash, accesa lato PHP.**
`includes/db.php` la accende per ogni connessione PHP — è per questo che
cancellare una registrazione dall'interfaccia fa sparire in cascata anche i
suoi marker (`ON DELETE CASCADE` nello schema). `sqlite3` da riga di comando
parte con quella pragma **spenta**, e `retention_cleanup.sh` non l'accendeva:
la cancellazione automatica toglieva la riga in `recordings` e i *file* delle
clip (li cancella esplicitamente), ma non le *righe* in `markers`, che
restavano orfane — puntavano a una registrazione ormai inesistente. **3 marker
orfani già trovati in produzione** al momento della scoperta (id 25, 53, 54,
tutti `type='marker'`, nessun file coinvolto), lasciati lì: sono innocui, non
compaiono in nessuna pagina perché la registrazione a cui si riferiscono non
esiste più, e non si tocca il database di produzione per una pulizia che può
aspettare. Fix: `-cmd "PRAGMA foreign_keys = ON"` su entrambe le `DELETE`
del bash — bash e PHP tornano a comportarsi allo stesso modo.

## EDIT — Solo audio — **IN QUARANTENA (2026-07-26)**

⚠️ Vedi sezione "TRIM/EDIT manuale — in quarantena" più sotto per lo stato
attuale (disattivato, non eliminato). Quanto segue descrive il design
originale, mantenuto per riferimento in vista di una futura riattivazione.

`edit.php`: lista cue con clip_status='ready' da registrazioni con media_type='audio'.
Identico ad Audio Recorder 1.0 edit.php.

`edit-trim.php`: editor trim audio con WaveSurfer.js 7.12.11, servito da
`assets/vendor/` come tutto il resto (0.2.0) — riattivare la pagina non
reintroduce una dipendenza da Internet.
Identico ad Audio Recorder 1.0 edit-trim.php:
- Tastiera: Space, I, O, ArrowLeft/Right (50ms, Shift=10s), J/K/L velocità
- Pulsante 2x, badge velocità
- Salva: api/trim.php?action=cue (ffmpeg atrim + libmp3lame)
- Salva in clip_trim_filename

I cue VIDEO non compaiono in edit.php. Non c'è EDIT per video.

## markers_table.php — Logica audio vs video

Per cue con clip_status='ready':
- **Audio**: pulsante play (audio element), download originale, download trim
  (se clip_trim_filename già esistente da prima della quarantena — link
  "Ritaglia in EDIT" rimosso, vedi sezione quarantena)
- **Video**: pulsante play (apre modale con <video> element), download originale
  Nessun link EDIT, nessun clip_trim_filename

Per marker (type='marker'): nessun file, nessun pulsante — solo riferimento temporale.

## TRIM/EDIT manuale — in quarantena (2026-07-26)

Su richiesta esplicita, l'intera funzionalità di ritaglio manuale è stata
**disattivata** (non eliminata): l'obiettivo è concentrarsi su altre priorità,
senza sviluppare oltre quest'area per ora. Il codice originale resta nei file,
raggiungibile solo tramite i punti di riattivazione elencati sotto.

**Cosa copre "TRIM/EDIT manuale"**:
1. EDIT (`edit.php`, `edit-trim.php`) — ritaglio dei cue audio con WaveSurfer.js.
2. Estrazione clip manuale in `recording.php` (sia per audio non segmentato
   con marcatori In/Out, sia per audio segmentato con input inizio/fine) —
   tabella `manual_clips`.

**Cosa è stato cambiato**:
- `api/trim.php`: guard subito dopo `fmRequireAuth()` che ritorna sempre
  `503 {"error": "Funzionalità TRIM/EDIT manuale temporaneamente disabilitata"}`
  per qualunque azione (`cue`, `manual`, `manual_list`, DELETE). Il resto del
  file (logica originale) resta sotto, mai raggiunto.
- `edit.php`: la query sui cue e la tabella non vengono più eseguite; la
  pagina mostra un avviso "EDIT — in pausa" e poi `exit` prima del codice
  originale (lasciato intatto più sotto, morto).
- `edit-trim.php`: redirect immediato a `edit.php` subito dopo
  `fmRequireAuth()`, prima di qualunque query sul marker. Codice originale
  lasciato sotto, morto.
- `includes/nav.php`: voce "EDIT" rimossa dall'array `$fmNavItems`.
- `includes/markers_table.php`: rimosso il link "Ritaglia in EDIT" (scissors
  SVG) dalla riga dei cue audio. Il download del trim già generato in
  passato (`clip_trim_filename` esistente) resta invece disponibile: è un
  file statico già pronto, non richiede la funzionalità di ritaglio per
  essere servito.
- `recording.php`:
  - Audio non segmentato: rimossi waveform WaveSurfer, marcatori In/Out,
    pulsante "Estrai clip"; sostituiti con un semplice `<audio controls>`
    per mantenere la riproduzione di base (che altrimenti sarebbe sparita).
  - Audio segmentato: rimosso il pulsante "Usa per estrazione manuale"
    (scissors) da ogni riga segmento e l'intera riga di estrazione manuale
    (input inizio/fine + pulsante "Estrai clip"). Il player `<audio>` e il
    download per segmento restano.
  - Rimossa la sezione/tabella "Clip estratte manualmente" e i due blocchi
    `<script>` (WaveSurfer + estrazione manuale) che la alimentavano.
- La tabella `manual_clips` e la colonna `clip_trim_filename` in `markers`
  restano invariate nello schema DB — nessuna migrazione, solo UI/API disattivate.

**Per riattivare in futuro**: rimuovere i guard/redirect aggiunti in cima a
`api/trim.php` ed `edit-trim.php`, ripristinare la query+tabella in `edit.php`
(codice già presente sotto l'`exit`), i blocchi UI rimossi in `recording.php`
e `includes/markers_table.php`, e la voce `edit.php` in `includes/nav.php`.

## UI: recordings.php — eliminazione multipla (2026-07-27)

Prima colonna di selezione in ogni riga della lista, con "seleziona tutte"
nell'intestazione. La barra azioni (`.fm-bulkbar`) compare da **una** riga
spuntata in su e mostra il conteggio, "Elimina selezionate" e "Annulla
selezione".

- **Stile**: il comando è un **pallino** (`.fm-dot-check`, 13px,
  `appearance:none` + `border-radius:50%`, pieno in `var(--fm-accent)` con anello
  interno bianco), non un checkbox quadrato: in una tabella densa pesa molto
  meno. Proprio perché è piccolo, il feedback vero lo dà la **riga**
  (`.fm-row-selected`: sfondo tenue + barra 3px a sinistra nel colore
  d'accento). Lo stato `:indeterminate` del "seleziona tutte" (trattino
  centrale) distingue "alcune" da "tutte".
- **Le registrazioni in corso non sono selezionabili**: pallino `disabled` con
  tooltip. I loro file sono aperti da ffmpeg e la riga verrebbe comunque
  riscritta alla finalizzazione.
- La cella della selezione ferma la propagazione del click, altrimenti scatterebbe
  l'`onclick` della riga che porta a `recording.php`.
- Conferma via `UIkit.modal.confirm` che **elenca i codici** delle registrazioni
  (V047, A046…): evita l'errore più costoso, cancellare le righe sbagliate
  perché si era perso il conto delle spunte.

`api/recordings.php` (DELETE) accetta ora `?ids=1,2,3` oltre a `?id=N`. La logica
di cancellazione di una singola registrazione (file, segmenti, export marker,
clip dei cue, riga DB) è stata estratta in `fmDeleteRecording(PDO $db, int $id)`,
usata da entrambi i rami — nessuna duplicazione, il singolo si comporta come
prima. Nel ramo multiplo: massimo 200 id per richiesta, le registrazioni
`status='recording'` vengono **saltate** e la risposta distingue
`deleted` / `skipped_running` / `not_found`.

## UI: recording.php (v2.5.2, 2026-07-30)

### Anteprima video in linea, non in un modale

Cliccando una riga della tabella "Video" (un segmento, o il file unico se la
registrazione non è segmentata) il player si apre **nella riga immediatamente
sotto**, a tutta larghezza della tabella. Prima si apriva un modale
(`#fm-rec-video-modal`, rimosso insieme al suo handler `hidden`): con dieci
segmenti dall'aspetto quasi identico, il modale copriva proprio la riga che
diceva quale file si stesse guardando.

- Per ogni riga file c'è una `<tr class="fm-video-player-row" hidden>` con
  `colspan` pari al numero di colonne — **10 se segmentata** (c'è la colonna
  `#`), **9 altrimenti**: il valore sta in `$vColspan`, da aggiornare se si
  aggiunge o toglie una colonna alla tabella.
- **Toggle**: un secondo click sulla stessa riga chiude l'anteprima; aprirne
  un'altra chiude la precedente (un solo player attivo per volta).
- Il `<video>` nasce con `preload="none"` e l'URL in `data-src`: la `src` viene
  assegnata **solo alla prima apertura**. Così aprire la pagina non scarica
  nulla — con dieci segmenti da 2 GB non è un dettaglio — e riaprire un segmento
  già visto riprende dal punto in cui era invece di ripartire da capo (alla
  chiusura si fa `pause()`, non si azzera la `src`).
- Il click sull'icona di download continua a **non** aprire l'anteprima
  (`e.target.closest('.fm-video-dl-btn')`).
- Riga aperta e pannello condividono la barra d'accento a sinistra e lo sfondo
  (`.fm-video-row-open` / `.fm-video-player-row`, in `assets/style.css`), così i
  due blocchi si leggono come un pezzo solo; l'icona play della riga aperta
  passa al colore d'accento.

⚠️ `.fm-video-player-row[hidden] { display: none }` è esplicito nel CSS: su un
`<tr>` la regola `[hidden]` dello user-agent è fragile rispetto alle regole di
`display` delle tabelle.

Il modale dei **cue** in `includes/markers_table.php` non è stato toccato: lì il
contesto è la riga del marker, non un elenco di file quasi identici. `fm-modal-wide`
resta in uso per quello.

### Durata di ogni segmento audio

Nell'elenco dei segmenti audio (`.fm-segplayer-row`) accanto alla dimensione c'è
ora la **durata reale del file**, letta con `fmProbeDuration()` — versione
leggera di `fmProbeVideoFile()` che chiede a ffprobe il solo
`format=duration`. Le registrazioni video l'avevano già in tabella; l'audio no,
e con le riprese dopo una caduta i segmenti **non durano tutti quanto
`segment_duration`** (vedi "Ripresa automatica e robustezza DB"): il valore
dichiarato nella scheda in cima alla pagina non basta più a saperlo.

Costo misurato: un ffprobe per segmento a ogni caricamento della pagina, con
`timeout 5`. Su una registrazione da 2 segmenti MP3 la pagina resta a ~0,41s,
identica a prima — su MP3 la durata si legge dall'header. Se un giorno servisse
per elenchi molto lunghi, la strada è memorizzarla, non toglierla.

### Barra "marker/cue a posteriori" attaccata alla card dei file

Il riquadro non è più una card separata (`uk-card-secondary` con titolo e
paragrafo di spiegazione): è una **barra di una riga in fondo alla card dei
file** — quella dei segmenti video o dei player audio — separata solo da un
filo (`.fm-posthoc-bar`). È lì che si riascolta per ricostruire un marker
perso, e attaccata costa una riga invece di una card intera.

- Il markup è costruito **una volta sola** in `$posthocBox` (output buffering,
  sotto la guardia `!$isRecording`) e stampato in fondo a entrambi i rami, video
  e audio: gli id restano quelli di prima (`fm-posthoc-type`, `fm-posthoc-at`,
  `fm-posthoc-label`, `fm-posthoc-btn`, `fm-posthoc-msg`), quindi il JS non è
  cambiato. ⚠️ Va stampato in **un ramo solo per pagina**: gli id sono unici.
- La spiegazione (registrazione integrale, durata, estrazione automatica delle
  clip) è passata nel **tooltip dell'icona info** accanto al titolo, invece di
  occupare un paragrafo fisso.
- **Il valore predefinito del menù a tendina è `Marker`**, non più `Cue`: è
  l'inserimento più frequente a posteriori, e non produce alcun file.
- Il messaggio d'esito è ora uno `<span>` a piena riga dentro la barra
  (`flex: 1 1 100%`), con gli stessi colori di prima.

### Zona pericolosa in fondo alla pagina

Il riquadro "Zona pericolosa" (Elimina registrazione) non sta più nella colonna
di destra, sotto il box Download, ma **in fondo alla pagina, dopo l'elenco
marker**: è un'azione irreversibile e non deve stare accanto a ciò che si
consulta di continuo. Il pulsante non è più `uk-width-1-1` (a piena larghezza di
colonna aveva un bersaglio enorme per un'azione che non si annulla).

Resta sotto la guardia `!$isRecording` e conserva l'id `fm-btn-delete-recording`:
lo script che lo gestisce vive più in basso nel file, quindi trova comunque
l'elemento.

## Piè di pagina (includes/foot.php)

`foot.php` stampa un `<footer class="fm-footer">` in quest'ordine: `© <anno
corrente>` · `FM_APP_NAME` + versione (`FM_VERSION`) · **`a Fabio Ranfi
solution`** — dicitura esatta, con la `a` iniziale minuscola: è la formula voluta
dall'utente, non un refuso da "correggere". Vale per tutte le pagine che
includono `foot.php`.

**"Fabio Ranfi" è reso con il font *Recursive*, peso 700**, racchiuso in
`<span class="fm-signature">`; il resto della riga usa il font di pagina.

Il font arrivava da Google Fonts, e con esso ogni pagina di Fluxus chiedeva
qualcosa a Internet. **Dalla 0.2.0 è servito da Fluxus**: la regola `@font-face`
sta in cima ad `assets/style.css`, il file è `assets/vendor/fonts/`, e c'è il
solo sottoinsieme latino al peso 700 — 23 KB invece del font variabile completo,
che supera il megabyte. Il ripiego `system-ui` resta dichiarato: se un giorno il
file mancasse, la firma non sparisce, cambia solo forma.

- L'anno viene da `date('Y')`: non va scritto a mano, altrimenti il 1° gennaio
  la pagina mente.
- **`login.php` non include `foot.php`** (è una schermata a sé, senza navbar né
  container): lì la riga di copyright è stampata nel file, sotto il nome del
  nodo, e mostrava già nome e versione sopra il form. Se si cambia la dicitura,
  vanno toccati entrambi i punti.

## UI: dashboard.php

- Header con filtro: [Tutti] [Audio] [Video] (filtra le card live e le ultime registrazioni)
- Card live per ogni recording attivo:
  - Audio: icona microfono, nome sorgente, durata live, badge AUDIO blu
  - Video: icona video-camera, nome sorgente, durata live, badge VIDEO viola
  - Entrambi: pulsanti [M] Marker (uk-button-primary) e [C] Cue (scuro)
  - Tasti M e C da tastiera (come Fluxus 1.0)
- Ultime 10 registrazioni completate (miste audio e video)
- Polling status ogni 5s

### Anteprima live — audio e video (v2.4.1, 2026-07-27)

Pulsante "Anteprima" su ogni card sorgente della dashboard **e** nella barra
comandi di `recording.php` mentre la registrazione è in corso (lì risponde a
"non so cosa stia registrando": apre un flusso separato verso la sorgente e
non tocca la registrazione).

⚠️ **Il meccanismo precedente, basato sul muxer HLS di MediaMTX, non ha mai
funzionato** su nessuna sorgente reale — vedi vincolo 17. Non riproporlo.

**Meccanismo attuale**: relay `ffmpeg` locale → HLS su disco, servito da nginx.

- `scripts/preview.sh <source_id> <media_type> <type> <url> <device>
  <resolution> <fps> <ttl>` — scrive HLS in `FM_TMP/preview/{source_id}/`
  (`index.m3u8` + `seg%05d.ts`, finestra scorrevole di 5 segmenti da 2s con
  `delete_segments`). Video `-c:v copy` (CPU ~0), audio sempre transcodificato
  in AAC perché è l'unico codec audio che ogni browser riproduce dentro HLS.
  Ramo `v4l2`: `libx264 -preset ultrafast -crf 30 -an` (un device raw non è
  copiabile). `-reconnect*` solo per `type=http` (vincoli 1 e 7).
  Scrive `pid` nella dir e fa `exec ffmpeg`, così il pid resta valido.
- `api/preview_start.php` (POST `{source_id}`): uccide un eventuale relay
  precedente sulla stessa sorgente, lancia lo script con `setsid nohup`, e
  attende fino a 20s che compaia una playlist **contenente almeno un `.ts`**
  (una `index.m3u8` vuota fa fallire subito il player). Se il relay muore
  prima, esce subito e allega la coda del log all'errore. Risposta:
  `{ok:true, source_id, media_type, hls_url}`.
- `api/preview_stop.php` (POST `{source_id}`): uccide il relay e cancella la
  dir. Chiamato alla chiusura del modale, anche via `sendBeacon`.
- `includes/preview_modal.php`: modale condiviso fra dashboard e recording,
  espone `window.fmOpenPreview(sourceId, name, mediaType)` e
  `window.fmPreviewActive()`. Spinner UIkit con contatore dei secondi durante
  la connessione, `<video>` per il video e `<audio>` per l'audio, hls.js servito
  da `assets/vendor/` con fallback all'HLS nativo di Safari.

⚠️ **Di hls.js si usa la build `light`** (0.2.0). Rinuncia a DRM, tracce audio
alternative e sottotitoli: questo flusso non ne ha nessuno — `preview.sh`
produce un HLS mpegts a variante singola, un video e un audio — e sono 180 KB in
meno. Se un giorno l'anteprima dovesse servire flussi con più tracce audio,
serve la build completa.

**`hls_url` è un path relativo** (`{FM_WEB_BASE}/preview/{id}/index.m3u8`),
servito da nginx: nessuna porta 8888 da raggiungere, nessun mixed content,
nessun problema di host. Richiede la location in `/etc/nginx/sites-available/default`:

```nginx
location /fluxus-media/preview/ {
    alias /var/lib/fluxus-media/tmp/preview/;
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    types { application/vnd.apple.mpegurl m3u8; video/mp2t ts; }
    default_type application/octet-stream;
}
```

**Ciclo di vita**: il relay ha un TTL di 900s (`-t` di ffmpeg) come rete di
sicurezza se il browser sparisce senza chiudere; `preview_start.php` riavvia
sempre da zero un relay già esistente. Il polling di `dashboard.php` non fa
più `location.reload()` mentre una preview è aperta (la ucciderebbe).

**Costo**: una connessione in più verso la sorgente per ogni preview aperta.
Verificato che registrazione e anteprima convivono senza disturbarsi.

⚠️ Una sorgente **senza publisher attivo** accetta la connessione TCP ma non
manda dati: il relay resta vivo a leggere zero byte e `preview_start.php`
scade a 20s. Non è un limite di connessioni concorrenti — è lo stream spento.
Prima di dare la colpa al codice, usa il pulsante "Check".

### Pulsante "Check" — raggiungibilità sorgente (v2.4.1)

Icona a fulmine su ogni card della dashboard: POST `api/test_source.php` con
`{source_id}` e stampa l'esito sotto la card (verde/rosso), con il riassunto
dei codec (`audio: aac 44kHz · video: h264 1920x1080`) o l'errore di ffprobe.
`test_source.php` accetta ora **sia** `{source_id}` (dashboard, legge la
sorgente dal DB) **sia** `{url, device, type}` (form di `sources.php`, campi
non ancora salvati). Per `type=rtmp-push` non sonda un URL — non ne esiste
uno — ma chiede a MediaMTX se qualcuno sta effettivamente pushando
(`GET /v3/paths/get/{source_id}`, `ready:true`).

## UI: sources.php

Form di aggiunta/modifica sorgente con media_type selector [Audio / Video].
Quando si sceglie Audio: mostra url, type (http|rtmp|rtsp), audio_quality.
Quando si sceglie Video: mostra url, type (http|rtmp|rtsp|srt|v4l2|rtmp-push) e
  il select **"Qualità registrazione"** (`video_quality`), con un box informativo
  sotto che ripete bitrate video/audio e stima GB/h del profilo scelto.
Badge [AUDIO] verde o [VIDEO] viola in ogni riga della lista sorgenti.
La colonna "Qualità" dell'elenco mostra `-q:a N` per l'audio e il nome del
profilo (`copy`/`alta`/`media`/`bassa`) per il video — prima mostrava `—`.

**Campi rimossi/riorganizzati nel modale (v2.3.0)**: i select `video_codec` e
`audio_codec` sono spariti (erano salvati in DB e mai usati da ffmpeg — falsa
aspettativa per l'utente); `resolution` e `fps` hanno ora la classe
`fm-field-v4l2-only` e compaiono **solo** con `type=v4l2`, etichettati
"Risoluzione/FPS di cattura", perché per pull e push la geometria la detta lo
stream in ingresso; `extra_opts` è stato spostato in un `<details>` "Avanzate"
chiuso di default, ed è finalmente onorato da record.sh; il blocco include i
link alla documentazione ffmpeg (opzioni/codec/filtri/protocolli) e qualche
esempio pronto.
La visibilità è gestita da `applyMediaType()`/`applyType()`/`applyQuality()`
nello script inline del file.

Il form include anche:
- `file_prefix` (opzionale, max 12 caratteri, mostrato come chip in tabella):
  se impostato sostituisce il nome sorgente slugificato nel `filename_base`
  generato da `run_schedule.sh`.
- 4 campi di retention automatica (`max_recordings`, `max_days_recordings`,
  `max_clips_per_marker`, `max_days_clips`) — vedi sezione "Retention
  automatica" per la logica di enforcement.

### Modalità sorgente: Pull vs Push (toggle nel modale)

Il form è organizzato attorno a un toggle **"Modalità sorgente"** che precede
Tipo media/Protocollo, per separare concettualmente due casi opposti:

- **Preleva stream esistente (pull)**: Fluxus-Media è client e si collega lui
  stesso a uno stream/device già esistente (`http|rtmp|rtsp|srt|v4l2`).
  L'URL è noto subito, si compila prima di salvare.
- **Ricevi stream in ingresso (push)**: forza `media_type=video` e
  `type=rtmp-push`; nasconde URL/risoluzione/fps/codec (ignorati da record.sh
  per rtmp-push, che usa sempre `-vcodec copy -acodec copy`). L'encoder/
  telecamera esterna deve collegarsi *a* questo Pi.

Il toggle è solo UI: `type` in DB resta lo stesso campo di sempre, il JS
imposta/nasconde i valori (`fm-opt-rtmp-push` è `hidden+disabled` in modalità
pull così non appare nel dropdown, ma resta selezionabile via script).

### URL di ingresso rtmp-push: noto solo dopo il salvataggio

L'URL `rtmp://<ip>:1935/{source_id}` contiene l'ID assegnato da SQLite
AUTOINCREMENT — non esiste finché la riga non è salvata. Per questo:
1. Prima del salvataggio il box mostra un messaggio (non un placeholder
   fittizio tipo `{id}`) che spiega che l'URL apparirà dopo il salvataggio.
2. Dopo save/update di una sorgente `type=rtmp-push`, il redirect passa per
   `sources.php?opened=<id>` — la pagina riapre automaticamente la modale in
   modifica su quella sorgente mostrando l'URL reale con pulsante "Copia",
   senza bisogno di riaprirla manualmente.
3. La tabella elenco sorgenti mostra già `rtmp://<ip>:1935/<id>` nella
   colonna URL per le righe rtmp-push, visibile senza aprire nulla.

### Percorso di ingresso rtmp-push: risolto da MediaMTX (2026-07-30)

Fino alla 2.5.0 Fluxus pretendeva che l'encoder pubblicasse **esattamente** su
`{source_id}`. Molti encoder però hanno **due campi obbligatori** — "indirizzo"
e "stream" — e li concatenano sempre: Wirecast con indirizzo
`rtmp://<ip>:1935/21` e stream `21` pubblica su `21/21`, e non accetta uno
stream vuoto. Risultato: MediaMTX riceveva regolarmente lo stream, ma "Check",
anteprima e registrazione fallivano tutti e tre su un percorso inesistente
(`rtmp://127.0.0.1:1935/21: Input/output error`).

Ora il percorso non si assume, **si chiede a MediaMTX**: `GET /v3/paths/list` e
si cerca la path `ready` che sia `{id}` **oppure** `{id}/<qualunque cosa>`.

| dove | funzione |
|---|---|
| `record.sh` (ramo `rtmp-push`) | `push_input_url()`, dentro `build_cmd` |
| `preview.sh` (ramo `rtmp-push`) | `push_input_url()` |
| `api/test_source.php` | `fmResolvePushPath()` in helpers.php |

Regole:

- **Il nome esatto vince** sull'annidato (l'ordinamento mette `21` prima di
  `21/qualcosa`): se un giorno l'encoder pubblicasse sul percorso canonico, quello
  ha la precedenza.
- ⚠️ **Il confronto sul prefisso include lo slash.** Con `startswith("21")` la
  sorgente 21 catturerebbe anche il push della sorgente 210.
- Se nessuno sta pushando si ricade sul nome canonico: ffmpeg fallisce e in
  `record.sh` è il ciclo supervisore a riprovare quando l'encoder si collega. La
  risoluzione sta **dentro `build_cmd`**, quindi viene rifatta a ogni tentativo.
- `record.sh` scrive nel log `Sorgente push su percorso non canonico: 21/21`, e
  "Check" mostra `· percorso 21/21` accanto ai codec — solo quando il percorso
  non è quello canonico.
- La modale sorgente spiega entrambe le forme (campo unico o indirizzo+stream).

**Verificato sul campo** con Wirecast su `21/21` (720p30 h264 + aac): Check
verde con i codec, anteprima HLS con segmenti reali, registrazione manuale da
40s completata (`rc=0`, tentativi=1, file da 3,6 MB leggibile da ffprobe).

## API di Federazione — **PENDING/FUTURO, non implementata**

⚠️ Nessuno di questi file esiste ancora: né `federation.php`, né alcun
endpoint sotto `api/federation/`, né una voce "Federazione" in navbar. Da
non confondere con **Fluxus Remote** (vedi sezione dedicata), che è la
feature realmente implementata per marker/cue da fuori LAN.

Quello che esiste già in DB, predisposto in vista di questa feature:
tabelle `federation_peers`/`federation_log`, colonna `sources.federated_from`
e `schedules.federated_from`, setting `federation_api_key` (generato
automaticamente al primo avvio da `db_init.php`, mai esposto in UI).

**Design originale non ancora costruito** (mantenuto come riferimento per
una futura implementazione, non descrive nulla di attualmente funzionante):

- Auth: header `X-Federation-Key: <chiave>` su tutti gli endpoint, 401 se
  mancante/errata, chiave da confrontare con `settings.federation_api_key`.
- Endpoint previsti sotto `/api/federation/`: `info.php` (GET, identità
  nodo), `sources.php`/`schedules.php` (GET, elenco active=1),
  `import.php` (POST, importa sources/schedules da un peer),
  `peers.php` (GET/POST/DELETE, gestione peer), `sync.php` (POST,
  sincronizza da un peer), `sync_all.php` (CLI, itera tutti i peer attivi).
- Compatibilità peer Audio Recorder 1.0: se `node_type='audio'` forzare
  `media_type='audio'` sulle sorgenti importate (che non hanno il campo).
- Import: non sovrascrivere sorgenti locali (`federated_from IS NULL`);
  match su `name` per update, insert con `federated_from=peer_id` se nuovo.

**Prima di implementarla**: verificare che sia ancora la priorità richiesta,
non procedere di iniziativa.

## Auth (includes/auth.php)

- fmRequireAuth(), fmLogin($pass), fmLogout(), fmSession(), fmWantsJson()
- Session PHP. Se auth_enabled=0: nessun blocco.
- Login page: login.php

### Sessione dedicata (2026-07-30)

`fmSession()` configura la sessione **solo per Fluxus**, senza toccare il
`php.ini` di sistema né il timer `phpsessionclean`, che restano invariati per
gli altri siti di questo host (vhost condiviso).

| parametro | valore | perché |
|---|---|---|
| `session.save_path` | `FM_SESSIONS` (`FM_BASE/sessions`, 0700 www-data) | vedi sotto |
| `session.gc_maxlifetime` | `FM_SESSION_TTL` = 21600 (6h) | durata di uno show lungo con le sue pause |
| `session.gc_probability/divisor` | 1/100 | con una save_path nostra il GC lo deve fare PHP |
| nome cookie | `FLUXUSSESSID` | un `PHPSESSID` su path `/` sarebbe condiviso con gli altri siti |
| path cookie | `/fluxus-media/` | isolamento dagli altri siti |
| scadenza cookie | riemessa a ogni richiesta | rende la finestra di 6h di **inattività**, non assoluta |

⚠️ **La `save_path` dedicata è la parte che fa il lavoro, non il
`gc_maxlifetime`.** `phpsessionclean.timer` passa ogni 30 minuti sulla save_path
di default (`/var/lib/php/sessions`) e cancella i file più vecchi del
`gc_maxlifetime` **letto dal php.ini**, ignorando qualunque `ini_set()` fatto a
runtime. Con i file lì dentro, alzare il TTL da codice non serve a niente: passa
phpsessionclean e li cancella lo stesso. Spostandoli in `FM_SESSIONS` restano
fuori dalla sua vista.

⚠️ Niente flag `secure` sul cookie: in LAN si va in HTTP e il cookie non
verrebbe mai inviato.

### 401 JSON invece del redirect (2026-07-30)

`fmRequireAuth()` distingue ora, via `fmWantsJson()` (URL sotto `/api/`, header
`Accept: application/json` o `X-Requested-With`), fra pagina e chiamata API:

- **pagina** → `302` a `login.php`, come sempre;
- **API** → `401` con `{"ok":false,"session_expired":true}`.

Prima rispondeva `302` **anche alle API**: il frontend faceva `r.json()`
sull'HTML della pagina di login, otteneva un'eccezione di parsing e la ingoiava
in un `catch` vuoto. Vedi la sezione seguente.

## Marker/cue: niente più perdite silenziose (2026-07-30)

Il difetto stava in `includes/marker_modal.php`: `doSave()` **chiudeva il modale
prima di conoscere l'esito** e terminava con `.catch(function () {})`. Qualunque
fallimento — sessione scaduta, rete, 500, risposta non JSON — era quindi
**indistinguibile da un salvataggio riuscito**: l'operatore vedeva il modale
sparire e continuava a lavorare credendo di aver marcato.

⚠️ Il difetto **non dipendeva dalla sessione**: su questo host `auth_enabled=0`,
quindi nessun 401 è mai stato emesso e i marker persi non possono essere stati
causati dalla scadenza della sessione. Il `catch` vuoto però perdeva in silenzio
anche un errore di rete o un 500. Le due cose sono state corrette insieme.

Come funziona adesso:

1. **`window.fmApiPost()`** (in `includes/session_modal.php`) è l'unica via per i
   POST JSON: classifica l'errore in `session` / `network` / `server` e non ne
   ingoia nessuno. Una risposta non-JSON è un errore esplicito, non un silenzio.
2. **Il modale resta aperto finché il marker non esiste davvero.** In caso di
   errore di rete o server mostra il motivo in chiaro e il pulsante Salva
   ritenta, con l'etichetta digitata ancora lì.
3. **Sessione scaduta** → avviso bloccante e persistente (non un toast):
   `fmSessionExpired()` dice esattamente cosa non è stato salvato (tipo,
   etichetta, ora del click), spiega che la registrazione **non** si è
   interrotta, e offre "Rifai login" (finestra separata, la pagina non si
   ricarica e il marker in sospeso non si perde) più "Riprova salvataggio", che
   si sblocca da solo appena `api/ping.php` torna 200.
4. **`clicked_at`**: il modale manda l'istante in cui è stato **premuto il
   pulsante**, non quello in cui parte il salvataggio. Così gli 8s
   dell'autosalvataggio — e qualunque ritardo per errori e ritentativi — non
   finiscono più dentro la posizione del cue. Stessa logica già usata da Fluxus
   Remote. Il server accetta l'istante solo se plausibile (dentro la
   registrazione, non nel futuro): un orologio del client sballato non deve
   poter spostare il marker dove gli pare.

### Keep-alive

`api/ping.php` è una richiesta leggera che riscrive il file di sessione e ne
aggiorna l'`mtime`, su cui si basa il GC. `window.fmStartKeepAlive(300000)` la
chiama ogni 5 minuti da `dashboard.php` e da `recording.php` (lì solo con una
registrazione in corso). Una sessione con la pagina aperta quindi non scade; una
davvero abbandonata scade come prima. Se il ping torna 401, l'avviso compare
**subito**, prima che l'operatore prema Marker e perda un cue.

⚠️ La chiamata a `fmStartKeepAlive` va dentro `DOMContentLoaded`:
`session_modal.php` è incluso in fondo alla pagina, e allo script della
dashboard la funzione non è ancora definita (stessa trappola del box volumi).

### Marker/cue a posteriori su registrazioni concluse

Rete di sicurezza, non sostituto: i media sono registrati per intero a
prescindere dai marker, quindi un cue perso si ricostruisce riascoltando.

In `recording.php`, **solo per le registrazioni non in corso**, c'è la barra
"Marker/cue a posteriori": tipo (predefinito **Marker**), istante (`h:mm:ss`,
`mm:ss` o secondi), etichetta. `api/marker.php` accetta `elapsed_seconds` e valida
contro `duration_seconds`. Dalla 2.5.2 la barra è attaccata in fondo alla card dei
file invece di essere una card a sé — vedi "UI: recording.php".

- I cue creati così **vengono estratti normalmente** da `extract_clips.sh`, che
  non filtra per `recordings.status` — verificato: clip da 150,02s (60 pre + 90
  post) su una registrazione conclusa da ore.
- Su una registrazione conclusa un marker **senza** istante esplicito viene
  rifiutato: "adesso" darebbe una posizione pari al tempo passato dall'inizio
  dello slot, cioè fuori scala. Prima veniva accettato e scriveva quel valore.

## Numerazione delle registrazioni

Il codice mostrato in UI è `fmRecCode()`: `A`/`V` per media_type + **l'id del DB
a tre cifre** (`A015`, `V048`). Non è un contatore separato: è
`recordings.id`, quindi dipende dall'AUTOINCREMENT di SQLite.

⚠️ **Mai inserire righe di prova nel DB di produzione con id espliciti fuori
scala.** `sqlite_sequence` non torna mai indietro: dopo un test con id 90001 le
registrazioni vere proseguono da 90002 e il codice diventa `V90002`. È successo
davvero — le verifiche in sandbox del 28-30 luglio hanno lasciato la sequenza a
90003 e poi a 99002, e le registrazioni reali dalla 48 in poi sono nate con
codici a cinque cifre. Le sandbox devono usare **un file DB separato**.

Rinumerazione fatta il 2026-07-30 (transazione unica, backup in
`fluxus_media.db.bak-20260730-renumber`): `90004…99006` → `48…57`, con
`markers.recording_id` e `manual_clips.recording_id` riallineati, i due marker
con id inquinato (90003/90004 → 55/56, nessun file li referenziava) e
`sqlite_sequence` riportata al massimo reale. I log `fm-record-{id}.log` sono
stati rinominati di conseguenza. Nessun file media contiene l'id della
registrazione — `filename_base` è prefisso+data e le clip usano l'id del marker
— quindi non c'era nulla da rinominare sui media.

## nav.php

UIkit 3 navbar identica a Fluxus 1.0 (stesso system bar CPU/RAM/Disco,
poll ogni 10s su api/system.php, con orologio locale live nel timezone di
`fmTimezone()`).
Logo: icons/fluxus-32.png + `FM_APP_NAME` ("Fluxus", non "Fluxus-Media") +
badge versione dinamico `v<?= FM_VERSION ?>` (non un valore fisso).
Voci in `$fmNavItems`: Dashboard · Sorgenti · Orari · Registrazioni.
"Impostazioni" è un'icona a parte nella navbar destra (non è nell'array
`$fmNavItems`). Nessuna voce "Federazione": la feature non è implementata,
vedi "API di Federazione" (PENDING/FUTURO).
EDIT rimosso dalla navbar (2026-07-26): TRIM/EDIT manuale in quarantena, vedi sezione dedicata.

## Stile UI

- UIkit 3.21.6, servito da `assets/vendor/` — vedi la sezione seguente
- Dark mode: <html class="uk-dark" style="background:#0d0f18">
- Badge AUDIO: background #1565c0 (blu), testo bianco, font-size 9px
- Badge VIDEO: background #6a1b9a (viola), testo bianco, font-size 9px
- Marker: uk-button-primary (blu)
- Cue: uk-button-default sfondo #1a1a1a bordo #444 testo #e0e0e0
- Favicon: icons/fluxus-32.png, fluxus-64.png, fluxus-180.png

## Dipendenze dell'interfaccia (0.2.0, 2026-07-31)

Fino alla 0.1.0 ogni pagina chiedeva sei file a tre CDN diverse: stile,
JavaScript e icone di UIkit, Font Awesome, il font della firma, il player
dell'anteprima. Su una macchina senza connessione arrivava una pagina senza
stile e senza icone — che è esattamente la condizione in cui si trova un Pi
appena portato in un posto nuovo, cioè quando servirà la pagina di rete della
fase 4. Ora li serve Fluxus, da `app/assets/vendor/`.

### Perché versionati e non scaricati dall'installer

Sono nel repository, circa 950 KB. L'alternativa — scaricarli durante
l'installazione — è stata scartata: installare non deve dipendere dal fatto che
una CDN sia raggiungibile in quel momento, l'immagine SD della fase 6 deve
funzionare appena accesa, e una fase che si dichiara «funziona senza Internet»
non può avere un passo che Internet lo richiede.

`packaging/vendor-assets.sh` tiene l'elenco di versioni, indirizzi e impronte
`sha256` e rifà la cartella (`--check` la verifica soltanto). **Non fa parte
dell'installazione**: serve solo per aggiornare.

### Cosa c'è, e le scelte che non sono ovvie

| | Perché così |
|---|---|
| UIkit 3.21.6 | stile, JavaScript, icone: la stessa versione di prima |
| hls.js 1.6.16 **build `light`** | l'anteprima è a variante singola, vedi la sezione dedicata: 180 KB in meno |
| wavesurfer.js 7.12.11 | incluso benché `edit-trim.php` sia in quarantena |
| Recursive 700, solo latino | 23 KB invece di oltre 1 MB del font variabile |

**wavesurfer c'è anche se la pagina che lo usa non è raggiungibile.** Il motivo
non è la completezza: è che «nessuna risorsa esterna» resta così una proprietà
verificabile in un colpo solo, e riattivare quella pagina un domani non
reintroduce di nascosto una chiamata a Internet.

### Nomi con la versione dentro

`uikit-3.21.6.min.css`, non `uikit.min.css`. Il contenuto servito a un certo
indirizzo non cambia mai, quindi nginx li dichiara `immutable` con scadenza a un
anno e un aggiornamento non può essere scavalcato dalla cache del browser.
Cambiare versione vuol dire cambiare il nome del file **e** i riferimenti nelle
pagine che lo caricano: `includes/head.php`, `includes/head_dark.php`,
`includes/foot.php`, `includes/preview_modal.php`, `login.php`, `edit-trim.php`.

### La verifica

Che non sia rientrato niente dall'esterno si controlla così:

```bash
grep -rnE '<(link|script)[^>]+(src|href)="https?://|@import[^;]*https?://|url\(["'"'"']?https?://' app/
```

⚠️ Un grep di tutti gli `https://` non va bene: restano — legittimi — i link
`<a href>` alla documentazione di ffmpeg in `sources.php` e a quella di systemd
in `schedules.php`. Quelli li apre l'utente in una scheda nuova, non sono
risorse della pagina.

## Inizializzazione DB e migrazioni (includes/db_init.php)

Incluso da `config.php` dopo `helpers.php` (non è più inline in
`config.php`). Due fasi eseguite a ogni request:

**1. Auto-init al primo avvio** (se `FM_DB` non esiste, o esiste ma manca
la chiave `node_id` in `settings`):
```php
foreach ([FM_BASE, dirname(FM_DB), FM_RECORDINGS, FM_CLIPS, FM_TMP, FM_SCRIPTS, FM_LOGS] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
}
$db = new PDO('sqlite:' . FM_DB);
$db->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
$db->exec("INSERT OR IGNORE INTO settings VALUES ('node_id', '" . fmGenerateUUID() . "')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('node_name', '" . FM_APP_NAME . "')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('timezone', 'Europe/Rome')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('auth_enabled', '0')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('password_hash', '')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('web_base', '')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('federation_api_key', '" . bin2hex(random_bytes(24)) . "')");
$db->exec("INSERT OR IGNORE INTO settings VALUES ('schema_version', '2')");
chmod(FM_DB, 0664);
```

**2. Migrazioni incrementali** per DB già esistenti (basate su
`settings.schema_version`, corrente = 4), applicate una sola volta ciascuna:
- v1→v2: aggiunge a `sources` le colonne `file_prefix`, `max_recordings`
  (default 30), `max_days_recordings` (default 45), `max_clips_per_marker`
  (default 100), `max_days_clips` (default 20) — retrocompatibili con
  `ALTER TABLE ... ADD COLUMN`, senza perdita dati.
- v2→v3: aggiunge a `markers` la colonna `origin` (default `'local'`).
- v3→v4: aggiunge a `sources` la colonna `video_quality` (default `'copy'`), che
  attiva i profili di qualità video. Il default preserva il comportamento
  storico su tutte le sorgenti già esistenti.
- v4→v5: archiviazione su più volumi — crea `storage_volumes` con la riga del
  volume interno, aggiunge `sources.storage_volume_id` e `recordings.clips_dir`
  (entrambe NULL su tutto l'esistente, cioè comportamento invariato) e le
  settings `storage_volume_audio`/`storage_volume_video` a 1.

Eventuali migrazioni future vanno aggiunte come nuovo blocco
`if ($cur < N)` in `db_init.php`, mai riscrivendo `schema.sql` per i DB già
in produzione (solo per install nuove).

## Archiviazione su più volumi (v2.5.0, 2026-07-30)

Fino alla 2.4.4 tutto stava su `FM_BASE` (microSD da 58 GB, 36 liberi): con il
video in `copy` a ~2 GB/h la scheda si riempiva in poche decine di ore. Ora si
sceglie da UI **su quale disco** salvare, con destinazioni distinte per audio e
per video.

**Il disco esterno di questo host**: `/dev/sda1`, ext4 (`-m 1`), etichetta
`DISCO-ESTERNO`, montato da `/etc/fstab` **per UUID** su `/mnt/disco-esterno` con
`nofail,x-systemd.device-timeout=10`. Radice dati `/mnt/disco-esterno/fluxus`,
proprietaria `www-data`. ⚠️ `nofail` non è opzionale: senza, il Pi non completa
il boot quando il disco è scollegato. Era FAT32 con 45 GB di dati (spostati
altrove dall'utente prima della formattazione) e montato dall'automount desktop
come utente `pi`: illeggibile per `www-data`, con il limite di 4 GB per file che
uno slot video da 2h sfiorava.

### Modello

- `storage_volumes(id, label, path, is_default, active)` — la riga `id=1` è il
  volume interno (`path = FM_BASE`, `is_default=1`) e non è eliminabile.
- `settings.storage_volume_audio` / `storage_volume_video` — destinazione
  predefinita per tipo media.
- `sources.storage_volume_id` — override per singola sorgente (NULL = default).
- `recordings.clips_dir` — cartella dei cue **persistita per registrazione**,
  accanto a `output_dir` che già esisteva. NULL sulle righe anteriori alla
  2.5.0: per quelle vale il path storico `FM_CLIPS/{source_id}`.
- `settings.storage_volume_order` — ordine dei volumi deciso trascinando le
  righe in Impostazioni (lista di mount point, uno per riga). Vale anche per la
  barra di stato: è lo stesso elenco.

Ogni volume replica `recordings/{source_id}/` e `clips/{source_id}/` sotto la
propria radice. Sul volume interno la radice resta `FM_BASE`, quindi **i path
storici non cambiano di un byte**.

### Il file sentinella

Un volume esterno è considerato collegato **solo se contiene `.fluxus-volume`**
nella sua radice (`fmVolumeOnline()` in helpers.php, `path_available()` in
retention_cleanup.sh, test `-f` in run_schedule.sh). Non basta `is_dir()`:
quando il disco si scollega resta la **directory vuota del mount point**, e
Fluxus scriverebbe in silenzio sulla microSD credendo di scrivere sull'esterno.

### Chi risolve il percorso

- `fmResolveStorage($source)` (PHP) e il blocco equivalente in
  `run_schedule.sh` (bash) scelgono il volume e creano le directory.
  **Se il volume non è collegato o non è scrivibile si ripiega sul volume
  interno**, scrivendo il motivo in `recordings.notes` e nel log: una diretta
  programmata non si perde perché qualcuno ha urtato il cavo USB (scelta
  esplicita dell'utente).
- **`record.sh` non è stato toccato**: legge già `output_dir` dal DB e non usa
  mai `FM_CLIPS`. Nessuna nuova eccezione al vincolo 1.
- In lettura, la cartella dei cue si prende **sempre dalla registrazione**
  (`fmRecordingClipsDir()`, colonna `clips_dir` nelle query bash), mai
  ricalcolandola da `FM_CLIPS`.

### Come i file arrivano al browser

I media su volume esterno non sono sotto la webroot di nginx. In
`FM_BASE/volumes/{volume_id}` c'è un **symlink** alla radice del volume
(`fmEnsureVolumeLink()`), servito dalla location `/fluxus-media/volumes/`: una
sola location statica, e ogni disco nuovo funziona senza toccare nginx.
`fmMediaBaseUrl($rec)` sceglie l'URL storico o quello del symlink.

```nginx
location /fluxus-media/volumes/ {
    alias /var/lib/fluxus-media/volumes/;
    add_header Accept-Ranges bytes;
}
```

### Cosa resta sempre sul volume interno

Il DB e **gli export marker CSV/TXT/JSON**: sono informazioni, non registrazioni,
e devono restare leggibili con il disco scollegato (richiesta esplicita
dell'utente). `fmGenerateMarkersFiles()` scrive perciò in
`FM_RECORDINGS/{source_id}/` anche quando i media stanno altrove — e
`fmDeleteRecording()` li cerca in entrambe le posizioni.

L'avviso "ci sono registrazioni su dischi non collegati" compare **solo se su
quel volume esistono davvero delle registrazioni** (`fmVolumeRecordingCount()`,
che conta per prefisso di `output_dir`), e ne indica il numero: un disco
scollegato e vuoto non è un problema da segnalare. ⚠️ Il conteggio usa `substr`,
non `LIKE`: negli underscore dei path (`/mnt/USB_ESTERNO`) `LIKE` vedrebbe un jolly
e conterebbe anche volumi diversi.

### Comportamento con disco scollegato

- `recording.php` **si apre normalmente**: marker, orari, durata e note stanno
  nel DB. In cima compare un avviso con il **nome esatto del volume** e il path,
  e al posto dei player si legge che i file sono sul volume non collegato.
- `recordings.php` mostra un'icona di avviso al posto della dimensione (che
  sarebbe "—" senza spiegazione).
- La retention **salta** quelle registrazioni invece di cancellarne le righe:
  altrimenti i file tornerebbero orfani al ritorno del disco.

### UI

**Impostazioni → Archiviazione**: una **card per disco realmente montato**
(`fmDetectedVolumes()` legge `/proc/mounts` e le etichette da
`/dev/disk/by-label`), con nome reale del volume, device, filesystem e spazio.

Ogni card ha **due caselle tratteggiate**, una per `AUDIO` e una per `VIDEO`,
fra l'icona e il nome del volume. La destinazione si sceglie **trascinandoci
dentro il tag** (gli stessi badge usati in tutta la UI) **oppure cliccando una
casella vuota**, che ci porta dentro il tag di quel tipo da dove si trovava —
più rapido del drag e usabile da telefono. Le due caselle hanno **gruppi
sortable distinti** (`fm-tag-audio`, `fm-tag-video`), così il tag audio non può
finire nella casella video. Il segnaposto è `:empty::after` in CSS, non un nodo
DOM: un elemento dentro la casella verrebbe trattato dal sortable come
trascinabile.

Anche l'ordine delle card si cambia trascinando, dalla maniglia. **Ogni
movimento salva da solo** via `api/volume_order.php` — non c'è alcun pulsante
Salva.

**Togliere e ritrovare i volumi**: la × a destra di una card non abilitata o
scollegata la toglie dall'elenco, scrivendone le chiavi (mount point *e* radice
dati, così non riappare cambiando stato) in `settings.storage_volume_hidden`.
**Nessun file viene cancellato e il disco resta montato**: sparisce solo dalla
vista, qui e in barra di stato.

Il pulsante **rileva dischi** (icona refresh sopra l'elenco) toglie dai nascosti
**solo le chiavi dei dischi realmente presenti** in quel momento. Ne segue la
regola che conta:

- volume **collegato** tolto dall'elenco → il refresh lo riporta;
- volume **scollegato** tolto dall'elenco → il refresh **non** lo riporta (non
  c'è nulla da rilevare); ricompare da sé quando lo si ricollega al Pi, perché a
  quel punto è di nuovo in `/proc/mounts`.

⚠️ **La riga in `storage_volumes` non va mai disattivata** per nascondere un
volume, anche se scollegato: le registrazioni fatte su quel disco la usano per
costruire l'URL dei player (`fmMediaBaseUrl()`) e per il controllo
anti-traversal dei download (`fmClipRoots()`). Con `active=0`, al ritorno del
disco i player punterebbero al percorso storico sbagliato e i download
risponderebbero 404. Si nasconde e basta.

Non si possono nascondere il volume interno né quelli che sono **destinazione
corrente** di audio o video: prima va spostato il tag, altrimenti sparirebbe
dalla vista il disco su cui si sta registrando. A sinistra di ogni card un **cerchio con l'icona del disco** (Font
Awesome 4: `fa-hdd-o` interno, `fa-usb` esterno) il cui sfondo segue i tag
presenti: blu audio (#1e87f0), viola video (#a855c9), **metà e metà se
entrambi**, grigio se il volume non riceve nulla.

Due trappole già pagate, da non ripetere:

- ⚠️ **Le card sono `div`, non righe di tabella**: `uk-sortable` non funziona su
  `<tbody>`/`<tr>`, il drag non parte proprio. Con la tabella sembrava che fosse
  il *salvataggio* a non funzionare, mentre non partiva il trascinamento.
- ⚠️ **Lo script del box deve girare dentro `DOMContentLoaded`**: `foot.php`
  carica `uikit.min.js` in fondo al body, quindi al punto in cui il box è
  scritto `UIkit` non esiste ancora e un `UIkit.util.on()` diretto muore con
  "UIkit is not defined", portandosi via drag&drop e colori.
- Lo stato inviato al server si **rilegge sempre dal DOM** (ordine delle card +
  card che contiene ciascun tag), mai dai dettagli dell'evento: UIkit emette
  `moved` per lo spostamento interno a una lista ma `added`/`removed` per quello
  fra liste diverse, e `detail[1]` è il *placeholder*. Un debounce di 150 ms
  unisce la coppia added+removed in una sola chiamata.

⚠️ **Le due icone dei dischi sono disegnate in linea**, non prese da un font di
icone (0.2.0). Prima erano `fa-hdd-o` e `fa-usb` di Font Awesome 4.7, caricato
da CDN in `head.php` perché UIkit 3 quelle icone non le ha: 106 KB fra foglio di
stile e font per due soli glifi, e assenti proprio sulla macchina senza
connessione. Ora le fa `fmVolumeIcon(bool $interno)` in `includes/helpers.php`,
in stile Feather (24×24, tratto 2, `currentColor`), e la misura la dà `.fm-icon`
in `style.css` — larga 1em, così le regole che dimensionavano le icone con
`font-size` valgono ancora com'erano.

Usata da `settings.php` e, passando da `json_encode`, dal JavaScript della barra
di stato in `nav.php`: **un disegno solo, in un posto solo.** Se si tocca, si
tocca lì.

⚠️ Il connettore della chiavetta va tenuto ad angoli vivi: arrotondandolo la
figura diventa un lucchetto. Provato.

**Barra di stato**: **fino a due volumi si vedono affiancati** direttamente in
barra (nome, barra, percentuale, GB); **da tre in su** resta solo il primo,
seguito dai **puntini di sospensione** e dalla freccetta che apre la tendina con
l'elenco completo. Stesse icone e stesso ordine di Impostazioni, i non collegati
in grigio. `api/system.php` mantiene i campi piatti `disk_*` (riferiti al primo
volume) per non rompere eventuali consumatori esterni, ma la barra non li usa
più.

### Abilitare un disco dalla UI

⚠️ **Dischi automontati dal desktop**: udisks li monta sotto
`/media/<utente>/<label>`, e `/media/<utente>` è `drwxr-x---` root:root con ACL per
`pi` — `www-data` non può nemmeno attraversarla, quindi `disk_total_space()`
restituisce 0 e nessuna scrittura è possibile. È il motivo per cui `DISCO-ESTERNO` è
in fstab su `/mnt` e non lasciato all'automount.

Dalla 2.5.0 non serve più intervenire a mano: la card di un disco non
utilizzabile mostra il pulsante **"Abilita"** al posto delle due caselle dei tag
(che lì non servirebbero), con la **stessa larghezza complessiva** — 164px, cioè
76+12+76 — perché le colonne restino allineate fra le card. Il pulsante chiama
`api/volume_enable.php` →
`sudo /usr/local/bin/fluxus-enable-volume.sh <UUID>`. Lo script (root) stacca il
disco dall'automount, aggiunge la voce in `/etc/fstab` **per UUID** con
`nofail`, lo monta sotto `/mnt/<label-slug>`, crea `fluxus-media/{recordings,clips}`
e la sentinella, e **verifica in chiusura che www-data ci scriva davvero**. Per i
filesystem senza permessi POSIX (vfat/exfat/ntfs) monta con `uid=33,gid=33,umask=002`,
perché lì `chown` non ha effetto. Fa un backup di `/etc/fstab` a ogni esecuzione.

⚠️ **Lo script deve restare `root:root 0755` in `/usr/local/bin`**, mai in
`/var/lib/fluxus-media/scripts` che appartiene a `www-data`: lì `www-data`
potrebbe riscriverlo e, con la regola `NOPASSWD`, ottenere root. La regola sta
in `/etc/sudoers.d/fluxus-media` accanto a quelle già esistenti per gli unit
systemd. L'unico argomento che arriva allo script è un UUID ricavato **dal
server** a partire da `/proc/mounts`, mai passato dal client.

Il sottocomando `--info <UUID>` dello stesso script legge dimensione e spazio
libero di un disco che `www-data` non riesce a interrogare, così **lo spazio
reale è sempre mostrato** anche prima di abilitarlo.

**La conferma dice sempre che fine fanno i dati**, perché è la prima domanda che
si fa chi preme quel pulsante:

- filesystem montabile così com'è (il caso normale) → `UIkit.modal.confirm` con
  un riquadro `uk-alert-success`: quanti GB ci sono già sul disco e che **non
  vengono toccati**, perché Fluxus monta e basta, non formatta;
- filesystem che richiederebbe la formattazione → **nessuna conferma, solo un
  `UIkit.modal.alert`** con riquadro `uk-alert-danger` che avverte che
  formattare cancellerebbe tutto e che Fluxus non lo fa: va fatto a mano, dopo
  aver messo al sicuro il contenuto.

Due trappole già pagate qui:

- ⚠️ **Mai `blkid` da www-data**: legge il device (`brw-rw---- root:disk`) e
  restituisce **vuoto senza errore**, facendo fallire in silenzio tutto ciò che
  dipende dall'UUID. Si usa `fmDeviceUUID()`, che risolve i symlink di
  `/dev/disk/by-uuid` (leggibili da chiunque).
- ⚠️ **`findmnt -r` fa l'escaping degli spazi** (`\x20`): su un mount come
  `/media/utente/USB DISK` il path arrivava a `df` malformato e lo spazio libero
  risultava 0. Serve `findmnt -l`.

**Sorgenti**: select "Volume di archiviazione", prima opzione "Predefinito per
tipo media".

### Verificato sul campo (2026-07-30)

Registrazione audio manuale sul disco esterno con `output_dir`/`clips_dir`
corretti; cue estratto in `/mnt/disco-esterno/fluxus/clips/13/` e scaricato
(200, 1,3 MB); download di un cue **storico** con `clips_dir` NULL ancora
funzionante; fallback sul volume interno con la nota giusta sia da
`api/start.php` sia da `run_schedule.sh`, a volume smontato; `recording.php`
apribile a disco staccato con l'avviso e il nome del volume; ordine manuale
rispettato dalla barra di stato; retention in sandbox nei tre casi (volume
offline → salta, online → cancella file e riga, `clips_dir` NULL → path storico).

## Sorgente CLOCK — marker senza flusso (0.3.3, 2026-08-03)

Fino alla 0.3.2 un marker esisteva solo dentro una `recordings` reale, che a
sua volta esisteva solo se c'era una sorgente audio/video con un flusso da
registrare. Il tipo di sorgente **CLOCK** (`sources.media_type='clock'`)
copre il caso di voler annotare eventi con orario reale **senza alcuna
diretta in corso** — nessun flusso, nessun file audio/video, nessun Cue (non
c'è nulla da tagliare), ma **Avvio/Ferma producono comunque una riga vera in
`recordings`**, visibile in `recordings.php` e apribile in `recording.php`
con inizio/fine/durata reali ed export CSV/TXT/JSON dei marker. L'avvio è
**solo manuale** (come le altre sorgenti): nessuna integrazione con
Orari/systemd.

Il pezzo chiave: una registrazione CLOCK **non è un caso speciale** se il suo
`output_dir` è il path storico del volume interno, `FM_RECORDINGS/{source_id}`
(invece di lasciarlo vuoto o inventarne uno fittizio — la stessa cartella
"canonica" che userebbe una registrazione normale di quella sorgente sul
volume interno, anche se lì non scrive mai nessuno). Con quel path,
`fmRecordingVolumeOffline()`/`fmRecordingVolume()`/`fmMediaBaseUrl()`
(helpers.php) la riconoscono automaticamente come "volume interno" senza
alcuna guardia dedicata, e `fmDeleteRecording()`/`fmRecordingSize()`/
`retention_cleanup.sh` fanno `glob()`/`rm -f` su pattern che semplicemente non
trovano nulla — **nessuna modifica** a questi punti.

- `api/start.php`: per `media_type='clock'` salta `fmResolveStorage()` (path
  storico diretto, nessuna cartella creata su disco) e **non lancia
  `record.sh`** — nessun processo ffmpeg, mai. `record.sh` resta quindi sotto
  il vincolo "non toccare dopo la creazione iniziale" senza bisogno di alcuna
  eccezione.
- `api/stop.php`: per una registrazione clock **non chiama
  `stop_recording.sh`** (pensato per aspettare fino a 6s che un processo
  ffmpeg finalizzi, cosa che qui non accade mai) — finalizza subito con una
  singola `UPDATE ... status='completed', end_time, duration_seconds`,
  risposta immediata.
- `api/marker.php`: guardia server-side — `type='cue'` su una registrazione
  clock è rifiutato esplicitamente (mai una whitelist client-side da sola:
  bottone disabilitato e scorciatoia da tastiera C sono solo comodità).
- `sources.php`: terzo bottone "CLOCK (nessun flusso)" nel toggle "Modalità
  sorgente" (stesso pattern di Push), che nasconde protocollo/URL/device/
  qualità/volume di archiviazione/retention cue e forza `media_type='clock'`,
  `type='clock'`, `url`/`device` vuoti — lato server, mai fidandosi del solo
  client.
- `dashboard.php`/`recording.php`: badge **CLOCK** (ambra, `.fm-badge-clock`
  in assets/style.css), pulsanti Anteprima/Check nascosti (nessuno stream),
  pulsante Cue **visibile ma disabilitato** con tooltip, barra di progresso
  "obiettivo slot_duration" nascosta (un cronometro aperto non ha un
  obiettivo). In `recording.php` il blocco file Video/Audio diventa un terzo
  ramo `elseif ($isClock)` con una card che spiega l'assenza di file.
- `schedules.php`/`run_schedule.sh`: le sorgenti clock sono escluse dal
  dropdown "Sorgente" degli Orari (avvio solo manuale, per scelta esplicita);
  `run_schedule.sh` ha comunque una guardia difensiva che esce senza chiamare
  `record.sh` se una sorgente risulta `media_type='clock'` (rete di sicurezza
  se il tipo viene cambiato dopo aver creato lo schedule).

**Prefisso codice `K`, non `C`**: scelta deliberata per non confondersi
visivamente con il badge "CUE" già presente nelle stesse tabelle
marker/registrazioni.

Nessuna modifica di schema (`media_type`/`type` erano già testo libero,
nessun `ALTER TABLE`, nessun bump di `schema_version`) — fuori fase come già
0.3.1/0.3.2, senza toccare il lavoro in corso sulla fase 4.

## Vincoli critici

1. Non modificare record.sh dopo la creazione iniziale. Eccezioni fatte finora,
   tutte su richiesta esplicita: vedi l'elenco nella sezione record.sh (4 al
   2026-07-27). La prima (2026-07-26): le sorgenti video/audio pull
   con `type=rtmp|rtsp|srt` (e il ramo `rtmp-push`) non passano più
   `-reconnect`/`-reconnect_streamed`/`-reconnect_delay_max` a ffmpeg — sono
   AVOption del solo protocollo http/https; su rtmp/rtsp/srt ffmpeg le
   accetta sulla riga di comando ma poi abortisce subito dopo l'apertura
   dello stream con `Option reconnect not found` (rc=1, 0-1s), senza scrivere
   alcun file di output. Bug riprodotto e risolto per la sorgente "Spazio
   Umano" (rtmp), che falliva sistematicamente ogni registrazione. Le flag
   restano solo per `type=http`.
2. Non toccare MediaMTX senza che sia esplicitamente richiesto. Modifiche fatte
   finora, entrambe su richiesta esplicita:
   (a) 2026-07-26: `hls: yes` in `/etc/mediamtx.yml`, allora necessario
   all'anteprima live video. **Dalla v2.4.1 l'anteprima non usa più l'HLS di
   MediaMTX** (vincolo 17), quindi non serve più a Fluxus — lasciato com'è
   perché disattivarlo non porta alcun beneficio.
   (b) 2026-07-29: rimossa una path di registrazione continua non più usata,
   con backup del file. La configurazione è ora ridotta ai listener più
   `all_others: source: publisher`.
   ⚠️ **`all_others` non va rimosso**: è ciò che permette a un encoder esterno
   di pushare su `rtmp://<ip>:1935/{source_id}` (sorgenti `rtmp-push`) e a
   `api/test_source.php` di interrogare `/v3/paths/get/{source_id}`. MediaMTX
   resta indispensabile per quelle sorgenti e per il "Check" su di esse.
3. PHP-FPM gira come www-data — tutti i path via costanti FM_*
4. La federazione multi-nodo (API + UI) è PENDING/FUTURO, non ancora
   costruita — vedi sezione dedicata. Non implementarla di iniziativa; se e
   quando verrà fatta, richiederà comunque sempre header X-Federation-Key
   validato contro settings.federation_api_key (design già deciso).
5. clip_trim_filename è rilevante SOLO per cue audio (EDIT non esiste per video,
   ed è comunque in quarantena anche per audio — vedi vincolo 11)
6. extract_clips.sh usa atrim+libmp3lame per audio, accurate_seek+copy per video.
   I cue video ereditano la qualità del file sorgente: se la registrazione è
   ricodificata, lo è anche la clip, senza alcuna modifica a extract_clips.sh
7. ffmpeg usa -reconnect SOLO per type=http (audio e video); rtmp/rtsp/srt e
   rtmp-push non lo supportano, vedi vincolo 1
8. Per sorgente rtmp-push l'URL ffmpeg **non è più assunto** (2026-07-30): si
   chiede a MediaMTX quale percorso stia ricevendo, accettando sia
   `{source_id}` sia `{source_id}/<qualunque cosa>` — gli encoder con i campi
   "indirizzo" e "stream" separati (Wirecast) li concatenano sempre. Vale per
   `record.sh`, `preview.sh` e `api/test_source.php`, che devono restare
   allineati; vedi "Percorso di ingresso rtmp-push". ⚠️ Il confronto sul
   prefisso deve includere lo slash, altrimenti la sorgente 21 intercetta i
   push della 210.
9. helpers FM_* (fmDB, fmJson, fmError) — usa prefisso fm per tutto
10. ~~Path MediaMTX con prefisso `preview_`~~ — **OBSOLETO dalla v2.4.1**:
    l'anteprima non crea più path in MediaMTX, gli endpoint `preview_*.php` non
    parlano più con la sua API e nessuna path `preview_*` viene più creata o
    cancellata. Vedi vincolo 17.
11. TRIM/EDIT manuale è in quarantena (2026-07-26, su richiesta esplicita):
    non svilupparlo/estenderlo finché non viene esplicitamente riattivato.
    Vedi sezione "TRIM/EDIT manuale — in quarantena" per l'elenco completo
    dei file toccati e come tornare indietro.
12. Bug cue video (2026-07-26, risolto): i cue video fallivano sempre in
    extract_clips.sh con `moov atom not found` perché record.sh scriveva i
    file .mp4 senza `-movflags`, quindi il moov atom (indice del contenitore)
    veniva scritto solo alla chiusura del file — illeggibile mentre la
    registrazione era ancora in corso (status='recording'). L'MP3 (audio) non
    ha questo problema, è leggibile in streaming da qualunque punto. Fix:
    aggiunto `-movflags frag_keyframe+empty_moov+default_base_moof` ai 3 punti
    di output mp4 video in record.sh (v4l2, rtmp-push, pull). Overhead
    trascurabile (nessuna transcodifica aggiuntiva, solo metadati contenitore
    scritti a frammenti). Log errore originale: `fm-extract-clips.log`,
    marker 26/27, recording 21, `moov atom not found` su
    `SP_2026-07-26_13-59.mp4`. ⚠️ Il fix era **incompleto**: copriva solo le
    registrazioni non segmentate, perché in modalità segmentata i `-movflags`
    finivano al muxer `segment` che non li propaga. Completato il 2026-07-27,
    vedi vincolo 19.
13. Bug segmento finale vuoto (2026-07-27, risolto): quando `slot_duration` è
    un multiplo esatto di `segment_duration` (es. 3600s/1800s = esattamente 2
    segmenti), il muxer `segment` di ffmpeg apre comunque un file aggiuntivo
    nell'istante esatto in cui `-t $DURATION` chiude lo stream, risultando in
    un segmento da 0s (solo header, nessun frame audio/video valido — su MP3
    ffprobe lo segnala con `Invalid frame size`). Fix: in record.sh, dopo
    `wait $FFMPEG_PID` e solo per registrazioni segmentate, si controlla via
    `ffprobe` la durata dell'ultimo file `${FBASE}_NNN.{mp3,mp4}` e lo si
    elimina se dura meno di 1s o se ffprobe non riesce a leggerlo. Non ci sono
    righe DB per i singoli segmenti (sono solo file), quindi nessuna pulizia
    lato DB è necessaria. Riprodotto e risolto su registrazione id 29
    (source_id 13, Kristall Radio, slot 3600s/segment 1800s,
    `KR_2026-07-27_09-30_002.mp3` da 1176 byte) — file rimosso manualmente
    per quella registrazione già completata.
14. Qualità video (2026-07-27, v2.3.0; profilo `hd` e forbice allargata in
    v2.4.4, 2026-07-29): il peso delle registrazioni video si controlla SOLO da
    `sources.video_quality` (`copy`|`hd`|`alta`|`media`|`bassa`), vedi sezione
    "Qualità video per sorgente". Regole da non violare:
    (a) nessun profilo ridimensiona mai il video — risoluzione e fps restano
    quelli dello stream in ingresso, è un requisito esplicito dell'utente;
    (b) `copy` deve restare il default e il fallback di ogni valore ignoto,
    così le sorgenti esistenti non cambiano comportamento;
    (c) il `case` in record.sh è l'autorità sui parametri, `$fmVideoQualities`
    in sources.php contiene solo etichette e va riallineato a mano se si
    toccano i CRF/bitrate;
    (d) `-force_key_frames` va aggiunto solo quando si ricodifica davvero
    (flag `TRANSCODE`), mai su uno stream copy;
    (e) il preset resta `veryfast` su tutti i profili: è l'unico sopra 1,0x a
    1080p30 su questo host. La qualità si alza abbassando il CRF, non salendo
    di preset — misure e motivazione nella sezione dedicata;
    (f) **CABAC va sempre lasciato attivo** (`cabac=1`, default di `veryfast`,
    output in H.264 High profile): costa poco e vale il 10-15% di compressione.
    L'unico preset che lo disattiva è `ultrafast`, pensato per streaming a
    bassissima latenza — non è il nostro caso, registriamo su disco.
    Non introdurre encoder hardware su questo host: il Pi 5 non ne ha. Non
    adottare le config del whitepaper RPi: sono tarate per ABR e per lasciare
    CPU alla pipeline camera, e in CRF costano il doppio dei byte a parità di
    qualità (misurato, vedi sezione dedicata).
15. Accesso concorrente a SQLite (2026-07-27, v2.4.0): il DB è in **WAL** e ogni
    accesso deve avere un busy_timeout — `PRAGMA busy_timeout=5000` in `fmDB()`,
    `-cmd ".timeout 5000"` (mai il PRAGMA, che stampa output e corrompe le
    `read`) nelle chiamate `sqlite3` da bash. Una sola riga di `recordings` deve
    avere **un solo scrittore per evento**: record.sh finalizza, stop_recording.sh
    solo come fallback. Non aggiungere altri scrittori concorrenti sulle stesse
    colonne. Vedi "Ripresa automatica e robustezza DB".
16. Ciclo supervisore (2026-07-27, v2.4.0): record.sh resta vivo fino alla
    scadenza dello slot e rilancia ffmpeg dopo una caduta. Regole:
    (a) il primo tentativo di una registrazione non segmentata deve continuare a
    scrivere `FBASE.ext` — il rename in `_000` avviene solo se c'è una caduta,
    e sotto `clip_queue.lock`;
    (b) la sentinella `FM_TMP/stop-{id}` e rc=255 sono gli unici segnali di stop
    voluto: non riprendere in quei casi;
    (c) "multi-file sì/no" si deduce dai file su disco, mai da `segment_duration`
    (extract_clips.sh e recording.php);
    (d) mai scrivere in DB una durata non verificata: fallback su `$SECONDS` e
    scarto dei valori fuori scala (il bug dava 56 anni).
17. Anteprima live (2026-07-27, v2.4.1): **non tornare al muxer HLS di
    MediaMTX**. Aborta all'istante sugli stream con timestamp non validi
    (`[HLS] [muxer X] destroyed: muxer error: unable to extract DTS: DTS is
    greater than PTS`), che è il caso della sorgente rtmp in produzione. Il
    guasto era subdolo: la path diventava `ready:true`, quindi
    `preview_start.php` rispondeva `ok:true`, ma `index.m3u8` restava 404/500 e
    il player non partiva mai senza alcun errore lato server. Riprodotto su
    `preview_16` **e** su una path persistente. ffmpeg normalizza
    quei timestamp (`Invalid DTS ... replacing by guess`) e produce HLS valido:
    è il motivo per cui il relay è `scripts/preview.sh` e non MediaMTX.
    Altre regole: (a) `preview_start.php` deve attendere una playlist con
    almeno un `.ts`, non la sola esistenza di `index.m3u8`; (b) i segnali per
    uccidere il relay vanno passati **numerici** (`fmKillPreviewRelay()` in
    helpers.php) — le costanti `SIGTERM`/`SIGKILL` vengono da pcntl, caricata
    in PHP CLI ma **non in PHP-FPM**, e produrrebbero un fatal con risposta
    vuota; (c) `hls_url` resta un path relativo servito da nginx, non un URL
    con host e porta.
18. Watchdog di stallo (2026-07-27, v2.4.2): uno stream che si congela **non**
    fa uscire ffmpeg, e `-t` non lo salva perché conta i timestamp del media,
    non l'orologio. Regole:
    (a) non sostituire `wait_with_watchdog` con un `wait` nudo, e non alzare
    `POLL_SEC` sopra i 2s: è anche la latenza di reazione allo stop dall'UI e,
    sommata alla grazia del SIGTERM, deve restare sotto i 6s di attesa di
    `stop_recording.sh`, altrimenti tornano due scrittori sulla stessa riga
    (vincolo 15);
    (b) allo stallo, `manual` chiude e `scheduled` riprende fino all'ora
    prevista — è una scelta esplicita dell'utente, non invertirla;
    (c) SIGTERM da solo non basta: su un processo bloccato in `read()` serve il
    SIGKILL dopo la grazia;
    (d) `KILLED_BY_WATCHDOG` deve continuare a distinguere un `rc=255` del
    watchdog da uno stop voluto, altrimenti le programmate non riprendono più;
    (e) la deadline di parete resta la rete di sicurezza finale, con il margine
    di 30s che evita di uccidere ffmpeg mentre finalizza il file.
19. `-movflags` in modalità segmentata (2026-07-27, v2.4.2): in output c'è il
    muxer `segment`, che **non propaga i -movflags** al muxer mp4 figlio. Vanno
    passati con `-segment_format_options movflags=frag_keyframe+empty_moov+default_base_moof`,
    verificato sul campo. Senza, ogni segmento resta senza `moov` finché non
    viene chiuso: un segmento interrotto (stream congelato, kill, crash, blackout)
    è **irrecuperabile** — header `ftyp|free|mdat`, nessun `moov` e nessun `moof`,
    ffprobe fallisce con `moov atom not found` e la UI non mostra durata,
    risoluzione né fps. È quanto successo a `SP_2026-07-27_17-03_001.mp4`
    (registrazione 47, 710 MB persi). ⚠️ Corollario sul vincolo 12: quel fix
    del 2026-07-26 era efficace **solo per le registrazioni non segmentate**;
    per le segmentate i cue video estratti a registrazione in corso fallivano
    ancora. Se si tocca `add_output_args()`, i due rami (segmentato e non) vanno
    tenuti entrambi coperti.
20. Durata e cue riferiti al CONTENUTO, non al tempo trascorso (2026-07-28,
    v2.4.3): una registrazione con buchi (stream in ritardo, riprese dopo una
    caduta) ha meno contenuto dei secondi trascorsi. Regole:
    (a) `duration_seconds` è la somma ffprobe dei file (`content_duration()` in
    record.sh), col tempo trascorso solo come fallback;
    (b) `extract_clips.sh` converte `elapsed_seconds` (parete) in posizione nel
    contenuto con `content_position()`, che ricostruisce la timeline da
    `mtime - durata` di ogni file: non usare `elapsed_seconds` come offset
    diretto nel file;
    (c) l'istante del click si ottiene da `start_time + elapsed_seconds`, mai
    mescolando `absolute_time` (ora locale) con `start_time` (UTC);
    (d) la soglia dei 3s che lascia intatto il caso senza buchi va mantenuta:
    evita che il taglio dipenda da una misura di `mtime` quando non serve.
21. Archiviazione su più volumi (2026-07-30, v2.5.0): vedi la sezione dedicata.
    Regole da non violare:
    (a) un volume esterno è collegato **solo se c'è `.fluxus-volume`** nella sua
    radice: `is_dir()` da solo fa scrivere sulla microSD sotto il mount point;
    (b) `output_dir` e `clips_dir` sono **persistiti per registrazione** e non
    vanno mai ricalcolati da `FM_RECORDINGS`/`FM_CLIPS`; `clips_dir` NULL
    significa "riga anteriore alla 2.5.0" → path storico;
    (c) `record.sh` non conosce i volumi e non va toccato per questo: legge
    `output_dir` dal DB;
    (d) volume non disponibile → si ripiega sul volume interno annotandolo, non
    si fa fallire la registrazione;
    (e) DB ed export marker restano **sempre** sul volume interno: devono essere
    leggibili a disco scollegato, e `recording.php` deve aprirsi lo stesso;
    (f) la retention **salta** ciò che sta su un volume scollegato, altrimenti
    cancella la riga DB e lascia i file orfani;
    (g) i media su volume esterno si servono dal symlink `FM_BASE/volumes/{id}`
    (location nginx `/fluxus-media/volumes/`): non aggiungere una location per
    ogni disco;
    (h) lo script privilegiato `fluxus-enable-volume.sh` resta `root:root` in
    `/usr/local/bin`, fuori da ogni cartella scrivibile da `www-data`, e riceve
    solo un UUID ricavato dal server; niente `blkid` da `www-data` (torna vuoto
    in silenzio) e niente `findmnt -r` sui path con spazi.
22. Marker e sessione (2026-07-30): vedi "Marker/cue: niente più perdite
    silenziose" e "Sessione dedicata". Regole da non violare:
    (a) **nessun `catch` vuoto** su una chiamata che salva un marker: un
    salvataggio fallito deve restare visibile a schermo, ed è il motivo per cui
    esiste `fmApiPost()`. Il modale non si chiude prima della conferma;
    (b) le API rispondono **401 JSON**, non un redirect: un `302` verso
    `login.php` fa esplodere `r.json()` sull'HTML e riporta al fallimento muto;
    (c) la sessione di Fluxus vive in `FM_SESSIONS`, **mai** nella save_path di
    default, altrimenti `phpsessionclean` la cancella dopo il `gc_maxlifetime`
    del php.ini ignorando il nostro. `php.ini` e `phpsessionclean.timer` sono di
    sistema e restano invariati: sono condivisi con gli altri siti dell'host;
    (d) `clicked_at` è l'istante del click, e il server lo accetta solo se
    plausibile — non fidarsi mai dell'orologio del client senza controllo;
    (e) su una registrazione conclusa un marker richiede un istante esplicito:
    "adesso" darebbe una posizione fuori scala.
23. Numerazione registrazioni (2026-07-30): il codice `A000`/`V000` è
    `recordings.id` a tre cifre, non un contatore a parte. **Le prove non si
    fanno mai sul DB di produzione con id espliciti**: `sqlite_sequence` non
    torna indietro e da lì in poi i codici reali diventano a cinque cifre. Le
    sandbox usano un file DB separato. Vedi "Numerazione delle registrazioni".
24. Configurazione dell'istanza (2026-07-31, 0.1.0): vedi la sezione dedicata.
    Regole da non violare:
    (a) **nessun percorso, nome utente o indirizzo di server scritto nel
    codice**. Tutto passa dalle costanti `FM_*` lato PHP e dalle variabili di
    `fluxus-env.sh` lato bash. Un valore reintrodotto a mano rompe la seconda
    installazione senza che nulla lo segnali, perché la prima continua a
    funzionare;
    (b) `fluxus-env.sh` e `includes/conf.php` sono **gemelli**: stesso formato,
    stesse regole di derivazione. Se ne cambia uno, si cambia l'altro;
    (c) **niente `source` del file di configurazione** da bash e **niente
    `parse_ini_file()`** da PHP: il primo eseguirebbe i valori con spazi, la
    seconda perde l'intero file in silenzio davanti a un commento con `#`;
    (d) **niente percorso predefinito di ripiego** quando la configurazione non
    si trova: si esce con 78 lato script e con un 500 leggibile lato
    applicazione. Un percorso indovinato, su una macchina con due
    installazioni, scrive nella cartella dell'altra;
    (e) gli script si localizzano da `<cartella dati>/scripts`: **non spostarli
    altrove** senza dargli `FLUXUS_CONF`;
    (f) `fluxus-enable-volume.sh` è unico per la macchina e riceve cartella,
    utente e gruppo come argomenti: **non fargli leggere una configurazione**
    scelta da chi lo invoca — gira come root per conto di un processo che root
    non è. È la regola sudo a fissarne i valori;
    (g) il nome dell'istanza compare anche nella cartella creata sui **dischi
    esterni** e nei nomi degli **alias di sudo**: sono i due punti in cui due
    installazioni si pestano i piedi fuori dai percorsi;
    (h) il **nome del file di database** (`fluxus_media.db`) e i nomi dei file
    di **log** (`fm-*`) restano quelli storici: stanno già dentro una cartella
    per istanza e rinominarli non risolve nulla che non sia già risolto.
25. Installazione (2026-08-01, 0.3.0): vedi la sezione dedicata. Regole da non
    violare:
    (a) **l'installer non inizializza il database per conto suo**: carica
    l'applicazione come utente di Fluxus e lascia fare a `db_init.php`. Uno
    schema applicato da bash sarebbe un terzo lettore da tenere allineato;
    (b) **il database non si apre mai come root**, nemmeno per contare le
    registrazioni in corso: è in WAL (vedi regole d'oro sul DB);
    (c) **l'inclusione di nginx va subito dopo `server {`**, o il blocco PHP di
    Fluxus finisce dopo il `location ~ \.php$` generico e le clip lunghe muoiono
    a metà;
    (d) la disinstallazione che conserva i dati **lascia il collegamento
    `fluxus.conf`**: è la firma che permette di reinstallare la stessa istanza
    sopra i suoi dati;
    (e) `/usr/local/bin/fluxus-enable-volume.sh` **non si sovrascrive** se è
    diverso da quello del sorgente: è unico per la macchina e lo condividono
    tutte le istanze;
    (f) il comando `fluxus` carica il lettore **root:root** di
    `/usr/local/lib/fluxus`, mai quello nella cartella dell'utente di Fluxus:
    gira come root, e quel file sarebbe riscrivibile da chi root non è;
    (g) **niente istanza indovinata**: con più installazioni e nessuna
    indicata, il comando si ferma ed elenca.
