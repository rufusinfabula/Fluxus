# Comandi da terminale

Tutto quello che si fa con Fluxus da un terminale sulla macchina, anche via
SSH. Quasi tutti i comandi vogliono `sudo`.

Le stesse ricette, già scritte con il nome della propria installazione, si
trovano anche nell'interfaccia: **Impostazioni → Comandi da terminale**. Lì c'è
anche l'aiuto completo del comando `fluxus` installato, letto dal vivo.

Negli esempi l'installazione si chiama `fluxus`. Se sulla macchina ce n'è una
sola, `--instance fluxus` si può omettere; se ce ne sono più d'una e non la si
indica, da un terminale il comando chiede quale.

---

## Le ricette più usate

**Come sta l'installazione** — versione, servizi, database, spazio, indirizzo,
registrazioni in corso:

```bash
sudo fluxus --instance fluxus status
```

**Cosa sta succedendo, in diretta** (Ctrl+C per uscire):

```bash
sudo fluxus --instance fluxus logs -f
```

**Il diario di una registrazione** (il numero è quello della registrazione):

```bash
sudo fluxus --instance fluxus logs record 46
```

**C'è una registrazione in corso?** Da controllare prima di ogni aggiornamento:
non deve stampare niente. Il database va aperto **sempre come `www-data`**,
mai come un altro utente, nemmeno solo per leggere: è in modalità WAL, e un
altro utente lascerebbe file che PHP poi non riesce più a scrivere.

```bash
sudo -u www-data sqlite3 -cmd ".timeout 5000" /var/lib/fluxus/db/fluxus_media.db \
  "SELECT id, source_name FROM recordings WHERE status='recording';"
```

**Backup** di database, configurazione e chiavi (le registrazioni non sono
incluse):

```bash
sudo fluxus --instance fluxus backup
sudo fluxus --instance fluxus backup --with-media   # anche registrazioni e clip: può essere enorme
```

---

## Installare da GitHub

Su una macchina con Raspberry Pi OS Bookworm (64 bit), Debian 12 o Ubuntu
22.04+, già collegata a Internet:

```bash
sudo apt install -y git
git clone https://github.com/rufusinfabula/fluxus.git
cd fluxus
sudo ./install.sh --dry-run    # mostra ogni azione, senza farne nessuna
sudo ./install.sh
```

⚠️ L'installazione nasce **senza password**. Al primo collegamento:
Impostazioni → Nodo → *Richiedi autenticazione*, e una password.

## Aggiornare

Dalla cartella del sorgente:

```bash
git pull
sudo ./install.sh --instance fluxus --dry-run
sudo ./install.sh --instance fluxus
sudo fluxus --instance fluxus status
```

Oppure, se il sorgente è già aggiornato, `sudo fluxus --instance fluxus update`
rilancia l'installer dal sorgente usato l'ultima volta. L'installer si rifiuta
di procedere con una registrazione in corso (a meno di `--force`).

## Un'installazione di prova accanto a quella in servizio

Si fa dandole un altro nome: percorsi, servizi, permessi e server RTMP sono
tutti separati.

```bash
sudo ./install.sh --instance fluxus-dev
sudo fluxus --instance fluxus-dev status
```

---

## Il comando `fluxus`

```
fluxus [--instance NOME] <comando> [opzioni]
```

| Comando | Cosa fa |
|---|---|
| `status` | versione, percorsi, servizi, database, spazio, indirizzo |
| `list` | le installazioni presenti su questa macchina |
| `config` | la configurazione dell'installazione, come la vede Fluxus |
| `logs` | gli ultimi log applicativi |
| `logs -f` | gli stessi, in diretta |
| `logs record ID` | il log di una registrazione |
| `logs services` | il giornale dei servizi systemd |
| `logs NOME` | un file in `<cartella dati>/logs` |
| `update [--from DIR]` | aggiorna dal sorgente (predefinito: quello dell'ultima installazione) |
| `backup [--out FILE] [--with-media]` | archivio di database, configurazione e segreti (in `/var/backups/fluxus/`) |
| `restore FILE` | rimette a posto un archivio prodotto da `backup` |
| `uninstall [--purge] [--yes]` | rimuove servizi, permessi, regole web e applicazione; i dati restano, a meno di `--purge` |

Opzioni generali: `--instance NOME` (su quale installazione), `--yes` (nessuna
domanda), `--help`.

Con più installazioni e senza `--instance`: da un terminale il comando mostra
un elenco numerato e chiede quale; dentro uno script, in una pipe o con
`--yes` si ferma ed elenca, senza scegliere da solo.

## L'installer `install.sh`

Le opzioni che servono più spesso. L'elenco completo è `./install.sh --help`.

| Opzione | Cosa fa |
|---|---|
| `--instance NOME` | nome dell'installazione (predefinito `fluxus`): da qui derivano percorsi, servizi e permessi |
| `--dry-run` | mostra cosa farebbe, senza toccare niente |
| `--yes` | non fa domande, usa i valori predefiniti |
| `--force` | prosegue anche con una registrazione in corso |
| `--no-deps` | non installa pacchetti |
| `--quiet` | stampa solo il riepilogo finale |
| `--data-dir`, `--web-dir`, `--web-base` | percorsi diversi dai predefiniti |
| `--rtmp-port`, `--mediamtx-api-port` | porte del server RTMP dell'installazione |
| `--volume-helper overwrite` | sostituisce `/usr/local/bin/fluxus-enable-volume.sh` se è diverso da quello del sorgente (con copia di sicurezza) |
| `--network-helper overwrite`, `--connect-conf-helper overwrite` | lo stesso, per gli altri due script di sistema |

Senza un terminale (pipe, script, cron) l'installer non fa domande e prosegue
come con `--yes`.

---

## Script di sistema da non lanciare a mano

In `/usr/local/bin` ci sono tre script che girano come root su richiesta
dell'interfaccia, attraverso regole `sudo` precise per ogni installazione:
`fluxus-enable-volume.sh` (dischi), `fluxus-network.sh` (rete),
`fluxus-write-connect-conf.sh` (Fluxus Connect). Si aggiornano con
l'installer, non si lanciano a mano.

## Funzioni sperimentali

L'hotspot di primo avvio è installato ma **non abilitato** (dalla `0.4.14`):
non è ancora stato collaudato dal vivo. Chi lo vuole provare, con un cavo
Ethernet come via di rientro:

```bash
sudo systemctl enable fluxus-hotspot-check.service    # scatta al prossimo riavvio
sudo systemctl disable fluxus-hotspot-check.service   # per spegnerlo di nuovo
```
