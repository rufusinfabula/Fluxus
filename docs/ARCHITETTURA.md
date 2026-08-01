# Architettura di Fluxus

Sistema di registrazione **audio e video** programmata per Raspberry Pi, con
marcatura in diretta ed estrazione automatica di clip.

Gira come applicazione web PHP dentro nginx, registra con `ffmpeg`, programma
gli slot con i timer di systemd e tiene lo stato in SQLite. Nessun servizio
cloud, nessuna porta aperta verso Internet.

Questo documento descrive **com'è fatto**. Per il resto:

- **Presentazione e installazione**: [../README.md](../README.md)
- **Manuale per chi lo usa**: [MANUALE.md](MANUALE.md)
- **Note tecniche per chi lo sviluppa**: [NOTE-TECNICHE.md](NOTE-TECNICHE.md) —
  storia delle decisioni, misure sul campo, vincoli critici. È la fonte
  autorevole sui *perché*; qui c'è il *cosa*.
- **Lavori in corso**: [ROADMAP.md](ROADMAP.md)

⚠️ I percorsi citati (`/var/www/fluxus-media`, `/var/lib/fluxus-media`, il
sottopercorso web `/fluxus-media/`) **non sono più scritti nel codice**: dalla
`0.1.0` arrivano tutti da un file di configurazione, uno per installazione. Qui
compaiono nella forma che assumono per l'istanza `fluxus-media`, cioè quella da
cui il progetto proviene. Vedi *Configurazione* più sotto.

---

## Cosa fa

1. Si definiscono delle **sorgenti**: uno stream (HTTP, RTMP, RTSP, SRT), una
   webcam locale (V4L2), o un ingresso su cui un encoder esterno può pushare
   (RTMP push verso MediaMTX).
2. Si registra **a mano** dalla dashboard, oppure si programmano **orari**
   ricorrenti in formato `OnCalendar` di systemd.
3. Mentre la registrazione è in corso si premono **Marker** (segnalibro
   temporale) e **Cue** (segnalibro che genera anche un file ritagliato).
4. Un timer estrae i **cue** in clip autonome, con pre-roll e post-roll
   configurabili.
5. Registrazioni e clip si riascoltano e si scaricano dal browser. Una
   **retention** per sorgente cancella il vecchio da sola.

Ogni sorgente ha un `media_type` (`audio` o `video`) che determina formato di
output (MP3 / MP4), parametri ffmpeg e funzioni disponibili in interfaccia.

---

## Architettura

```
                    browser (LAN)
                          │
                     nginx :80
              ┌───────────┼────────────────────────────┐
              │           │                            │
        PHP-FPM 8.2   file statici              /preview/ (HLS)
        (www-data)    /recordings/ /clips/      /volumes/  (symlink ai dischi)
              │
              ├── pagine: dashboard, sorgenti, orari, registrazioni, impostazioni
              ├── api/*.php  (status, start, stop, marker, download, preview…)
              │
              ▼
        SQLite (WAL)  ◄──────────┬──────────────┬─────────────────┐
   /var/lib/fluxus-media/db/     │              │                 │
                                 │              │                 │
                        record.sh │  extract_clips.sh   retention_cleanup.sh
                        (ffmpeg)  │   (timer 30s)        (timer 30min)
                                 │
                        run_schedule.sh ◄── fm-sched-{id}.timer (OnCalendar)
                                 │
                          ┌──────┴──────┐
                       ffmpeg        MediaMTX
                     (registra)   (:1935 RTMP ingresso push)
```

Chi scrive cosa, in breve:

| Componente | Ruolo |
|---|---|
| `nginx` | serve le pagine PHP, i media (fuori dalla webroot, via `alias`) e l'HLS dell'anteprima |
| `PHP-FPM` (www-data) | interfaccia e API; scrive sorgenti/orari/marker; lancia gli script |
| `record.sh` | l'unico che parla con ffmpeg per registrare e l'unico che finalizza la riga in `recordings` |
| `run_schedule.sh` | invocato dai timer: crea la riga e lancia `record.sh` |
| `extract_clips.sh` | ritaglia le clip dei cue pendenti |
| `stop_recording.sh` | stop richiesto dall'interfaccia (sentinella + kill) |
| `retention_cleanup.sh` | pulizia periodica per sorgente |
| `preview.sh` | relay ffmpeg → HLS locale per l'anteprima live |
| `remote_sync.php` | invia lo stato a *Fluxus Remote* e recupera i marker premuti da fuori LAN |
| MediaMTX | riceve gli stream push (`rtmp-push`) e risponde al "Check" su di essi |

---

## Stack

Versioni verificate su questo host (Raspberry Pi 5, Debian 12, kernel 6.12):

| | |
|---|---|
| nginx | 1.22.1 — vhost di default, sottopercorso `/fluxus-media/` |
| PHP | 8.2.32 FPM, pool condivisa con gli altri siti dell'host |
| SQLite | 3.40.1, journal in **WAL** |
| ffmpeg | 5.1.9 (Debian rpt) |
| UIkit | 3.21.6, servito da Fluxus insieme a hls.js e al font della firma — vedi *Dipendenze dell'interfaccia* |
| MediaMTX | systemd, RTMP `:1935`, RTSP `:8554`, API `:9997` |

Utente di sistema: **`www-data`**. Non esiste un utente dedicato.

⚠️ **Il Pi 5 non ha encoder H.264 hardware** (rimosso rispetto al Pi 4). Ogni
transcodifica è `libx264` software. Vedi *Profili di qualità video*.

---

## Percorsi

Codice (webroot):

```
/var/www/fluxus-media/
  index.php            → redirect a dashboard.php
  dashboard.php  recording.php  recordings.php
  sources.php    schedules.php  settings.php
  login.php      logout.php
  edit.php  edit-trim.php      ← in quarantena, vedi sotto
  VERSION              ← unica fonte del numero di versione
  includes/   conf.php config.php db.php db_init.php auth.php helpers.php
              nav.php head.php head_dark.php foot.php
              markers_table.php marker_modal.php preview_modal.php
  api/        status.php start.php stop.php recordings.php marker.php
              download.php system.php test_source.php
              preview_start.php preview_stop.php
              validate_oncalendar.php volume_order.php volume_enable.php
              trim.php            ← in quarantena
  db/schema.sql
  assets/style.css
  assets/vendor/       ← UIkit, hls.js, wavesurfer, il font della firma
  icons/
```

### Dipendenze dell'interfaccia

Tutto ciò che una pagina carica viene da Fluxus: **nessuna CDN, nessun font
remoto**. Non è pulizia formale — una macchina appena portata in un posto nuovo
non ha ancora una connessione, e la prima pagina che serve aprire è quella che
la rete la configura.

| | |
|---|---|
| UIkit 3.21.6 | stile, JavaScript, icone |
| hls.js 1.6.16 (build `light`) | anteprima live delle sorgenti |
| wavesurfer.js 7.12.11 | ritaglio dei cue audio (pagina in quarantena) |
| Recursive 700, latino | la firma nel piè di pagina |

Circa 950 KB. Ogni file porta la versione nel nome e nginx li dichiara
immutabili. Le icone dei dischi non vengono da un font di icone ma sono
disegnate in linea da `fmVolumeIcon()` in `includes/helpers.php`.

Si aggiornano con `packaging/vendor-assets.sh`, che tiene versioni, indirizzi e
impronte `sha256`; il dettaglio è in `app/assets/vendor/README.md`.

Dati (volume interno, radice `FM_BASE`):

```
/var/lib/fluxus-media/
  fluxus.conf                                           → /etc/fluxus/fluxus-media.conf
  db/fluxus_media.db                                    + -wal / -shm
  recordings/{source_id}/{base}.mp3|.mp4                registrazione singola
  recordings/{source_id}/{base}_{NNN}.mp3|.mp4          segmentata o ripresa
  recordings/{source_id}/{base}_markers.csv|.txt|.json  export marker
  clips/{source_id}/{base}_m{marker_id}.mp3|.mp4        clip dei cue
  tmp/                       lock, sentinelle di stop, unit temporanei
  tmp/preview/{source_id}/   HLS effimero dell'anteprima
  volumes/{volume_id}        symlink alla radice di un disco esterno
  logs/                      vedi "Log"
  scripts/                   fluxus-env.sh ← carica la configurazione
                             record.sh run_schedule.sh extract_clips.sh
                             stop_recording.sh retention_cleanup.sh
                             preview.sh remote_sync.php
```

Un disco esterno replica `recordings/` e `clips/` sotto la propria radice dati
(es. `/mnt/disco-esterno/fluxus/`). **DB ed export marker restano sempre sul
volume interno**, così una registrazione resta consultabile con il disco
scollegato.

Configurazione fuori dal progetto:

| File | Contenuto |
|---|---|
| `/etc/fluxus/<istanza>.conf` | **percorsi e nomi dell'installazione** (root:root 0644) |
| `/etc/fluxus/<istanza>.remote.conf` | URL e chiave del relay Fluxus Remote (`root:<gruppo>` 0640) |
| `/etc/nginx/sites-available/default` | le `location` del sottopercorso |
| `/etc/sudoers.d/<istanza>` | i pochi comandi root concessi all'utente di Fluxus |
| `/etc/mediamtx.yml` | listener + `all_others: source: publisher` |
| `/etc/fstab` | dischi esterni, **per UUID**, con `nofail` |
| `/usr/local/bin/fluxus-enable-volume.sh` | abilitazione disco (root:root 0755) |

---

## Configurazione

Un file per installazione, `/etc/fluxus/<istanza>.conf`, in forma
`CHIAVE=valore`. Stabilisce **nome dell'istanza**, cartella dati, radice web,
sottopercorso, utente e gruppo di sistema, prefisso dei servizi systemd e
indirizzi del server RTMP. È l'unico punto in cui quei valori esistono.

Dal nome dell'istanza derivano tutti i valori non dichiarati, secondo una regola
sola: `/var/lib/<istanza>`, `/var/www/<istanza>`, sottopercorso `/<istanza>`,
servizi `<istanza>-*`, cartella `<istanza>/` sui dischi esterni. È questo che
permette di installare Fluxus **due volte sulla stessa macchina** — una
in produzione e una di collaudo — senza che si tocchino.

Lo leggono in due, con le stesse regole:

| Chi | Come lo trova |
|---|---|
| gli script (`scripts/fluxus-env.sh`) | risalendo dalla propria posizione: stanno in `<cartella dati>/scripts`, e `<cartella dati>/fluxus.conf` è un collegamento al file in `/etc` |
| l'applicazione (`includes/conf.php`) | `FLUXUS_CONF` dall'ambiente, poi `includes/instance.php` scritto dall'installer, poi il nome della cartella che la contiene |

Se la configurazione non si trova, **ci si ferma**: niente percorso di ripiego,
che su una macchina con due installazioni scriverebbe nella cartella sbagliata.

`nginx/`, `systemd/` e `config/sudoers.fluxus.in` nel repository sono **modelli**
con segnaposto `@NOME@`, resi dall'installer con i valori dell'istanza.

Il modello commentato di tutte le chiavi è
[config/fluxus.conf.example](../config/fluxus.conf.example); il *perché* di ogni
scelta sta in [NOTE-TECNICHE.md](NOTE-TECNICHE.md), sezione *Configurazione
dell'istanza*.

---

## Installazione e gestione

Due file, entrambi bash:

| | |
|---|---|
| [`install.sh`](../install.sh) | installa e aggiorna. Rilanciarlo sulla stessa istanza è il modo normale di aggiornarla |
| [`bin/fluxus`](../bin/fluxus) | governa ciò che è installato: `status`, `list`, `config`, `logs`, `update`, `backup`, `restore`, `uninstall` |

`install.sh` mette a posto, nell'ordine: pacchetti mancanti, configurazione
dell'istanza, cartelle dati, applicazione nella radice web, script nella
cartella dati, comandi di sistema, servizi systemd, permessi di sudo, snippet
nginx e relativa inclusione nel vhost, servizio MediaMTX dedicato, database,
avvio dei timer. Alla fine stampa l'indirizzo a cui collegarsi.

```
sudo ./install.sh --instance fluxus-dev        installa un'istanza di collaudo
sudo ./install.sh --dry-run                    mostra cosa farebbe, senza fare niente
sudo fluxus --instance fluxus-dev status       come sta
sudo fluxus update                             riaggiorna dal sorgente
```

Ciò che l'installer lascia sulla macchina, oltre alle due cartelle dell'istanza:

```
/etc/fluxus/<istanza>.conf              la configurazione
/etc/fluxus/<istanza>.remote.conf       i segreti del relay (root:<gruppo> 0640)
/etc/fluxus/<istanza>.install           il manifesto: che cosa è stato installato
/etc/fluxus/<istanza>.mediamtx.yml      la configurazione del suo server RTMP
/etc/systemd/system/<prefisso>-*        servizi e timer
/etc/sudoers.d/<istanza>                i permessi minimi dell'utente di Fluxus
/etc/nginx/snippets/<istanza>.conf      i blocchi location, inclusi nel vhost
/usr/local/bin/fluxus                   il comando di gestione (uno per macchina)
/usr/local/lib/fluxus/fluxus-env.sh     copia root:root del lettore, per il comando
/usr/local/bin/fluxus-enable-volume.sh  script privilegiato (uno per macchina)
```

⚠️ L'installer **non tocca un'installazione che non sia sua**: si ferma se la
radice web o la cartella dati esistono e non portano la sua firma, se il
prefisso dei servizi è di qualcun altro, o se c'è una registrazione in corso. Il
*perché* di ogni guardia sta in [NOTE-TECNICHE.md](NOTE-TECNICHE.md), sezione
*Installazione*.

---

## Database

SQLite, schema in [db/schema.sql](db/schema.sql), versione corrente **5**.

| Tabella | Contiene |
|---|---|
| `settings` | chiave/valore: `node_id`, `node_name`, `timezone`, `auth_enabled`, `password_hash`, `cue_pre_roll`, `cue_post_roll`, `marker_autosave_seconds`, `storage_volume_audio`/`_video`/`_order`, `schema_version` |
| `sources` | sorgenti: `media_type`, `type`, `url`/`device`, qualità, retention, volume |
| `schedules` | orari: `on_calendar`, `slot_duration`, `segment_duration` |
| `recordings` | una riga per registrazione: `output_dir`, `clips_dir`, tempi, `status`, `duration_seconds`, `ffmpeg_pid`, `notes` |
| `markers` | marker e cue: `elapsed_seconds`, `type`, `clip_status`, `clip_filename`, `origin` |
| `manual_clips` | clip da ritaglio manuale — tabella viva, funzione in quarantena |
| `storage_volumes` | volumi di archiviazione; `id=1` è il volume interno, non eliminabile |
| `federation_peers`, `federation_log` | predisposte, **nessun codice le usa** |

`includes/db_init.php` gira a ogni request e fa due cose: crea il DB al primo
avvio, e applica le **migrazioni incrementali** basate su `schema_version`
(v1→v2 retention, v2→v3 `origin`, v3→v4 `video_quality`, v4→v5 volumi). Una
migrazione nuova va aggiunta come blocco `if ($cur < N)`, **mai riscrivendo
`schema.sql`** per i DB già in produzione.

### ⚠️ Regole d'oro sul DB

Il DB è in WAL e ha più scrittori (PHP-FPM e vari script bash). Due errori
già pagati:

**1. Mai aprire il DB con un utente diverso da `www-data`, nemmeno in sola
lettura.** In WAL anche una `SELECT` crea `-wal`/`-shm` con l'owner di chi ha
lanciato il comando: da quel momento PHP-FPM va in
`attempt to write a readonly database` e l'interfaccia smette di salvare pur
continuando a leggere. Forma corretta:

```bash
sudo -u www-data sqlite3 -cmd ".timeout 5000" \
  /var/lib/fluxus-media/db/fluxus_media.db "SELECT …"
```

Se il danno è fatto: `sudo chown www-data:www-data <db>-wal <db>-shm` (non
cancellare il `-wal`, contiene transazioni non ancora checkpointate).

**2. Nei bash usare `-cmd ".timeout 5000"`, mai `-cmd "PRAGMA busy_timeout=…"`**:
il PRAGMA stampa il proprio risultato, che finisce nella prima variabile letta
con `IFS='|' read` e rompe lo script.

Un backup deve includere `-wal` e `-shm`, o passare da un checkpoint.

---

## Ciclo di vita di una registrazione

```
 timer fm-sched-{id}          oppure     POST api/start.php
        │                                       │
   run_schedule.sh                        (riga 'recording')
   (riga 'pending')                             │
        └───────────────┬─────────────────────ancora───┘
                        ▼
                    record.sh
        ┌───────────────────────────────────────────┐
        │ ciclo supervisore, fino alla deadline     │
        │  ffmpeg  ← watchdog di stallo (byte fermi │
        │            per 60s → chiude e riprende)   │
        │  caduta → riprende dopo 10s, file _NNN    │
        │  sentinella stop-{id} o rc=255 → esce     │
        └───────────────────────────────────────────┘
                        ▼
        duration_seconds = somma ffprobe dei file
        status = completed | failed, notes = interruzioni
```

In parallelo, ogni 30s `extract_clips.sh` prende i cue con
`clip_status='pending'` più vecchi di `post_roll + 10s` e ne ritaglia la clip.

Tre comportamenti che spiegano la maggior parte delle domande:

- **La durata è quella del contenuto, non dello slot.** Se lo stream parte in
  ritardo o cade, `duration_seconds` è la somma reale dei file.
- **I cue sono tagliati sulla timeline del contenuto.** Un cue premuto a +140s
  di parete, su uno stream partito a +95s, viene estratto a 45s di contenuto.
  La conversione scatta solo se lo scarto supera 3s.
- **Una registrazione programmata riprende da sola** dopo una caduta o un
  blocco dello stream, fino all'ora di fine prevista. Una manuale, invece, allo
  stallo si chiude.

---

## Script

Tutti in `/var/lib/fluxus-media/scripts/`, girano come `www-data`.

### `record.sh <recording_id> <source_id> <duration> [segment]`

Il cuore del sistema. Legge la sorgente dal DB, costruisce la riga ffmpeg,
sorveglia il processo e finalizza la riga in `recordings`.

⚠️ **`record.sh` non va modificato senza richiesta esplicita.** È un vincolo di
progetto: le sette eccezioni fatte finora sono elencate in
[NOTE-TECNICHE.md](NOTE-TECNICHE.md), ognuna con il bug che l'ha motivata. Se serve
sostituirlo con una registrazione in corso, usare un **rename atomico**
(`mv` da un file già scritto): bash rilegge lo script mentre lo esegue, e
riscriverne l'inode sotto un processo vivo gli fa leggere byte sbagliati alla
finalizzazione.

Cose da sapere prima di toccarlo:

- `-reconnect`/`-reconnect_streamed`/`-reconnect_delay_max` valgono **solo per
  `type=http`**: su rtmp/rtsp/srt ffmpeg le accetta sulla riga di comando e poi
  aborta con `Option reconnect not found`, senza scrivere nulla.
- Gli output MP4 vogliono `-movflags frag_keyframe+empty_moov+default_base_moof`,
  altrimenti il `moov` arriva solo alla chiusura e il file è illeggibile mentre
  si registra (i cue video fallivano con `moov atom not found`). In modalità
  segmentata vanno passati come
  `-segment_format_options movflags=…`: il muxer `segment` non li propaga al
  muxer mp4 figlio, e un segmento interrotto resta irrecuperabile.
- Il watchdog campiona i byte ogni 2s. Quei 2s sono anche la latenza di reazione
  allo stop dall'interfaccia e, sommati alla grazia del SIGTERM, devono restare
  sotto i 6s di attesa di `stop_recording.sh`, altrimenti tornano due scrittori
  sulla stessa riga.
- Non conosce i volumi: legge `output_dir` dal DB.

### Gli altri

| Script | Cosa fa |
|---|---|
| `run_schedule.sh {schedule_id}` | invocato dal timer. Verifica che schedule e sorgente siano attivi, costruisce `filename_base` (`{prefisso}_{YYYY-MM-DD_HH-MM}`), inserisce la riga, lancia `record.sh`. Logga **sempre** l'esito, anche quando non registra |
| `extract_clips.sh` | ogni 30s. Lock `tmp/clip_queue.lock`. Audio: `atrim` + libmp3lame. Video: `-accurate_seek -ss … -c copy`. Pre/post-roll letti dalle settings a ogni giro |
| `stop_recording.sh {id}` | crea `tmp/stop-{id}` **prima** del kill (è così che il supervisore distingue lo stop voluto da una caduta), attende ~6s che `record.sh` finalizzi, e chiude la riga solo se lui non l'ha fatto |
| `retention_cleanup.sh` | ogni 30min. Applica i 4 limiti per sorgente. **Salta** ciò che sta su un volume scollegato, per non lasciare file orfani |
| `preview.sh` | relay ffmpeg → HLS in `tmp/preview/{source_id}/`. Video in copy, audio sempre in AAC (l'unico che i browser leggono dentro HLS) |
| `remote_sync.php` | ogni 5s: manda al relay le registrazioni attive, recupera i marker premuti da fuori LAN |

---

## systemd

Fissi — i nomi hanno il prefisso dell'istanza, i modelli stanno in
[systemd/](../systemd/):

| Unit | Cadenza |
|---|---|
| `<prefisso>-extract-clips.timer` | 30s |
| `<prefisso>-retention-cleanup.timer` | 30min |
| `<prefisso>-remote-sync.timer` | 5s (inerte se Fluxus Remote non è configurato) |

Generati: ogni orario attivo produce `<prefisso>-sched-{id}.timer` +
`<prefisso>-sched-{id}.service`, scritti da `schedules.php` in
`/etc/systemd/system/` via i comandi `sudo` concessi in
`/etc/sudoers.d/<istanza>` (install, `daemon-reload`, `enable/disable --now`,
`rm`).

```ini
# fluxus-media-sched-9.timer
[Timer]
OnCalendar=Mon..Fri 17:03
Persistent=false

# fluxus-media-sched-9.service
[Service]
Type=oneshot
User=www-data
KillMode=none
Environment=FLUXUS_CONF=/etc/fluxus/fluxus-media.conf
ExecStart=/var/lib/fluxus-media/scripts/run_schedule.sh 9
```

`KillMode=none` è necessario: `record.sh` deve sopravvivere alla fine
dell'unit oneshot.

Diagnosi: `systemctl list-timers | grep <prefisso>`, e `logs/fm-schedule.log`
per sapere chi ha avviato cosa (il journal del service su questo host risulta
vuoto). I nomi dei **file di log** restano `fm-*` a prescindere dall'istanza:
stanno già dentro una cartella dati per istanza.

---

## nginx

Nel vhost di default. I media stanno fuori dalla webroot e sono serviti per
`alias`. Il modello con i segnaposto è
[nginx/locations-fluxus.conf.in](../nginx/locations-fluxus.conf.in); qui è reso
per l'istanza `fluxus-media`:

```nginx
location ~ ^/fluxus-media/.*\.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_read_timeout 300;          # l'estrazione clip può essere lenta
    fastcgi_param FLUXUS_CONF /etc/fluxus/fluxus-media.conf;
}
location /fluxus-media/            { alias /var/www/fluxus-media/; index index.php; }
location /fluxus-media/recordings/ { alias /var/lib/fluxus-media/recordings/; add_header Accept-Ranges bytes; }
location /fluxus-media/clips/      { alias /var/lib/fluxus-media/clips/;      add_header Accept-Ranges bytes; }
location /fluxus-media/volumes/    { alias /var/lib/fluxus-media/volumes/;    add_header Accept-Ranges bytes; }
location /fluxus-media/preview/ {
    alias /var/lib/fluxus-media/tmp/preview/;
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    types { application/vnd.apple.mpegurl m3u8; video/mp2t ts; }
    default_type application/octet-stream;
}
```

`/volumes/` è una sola location per **tutti** i dischi esterni: in
`FM_BASE/volumes/{id}` c'è un symlink alla radice di ciascuno, quindi
aggiungere un disco non richiede di toccare nginx.

---

## Archiviazione su più volumi

Con il video in stream copy a ~2 GB/h, una microSD da 58 GB si riempie in poche
decine di ore. Da 2.5.0 si scelgono da interfaccia le destinazioni, distinte per
audio e per video, con override per singola sorgente.

- `storage_volumes` elenca i volumi; `id=1` è quello interno.
- `settings.storage_volume_audio` / `_video` sono le destinazioni predefinite.
- `sources.storage_volume_id` è l'override (NULL = predefinito).
- `recordings.output_dir` e `recordings.clips_dir` sono **persistiti per
  registrazione**: in lettura non vanno mai ricalcolati. `clips_dir` NULL
  significa "riga anteriore alla 2.5.0" → vale il percorso storico.

**Il file sentinella.** Un volume esterno è considerato collegato solo se
contiene `.fluxus-volume` nella propria radice. Non basta `is_dir()`: quando il
disco si scollega resta la directory vuota del mount point, e si finirebbe per
scrivere sulla microSD credendo di scrivere sull'esterno.

**Se il volume non c'è, si ripiega sul volume interno** e lo si annota in
`recordings.notes`: una diretta programmata non si perde perché qualcuno ha
urtato un cavo USB.

Sul disco esterno la radice dei dati è una cartella che porta il **nome
dell'istanza** (`<mount>/fluxus-media/`): due installazioni possono usare lo
stesso disco senza sovrascriversi le registrazioni.

I dischi si abilitano dall'interfaccia (Impostazioni → Archiviazione →
*Abilita*), che chiama
`sudo /usr/local/bin/fluxus-enable-volume.sh <UUID> <cartella> <utente> <gruppo>`.
Lo script stacca il disco dall'automount, lo mette in `/etc/fstab` per UUID con
`nofail`, lo monta sotto `/mnt/<label>`, crea le cartelle e la sentinella, e
verifica in chiusura che l'utente di Fluxus ci scriva davvero. **Non formatta
mai nulla.**

⚠️ Lo script è **unico per la macchina** e non appartiene a nessuna istanza: per
questo cartella, utente e gruppo gli arrivano come argomenti invece che da una
configurazione. È la regola sudo a fissarli ai valori di quell'istanza — lui si
limita a controllare che siano sensati.

⚠️ Lo script deve restare `root:root 0755` in `/usr/local/bin`. Nella cartella
`scripts` dell'istanza (che appartiene all'utente di Fluxus) la regola
`NOPASSWD` diventerebbe una scala verso root.

Altre due trappole già pagate: `blkid` lanciato da `www-data` torna **vuoto
senza errore** (si usa `fmDeviceUUID()`, che risolve i symlink di
`/dev/disk/by-uuid`); e `findmnt -r` fa l'escaping degli spazi, rompendo i
mount tipo `/media/utente/USB DISK` (serve `findmnt -l`).

---

## Profili di qualità video

`sources.video_quality` decide il peso delle registrazioni video. Il `case` in
`record.sh` è l'autorità sui parametri; la tabella in `sources.php` contiene
solo le etichette e va riallineata a mano.

| Profilo | ffmpeg | GB/h | SSIM | velocità |
|---|---|---|---|---|
| `copy` (default) | `-vcodec copy -acodec copy` | come la sorgente | — | — |
| `hd` | `libx264 -preset veryfast -crf 17` + aac 128k | ~0,92 | 0,9944 | 1,27x |
| `alta` | `veryfast -crf 21` + aac 128k | ~0,58 | 0,9923 | 1,31x |
| `media` | `veryfast -crf 28` + aac 128k | ~0,26 | 0,9836 | 1,45x |
| `bassa` | `veryfast -crf 32` + aac 96k | ~0,16 | 0,9750 | 1,48x |

Misurato su 1080p30 su questo host. Scala con la risoluzione.

Regole invarianti:

- **Risoluzione e fps non vengono mai toccati.** Nessun profilo ridimensiona;
  `resolution`/`fps` in `sources` sono parametri di *cattura* del solo ramo v4l2.
- `copy` resta il default e il fallback di ogni valore ignoto.
- Il preset resta **`veryfast` su tutti i profili**: è l'unico sopra 1,0x a
  1080p30 su questo host, e sotto 1,0x ffmpeg perde frame. La qualità si alza
  abbassando il CRF, non salendo di preset.
- Chi ricodifica aggiunge `-force_key_frames "expr:gte(t,n_forced*2)"` (GOP 2s),
  che tiene preciso il taglio dei cue; mai su uno stream copy.
- Il ramo v4l2 non può copiare un device raw: se la sorgente è su `copy`,
  ricade su `alta`.

⚠️ **Non riproporre HEVC/libx265 né AV1**: misurati fra 0,15x e 0,27x su questo
host, e HEVC in MP4 non si riproduce in modo affidabile nel browser. **Non
adottare le configurazioni del whitepaper Raspberry Pi**: sono tarate per ABR e
per lasciare CPU alla pipeline camera, e in CRF costano il doppio dei byte a
parità di qualità (misurato).

---

## Sicurezza

- **Nessuna porta esposta a Internet.** L'interfaccia si raggiunge in LAN.
- **Autenticazione opzionale**: `settings.auth_enabled` + `password_hash`
  (bcrypt), sessione PHP. Con `auth_enabled=0` non c'è alcun blocco — va bene in
  LAN fidata, va acceso appena la rete è condivisa.
- **Marker da fuori LAN senza aprire porte**: *Fluxus Remote* è un relay su una
  VM esterna. Il Pi **non riceve mai connessioni in ingresso**: fa solo POST in
  uscita ogni 5s, con `Authorization: Bearer`. Nel caso peggiore, un relay
  compromesso può iniettare marker falsi in coda — non ha alcuna via di rientro
  verso il Pi o la LAN.
- **Privilegi di `www-data`**: solo i comandi in `/etc/sudoers.d/fluxus-media`,
  tutti con pattern stretti (unit `fm-sched-*`, e lo script dei volumi che
  riceve solo un UUID ricavato dal server, mai dal client).

---

## Log

In `/var/lib/fluxus-media/logs/`:

| File | Contenuto |
|---|---|
| `fm-record-{id}.log` | una per registrazione: riga ffmpeg, riprese, stalli, durata calcolata |
| `fm-schedule.log` | ogni invocazione di `run_schedule.sh`, con chi l'ha chiamata ed esito |
| `fm-extract-clips.log` | estrazione delle clip |
| `fm-retention.log` | cosa ha cancellato la retention |
| `fm-remote-sync.log` | dialogo con Fluxus Remote |
| `fm-preview-{source_id}.log` | relay dell'anteprima |

Cose da cercare: `speed=` sotto `1.0x` significa **frame persi**;
`Option reconnect not found` è il bug delle flag su rtmp/rtsp;
`moov atom not found` è un MP4 senza `-movflags`;
`database is locked` è contesa SQLite.

**Rotazione (0.3.1)**, decisa log per log, non con una politica unica:

| Log | Politica |
|---|---|
| `fm-record-{id}.log` | non ruota: resta finché esiste la registrazione, poi 30 giorni di grazia dalla cancellazione (automatica o manuale) |
| `fm-extract-clips.log`, `fm-preview-*.log` | settimanale, 12 copie |
| `fm-retention.log`, `fm-schedule.log` | settimanale, 26 copie (sono registri d'archivio, non operativi) |
| `fm-remote-sync.log` | giornaliera, 30 copie, `maxsize 8M` (il più chiacchierone) |

I cinque log "di servizio" li ruota `logrotate`, di sistema, installato da
`install.sh` come `/etc/logrotate.d/<istanza>` a partire dal modello
[config/logrotate.fluxus.in](../config/logrotate.fluxus.in) — verificato con
`logrotate -d` prima di essere messo al suo posto, come `nginx -t`. Il perché
della direttiva `su` (senza, la rotazione non parte, in silenzio) e del
meccanismo a due punti (`retention_cleanup.sh` + `api/recordings.php`) per
`fm-record-{id}.log` stanno in [NOTE-TECNICHE.md](NOTE-TECNICHE.md).

---

## Cosa non c'è

Da sapere prima di cercarlo nel codice o riproporlo:

**TRIM/EDIT manuale — in quarantena** (dal 2026-07-26, richiesta esplicita).
Il ritaglio dei cue audio con WaveSurfer e l'estrazione manuale in
`recording.php` sono **disattivati, non eliminati**: `api/trim.php` risponde
503, `edit.php` mostra un avviso ed esce, `edit-trim.php` redirige, la voce è
via dalla navbar. Il codice originale è ancora sotto i guard, e la sezione
dedicata di [NOTE-TECNICHE.md](NOTE-TECNICHE.md) elenca i punti esatti da cui riattivarlo.
Non estenderlo finché non viene riattivato.

**Federazione multi-nodo — mai costruita.** Schema pronto
(`federation_peers`, `federation_log`, `sources.federated_from`,
`settings.federation_api_key`), ma non esiste `federation.php`, nessun endpoint
`api/federation/*`, nessuna voce in navbar. Non implementarla di iniziativa. Da
non confondere con **Fluxus Remote**, che è realmente in funzione.

**Anteprima via HLS di MediaMTX — abbandonata.** Non ha mai funzionato su
nessuna sorgente reale: aborta sugli stream con timestamp non validi
(`DTS is greater than PTS`), e il guasto era subdolo perché la path diventava
`ready:true` e l'API rispondeva `ok`, mentre `index.m3u8` restava 404. ffmpeg
normalizza quei timestamp: per questo l'anteprima passa da `preview.sh`. Non
tornare indietro.

**Encoder hardware su questo host.** Non esiste sul Pi 5.

---

## Manutenzione

```bash
# Stato dei timer
systemctl list-timers --all | grep fm-

# Registrazioni attive
sudo -u www-data sqlite3 -cmd ".timeout 5000" \
  /var/lib/fluxus-media/db/fluxus_media.db \
  "SELECT id, source_name, start_time, ffmpeg_pid FROM recordings WHERE status='recording';"

# Cue in attesa di estrazione
sudo -u www-data sqlite3 -cmd ".timeout 5000" \
  /var/lib/fluxus-media/db/fluxus_media.db \
  "SELECT id, recording_id, elapsed_hms, clip_status FROM markers WHERE clip_status='pending';"

# Segui una registrazione in corso
tail -f /var/lib/fluxus-media/logs/fm-record-<id>.log

# Verifica che il Pi non stia andando in throttling durante una transcodifica
vcgencmd get_throttled; vcgencmd measure_temp

# Forza un giro di estrazione clip / retention
sudo systemctl start fm-extract-clips.service
sudo systemctl start fm-retention-cleanup.service

# Backup del DB (WAL: passare dal checkpoint, non copiare il solo .db)
sudo -u www-data sqlite3 /var/lib/fluxus-media/db/fluxus_media.db \
  ".backup '/percorso/backup/fluxus_media.db'"
```

⚠️ Prima di misurare prestazioni di encoding, controllare che la macchina sia
scarica e non in throttling: una tornata di misure fatta con un altro processo
attivo ha dato valori depressi del ~30% e ha portato a conclusioni sbagliate.

---

## Storico versioni

| | |
|---|---|
| **2.5.2** (30-07-2026) | pagina della singola registrazione: anteprima video in linea sotto la riga del file invece che in un modale, barra "marker/cue a posteriori" compatta attaccata alla card dei file con Marker come predefinito, durata di ogni segmento audio accanto alla dimensione, zona pericolosa spostata in fondo alla pagina; piè di pagina con copyright, versione e firma |
| **2.5.1** (30-07-2026) | percorso di ingresso `rtmp-push` risolto da MediaMTX (encoder con indirizzo+stream separati); fine delle perdite silenziose di marker/cue (niente `catch` vuoti, 401 JSON, sessione dedicata da 6h, keep-alive, marker a posteriori); registrazioni rinumerate |
| **2.5.0** (30-07-2026) | archiviazione su più volumi: dischi esterni, destinazioni per tipo media, override per sorgente, migrazione v4→v5, drag&drop in Impostazioni e volumi nella barra di stato |
| **2.4.4** (29-07-2026) | profilo `hd`, forbice fra i profili allargata (fattore di peso da 2,5 a ~5,8) |
| **2.4.3** (28-07-2026) | `duration_seconds` = durata del contenuto; cue tagliati sulla timeline reale anche con buchi |
| **2.4.2** (27-07-2026) | watchdog di stallo; `-movflags` propagati al muxer mp4 figlio in modalità segmentata |
| **2.4.1** (27-07-2026) | anteprima live riscritta su relay ffmpeg→HLS, estesa all'audio e a `recording.php`; pulsante "Check"; badge AUDIO/VIDEO |
| **2.4.0** (27-07-2026) | ripresa automatica dopo caduta dello stream; SQLite in WAL con `busy_timeout` e un solo scrittore per evento |
| **2.3.0** (27-07-2026) | profili di qualità video per sorgente; `extra_opts` finalmente applicate; colonna Dimensione |
| **2.2.1** (27-07-2026) | nomi di default marker/cue, barra di autosalvataggio configurabile, fix del segmento finale vuoto |
| **2.2.0** (27-07-2026) | pre-roll/post-roll dei cue configurabili da interfaccia |
| **2.1.0** | Fluxus Remote |

---

## Convenzioni per chi ci lavora

- Tutto il naming ha prefisso `fm` / `FM_`: costanti (`FM_BASE`, `FM_CLIPS`),
  helper (`fmDB`, `fmJson`, `fmError`), classi CSS (`fm-*`).
- I percorsi passano **sempre** dalle costanti `FM_*` (in bash, dalle variabili
  che `fluxus-env.sh` valorizza), mai scritti a mano. Lo stesso vale per
  l'utente di sistema, il prefisso dei servizi e gli indirizzi del server RTMP:
  un valore reintrodotto a mano rompe la seconda installazione senza che nulla
  lo segnali, perché la prima continua a funzionare.
- Interfaccia in italiano, UIkit 3 in dark mode, badge AUDIO blu `#1565c0` e
  VIDEO viola `#6a1b9a`.
- Le migrazioni di schema si aggiungono a `db_init.php`, non a `schema.sql`.
- Prima di modificare `record.sh`, leggere il vincolo 1 di
  [NOTE-TECNICHE.md](NOTE-TECNICHE.md).
