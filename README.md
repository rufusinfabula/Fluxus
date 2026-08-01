# Fluxus

**Registratore audio e video programmato, per Raspberry Pi.**
Registra flussi in diretta secondo un palinsesto, permette di marcare gli
istanti interessanti mentre vanno in onda, e ne estrae automaticamente le clip.

Pensato per una radio o una piccola produzione: una macchina accesa in un
angolo che registra da sola, giorno dopo giorno, e a cui si chiede conto dal
browser. Nessun servizio cloud, nessuna porta aperta verso Internet, nessun
abbonamento.

> **Stato: in lavorazione (`0.3.0`, tre fasi su sei).**
> Da questa versione Fluxus **si installa** con un comando, e si può installare
> due volte sulla stessa macchina senza che le due copie si tocchino. Quel che
> manca per la 1.0 sta nella [roadmap](docs/ROADMAP.md): configurazione della
> rete dal browser, procedura guidata al primo accesso, immagine SD pronta.
>
> Fatto finora: percorsi, nomi e utente di sistema non sono più scritti nel
> codice ma vengono da un file di configurazione, uno per installazione;
> l'interfaccia non chiede più niente a Internet, perché la macchina che va
> configurata è proprio quella che una connessione non ce l'ha ancora; e c'è
> l'installer, con il comando `fluxus` per governare ciò che ha installato.

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

## Installazione

Da una copia del sorgente, sulla macchina che dovrà registrare:

```bash
sudo ./install.sh
```

Installa dipendenze, cartelle, applicazione, servizi, permessi, server web e
server RTMP, prepara il database e finisce stampando l'indirizzo a cui
collegarsi. Funziona sia in un terminale con monitor sia via SSH su una macchina
senza schermo; se un terminale non c'è — dentro uno script, in una pipe — non fa
domande e prosegue con i valori predefiniti.

Prima di fidarsi, `sudo ./install.sh --dry-run` mostra ogni singola azione senza
compierne nessuna.

Un'installazione di prova accanto a una già in servizio si fa dandole un nome:

```bash
sudo ./install.sh --instance fluxus-dev
```

Da lì in poi la si governa con il comando `fluxus`:

```bash
sudo fluxus status                       # come sta
sudo fluxus update                       # riaggiorna dal sorgente
sudo fluxus backup                       # database, configurazione e segreti
sudo fluxus logs -f                      # cosa sta succedendo
sudo fluxus uninstall                    # via tutto, i dati restano
```

Con più installazioni sulla stessa macchina, `fluxus list` le elenca e
`--instance <nome>` sceglie su quale lavorare. Le opzioni complete: `--help` su
entrambi i comandi.

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
install.sh   installer: installa e aggiorna un'istanza
app/         applicazione web PHP
scripts/     registrazione, estrazione clip, retention, anteprima
bin/         comandi di sistema: il comando 'fluxus' e lo script privilegiato
systemd/     modelli dei servizi periodici e del server RTMP
nginx/       modello della configurazione del server web
config/      modelli dei file di configurazione
packaging/   aggiornamento delle dipendenze dell'interfaccia
docs/        documentazione
VERSION      unica fonte del numero di versione
```

## Licenza

Da definire prima della pubblicazione — vedi [roadmap](docs/ROADMAP.md).
