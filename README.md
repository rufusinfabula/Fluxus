# Fluxus

**Registratore audio e video programmato, per Raspberry Pi.**
Registra flussi in diretta secondo un palinsesto, permette di marcare gli
istanti interessanti mentre vanno in onda, e ne estrae automaticamente le clip.

Pensato per una radio o una piccola produzione: una macchina accesa in un
angolo che registra da sola, giorno dopo giorno, e a cui si chiede conto dal
browser. Nessun servizio cloud, nessuna porta aperta verso Internet, nessun
abbonamento.

> **Stato: in lavorazione (`0.2.0`, due fasi su sei).**
> Il software funziona ed è in uso quotidiano, ma **non è ancora installabile
> da questo repository**: l'installer è la voce principale della
> [roadmap](docs/ROADMAP.md). Fino ad allora questo è il sorgente, non un
> pacchetto pronto.
>
> Fatto finora: percorsi, nomi e utente di sistema non sono più scritti nel
> codice ma vengono da un file di configurazione, uno per installazione — il
> che permetterà di tenere sulla stessa macchina un'installazione in uso e una
> di collaudo. E l'interfaccia non chiede più niente a Internet: stile, icone,
> player e font sono nel pacchetto, perché la macchina che va configurata è
> proprio quella che una connessione non ce l'ha ancora.

---

## Cosa fa

- **Registrazione programmata**: palinsesti settimanali con slot di durata
  arbitraria, anche segmentati in file da N minuti.
- **Sorgenti audio e video**: HTTP, RTMP, RTSP, SRT, webcam locali (v4l2), e
  ricezione di push RTMP da un encoder esterno (OBS, Wirecast, telecamere).
- **Marker e cue in diretta**: un pulsante mentre la trasmissione è in onda.
  I *marker* segnano un istante; i *cue* fanno estrarre da soli una clip che
  comincia prima del click (perché ci si accorge sempre dopo che valeva la
  pena).
- **Marker anche da fuori casa**, tramite un relay esterno opzionale: la
  macchina non riceve mai connessioni in ingresso, è lei a chiedere.
- **Archiviazione su più dischi**: destinazioni distinte per audio e video, e
  dischi USB abilitabili dal browser.
- **Resiliente per costruzione**: riprende da sola se lo stream cade, se ne
  accorge se si congela, non perde ciò che ha già scritto.

## Requisiti

- **Raspberry Pi 5** (consigliato), Pi 4 o Pi 3 — oppure un qualsiasi PC o
  mini-PC x86-64.
- **Raspberry Pi OS Bookworm (64 bit)** o Debian 12 / Ubuntu 22.04+.
- nginx, PHP 8.2, SQLite, ffmpeg, systemd.
- Spazio disco proporzionato: il video in copia diretta pesa circa **2 GB/ora**,
  ricodificato da 0,16 a 0,92 GB/ora a seconda del profilo scelto.

⚠️ **Fluxus gira solo su Linux**, e non per scelta stilistica: il palinsesto
*è* fatto di timer systemd, lo stato della macchina si legge da `/proc`, i
dischi passano da `/proc/mounts` e `/etc/fstab`, le webcam da v4l2. Su macOS e
Windows non esiste nulla di tutto questo. Da quelle macchine si usa il browser
per raggiungere il Pi, che è poi il modo normale di lavorarci.

## Documentazione

| | |
|---|---|
| [Manuale d'uso](docs/MANUALE.md) | per chi lo usa tutti i giorni |
| [Architettura](docs/ARCHITETTURA.md) | com'è fatto: schema dati, script, servizi |
| [Note tecniche](docs/NOTE-TECNICHE.md) | **perché** è fatto così: misure sul campo, vincoli critici, strade già provate e scartate |
| [Roadmap](docs/ROADMAP.md) | cosa manca per arrivare alla 1.0 |
| [Changelog](docs/CHANGELOG.md) | storia delle versioni |

Chi mette le mani nel codice legga le **note tecniche** prima di toccare la
registrazione, l'estrazione delle clip o l'archiviazione: i vincoli elencati in
fondo a quel documento nascono da guasti reali, e più d'uno è costato
registrazioni perdute.

## Struttura del repository

```
app/         applicazione web PHP
scripts/     registrazione, estrazione clip, retention, anteprima
bin/         comandi di sistema e script privilegiati
systemd/     modelli dei servizi periodici
nginx/       modello della configurazione del server web
config/      modelli dei file di configurazione
packaging/   costruzione dell'immagine SD
docs/        documentazione
VERSION      unica fonte del numero di versione
```

## Licenza

Da definire prima della pubblicazione — vedi [roadmap](docs/ROADMAP.md).
