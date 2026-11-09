# Fluxus

**Registratore audio e video programmato, per Raspberry Pi.**
Registra flussi in diretta secondo un palinsesto, permette di marcare gli
istanti interessanti mentre vanno in onda, e ne estrae automaticamente le clip.

Pensato per una radio o una piccola produzione: una macchina accesa in un
angolo che registra da sola, giorno dopo giorno, e a cui si chiede conto dal
browser. Nessun servizio cloud, nessuna porta aperta verso Internet, nessun
abbonamento.

> **Stato: `1.0.0`, la prima versione stabile.**
> Fluxus registra in produzione ogni giorno, **si installa** con un comando da
> chi sa usare un terminale, e si può installare due volte sulla stessa
> macchina senza che le due copie si tocchino. Percorsi e nomi vengono da un
> file di configurazione per installazione, l'interfaccia non chiede niente a
> Internet, e il comando `fluxus` governa ciò che l'installer ha messo.
>
> C'è una pagina Rete: stato, ricerca delle reti WiFi e nome della macchina
> sono collaudati; cambio di rete, cambio di indirizzo IP e hotspot sono
> **sperimentali**, e l'hotspot non si accende più da solo al riavvio.
>
> La versione "pronta all'uso" per chi un terminale non lo usa — procedura
> guidata al primo accesso, immagine SD da scrivere e accendere, hotspot
> collaudato — è spostata alla **1.5**: vedi la [roadmap](docs/ROADMAP.md).

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

**In arrivo** (`2.0`, vedi [roadmap](docs/ROADMAP.md#più-avanti)): una sezione
EDIT unica per ritagliare clip da cue, segmenti e file audio unici, con editor
a forma d'onda direttamente dal browser.

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

Sulla macchina che dovrà registrare, già collegata a Internet, un solo
comando:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rufusinfabula/fluxus/HEAD/get.sh)
```

Chiede quale versione installare — invio per l'ultima, `t` per vederle tutte
— scarica il sorgente in `/opt/fluxus` e lancia l'installer. Chi preferisce
leggerlo prima di eseguirlo lo scarica, lo guarda e poi lo lancia:

```bash
curl -fsSLO https://raw.githubusercontent.com/rufusinfabula/fluxus/HEAD/get.sh
less get.sh
bash get.sh
```

Per aggiornarla più avanti: `sudo fluxus update` prende le correzioni della
versione installata e dice se ne è uscita una più nuova; `sudo fluxus update
--to` per passarci. Le versioni stabili sono i branch `0.4`, `1.0`, `1.1`…; lo
sviluppo avviene su `dev`, che non è una versione rilasciata.

L'installer installa dipendenze, cartelle, applicazione, servizi, permessi, server web e
server RTMP, prepara il database e finisce stampando l'indirizzo a cui
collegarsi. Funziona sia in un terminale con monitor sia via SSH su una macchina
senza schermo; se un terminale non c'è — dentro uno script, in una pipe — non fa
domande e prosegue con i valori predefiniti.

Prima di fidarsi, `sudo ./install.sh --dry-run` mostra ogni singola azione senza
compierne nessuna.

⚠️ **L'installazione nasce senza password**: l'interfaccia è aperta a chiunque
sia sulla stessa rete. Al primo collegamento vai in **Impostazioni → Nodo**,
spunta *Richiedi autenticazione* e imposta una password.

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
`--instance <nome>` sceglie su quale lavorare; senza, da un terminale, il
comando chiede quale. Tutti i comandi, con le ricette più usate, sono in
[docs/COMANDI.md](docs/COMANDI.md) e, già scritti per la propria
installazione, in Impostazioni → Comandi da terminale.

## Documentazione

| | |
|---|---|
| [Manuale d'uso](docs/MANUALE.md) | per chi lo usa tutti i giorni |
| [Comandi da terminale](docs/COMANDI.md) | per chi gestisce la macchina: `fluxus`, l'installer, le ricette |
| [Sviluppo e rilascio](docs/SVILUPPO.md) | per chi ci lavora: regole di sviluppo, collaudo, rilascio, manutenzione |
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

Fluxus — registratore audio e video programmato per Raspberry Pi.
Copyright © 2026 Fabio Ranfi.

Distribuito secondo i termini della **GNU Affero General Public License v3.0**
(o, a scelta di chi lo usa, una versione successiva) — testo completo in
[LICENSE](LICENSE). In breve: si può usare, modificare e ridistribuire
liberamente; chi fa girare una versione modificata come servizio raggiungibile
in rete deve mettere a disposizione di chi lo usa il codice sorgente di quella
versione, comprese le modifiche.

Le dipendenze dell'interfaccia in `app/assets/vendor/` (UIkit, hls.js,
wavesurfer.js, il font Recursive) restano ciascuna con la propria licenza
originale — MIT, Apache-2.0, BSD-3-Clause, OFL-1.1 — elencate in
[app/assets/vendor/LICENSES/](app/assets/vendor/LICENSES/); non fanno parte del
codice di Fluxus e non passano sotto l'AGPL.
