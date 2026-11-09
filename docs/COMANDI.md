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
22.04+, già collegata a Internet, un solo comando:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rufusinfabula/fluxus/HEAD/get.sh)
```

Chiede quale versione installare:

```
Versioni disponibili:

   1) 1.0   (1.0.0)    ultima, consigliata
   2) 0.4   (0.4.16)

   t) tutte le versioni

Quale installo? [invio = 1]
```

Invio installa l'ultima; `t` mostra tutte le versioni e anche `dev`, la
versione in sviluppo, da usare solo per prove. Poi scarica il sorgente in
`/opt/fluxus` e lancia l'installer, che fa le sue domande come sempre.

Opzioni, da scrivere dopo il comando (`bash <(curl …) --version 1.0`):

| Opzione | Cosa fa |
|---|---|
| `--version X.Y` | installa quella versione senza menu (`dev` = in sviluppo) |
| `--dir PERCORSO` | dove tenere il sorgente (predefinito `/opt/fluxus`) |
| tutte le altre | passano a `install.sh`: `--instance`, `--dry-run`, `--yes`… |

Senza un terminale (dentro uno script) installa l'ultima versione senza
domande.

A mano, senza `get.sh`:

```bash
sudo apt install -y git
git clone --branch 1.0 https://github.com/rufusinfabula/fluxus.git
cd fluxus
sudo ./install.sh --dry-run    # mostra ogni azione, senza farne nessuna
sudo ./install.sh
```

⚠️ L'installazione nasce **senza password**. Al primo collegamento:
Impostazioni → Nodo → *Richiedi autenticazione*, e una password.

## Aggiornare

```bash
sudo fluxus --instance fluxus update          # le correzioni della versione installata
sudo fluxus --instance fluxus update --to     # elenca le versioni più nuove e chiede
sudo fluxus --instance fluxus update --to 1.1 # passa direttamente alla 1.1
```

Per un'installazione fatta da GitHub (`get.sh`, o un clone su un branch di
versione come `1.0`), `update` scarica le correzioni di quella versione,
avvisa se ne esiste una più nuova e rilancia l'installer. Il passaggio a
un'altra versione non avviene mai da solo: serve `--to`. Se nel sorgente ci
sono modifiche non salvate, non lo tocca.

Se invece il sorgente è una copia di lavoro su `dev` (chi sviluppa Fluxus),
`update` non scarica niente: rilancia l'installer da quella cartella così
com'è. In quel caso si aggiorna a mano:

```bash
cd <cartella del sorgente>
sudo ./install.sh --instance fluxus --dry-run
sudo ./install.sh --instance fluxus
```

L'installer si rifiuta di procedere con una registrazione in corso (a meno di
`--force`).

## Versioni

Le versioni stabili sono i branch `0.4`, `1.0`, `1.1`… su GitHub: è da lì che
installa `get.sh`. Lo sviluppo avviene sul branch `dev`, che non è una
versione rilasciata. Ogni versione precisa ha anche un tag (`v1.0.0`,
`v0.4.16`…).

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
| `update` | aggiorna dal sorgente dell'ultima installazione; se viene da GitHub, prima ne scarica le correzioni |
| `update --to [X.Y]` | passa a un'altra versione (senza numero le elenca) |
| `update --from DIR` | aggiorna da un altro sorgente |
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
