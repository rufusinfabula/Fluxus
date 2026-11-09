# Sviluppo, collaudo, rilascio e manutenzione

Le regole con cui si lavora su Fluxus, raccolte in un posto solo. Ognuna
nasce da un caso reale — più d'una da un guasto che è costato registrazioni.
Il *perché* tecnico di ciascuna sta nelle [note tecniche](NOTE-TECNICHE.md);
qui c'è il *come* si lavora.

In breve: **si lavora su `dev`, si prova sull'installazione di collaudo, si
rilascia solo per decisione esplicita, si aggiorna la produzione per ultima e
con i propri comandi.**

---

## 1. Le tre cartelle

Sulla macchina di chi sviluppa convivono tre cose da non confondere:

| | Dove | Cos'è |
|---|---|---|
| **Sorgente** | la copia di lavoro del repository, sul branch `dev` | qui si scrive il codice. Non gira niente |
| **Collaudo** | un'istanza di prova, per esempio `fluxus-dev` (`/var/www/fluxus-dev`, `/var/lib/fluxus-dev`) | qui si prova. Ha un suo server RTMP, suoi servizi, suo database |
| **Produzione** | l'istanza principale, per esempio `fluxus` (`/var/www/fluxus`, `/var/lib/fluxus`) | registra davvero, ogni giorno. **Non ci si prova mai** |

La produzione *gira* dalle sue cartelle; il sorgente serve solo quando la si
aggiorna, perché l'installer copia da lì.

## 2. Regole invarianti

1. **Niente firme di strumenti nei commit.** Né nel codice, né nella
   documentazione, né nei messaggi di commit: nessun trailer di co-autore. Un
   hook `pre-commit` locale lo controlla; vive in `.git/hooks/`, quindi **va
   reinstallato dopo ogni clone**, e il suo contenuto non sta nel repository.
2. **Niente valori della macchina** nel repository: indirizzi IP, etichette di
   dischi, UUID, chiavi, nomi di nodo. Nei file di configurazione stanno solo
   gli `.example`. L'hook controlla anche questo.
3. **Nessun database, nessun backup** committato.
4. **Prima di cambiare registrazione, estrazione delle clip o archiviazione**
   si legge il vincolo corrispondente nelle [note tecniche](NOTE-TECNICHE.md),
   sezione *Vincoli critici*. `scripts/record.sh` è il file più delicato del
   progetto.
5. **Il database di produzione si apre solo come l'utente di Fluxus**
   (`www-data`), mai come un altro utente, nemmeno in sola lettura. È in
   modalità WAL: anche una `SELECT` crea i file `-wal`/`-shm` con il
   proprietario di chi l'ha lanciata, e da lì PHP non riesce più a scrivere.
   È già successo.
6. **Il database di produzione non si scrive mai a mano**, nemmeno per una
   pulizia banale. Ci pensano l'applicazione, gli script e le migrazioni.
7. `settings.schema_version` è il contatore delle migrazioni del database,
   non la versione del prodotto: non si tocca a mano.

## 3. Versioni e branch

```
dev   ──●──●──●──●──●──●──●──●──►   lavoro (oggi verso la 1.0.1)
              │        │
              0.4      1.0          versioni stabili: da qui si installa
           v0.4.16   v1.0.0         (tag fissi su ogni versione)
```

- **Si lavora solo su `dev`.** `VERSION` sale una versione dopo l'altra —
  1.0.0, 1.0.1 … 1.1.0 — e non esistono due versioni con lo stesso numero.
  Correzioni sulla terza cifra, novità sulla seconda.
- **Ogni versione fatta ha il suo tag** `vX.Y.Z`, sull'ultimo commit di
  quella versione.
- **I branch stabili** `0.4`, `1.0`, `1.1`… non si lavorano mai a mano: sono
  segnalibri su una versione di `dev` dichiarata stabile. Un branch stabile
  avanza lungo `dev` finché questo resta nella stessa serie (il `1.0` può
  passare dalla 1.0.0 alla 1.0.6); per la 1.1.0 nasce il branch `1.1`.
  Conseguenza accettata: quando `dev` è già alla 1.1.x, una correzione esce
  come 1.1.x, non come 1.0.x.
- **Il branch predefinito di GitHub è sempre l'ultima versione stabile.** È
  quello che vede chi apre la pagina del progetto, quello che prende un
  `git clone` senza opzioni, e soprattutto quello da cui il comando unico
  scarica `get.sh`: un errore su `dev` non deve poter rompere l'installazione
  di nessuno.
- Nel [CHANGELOG](CHANGELOG.md) la versione in lavorazione su `dev` ha la sua
  sezione, segnata *in lavorazione*, finché non è fatta.

## 4. Sviluppo

Su `dev`, per ogni modifica:

1. Leggere i vincoli che la riguardano (regola 2.4).
2. Scrivere il codice come quello intorno: stessa lingua (italiano), stessa
   densità di commenti, stessi nomi. I commenti spiegano il *perché*, e quando
   nasce da un guasto lo dicono con la data.
3. Controlli rapidi:
   ```bash
   php -l <file.php>                    # ogni PHP toccato
   bash -n <script.sh>                  # ogni script toccato
   packaging/vendor-assets.sh --check   # se si toccano le dipendenze dell'interfaccia
   ```
4. **Migrazioni del database**: un nuovo blocco `if ($_fm_cur < N)` in
   `app/includes/db_init.php`, mai riscrivere lo schema per i database già in
   servizio; la colonna nuova va anche in `app/db/schema.sql` per le
   installazioni da zero. Se una colonna nuova cambia il significato di dati
   esistenti, la migrazione li riempie in modo che niente cambi comportamento
   (esempio: `clip_ready_at = created_at` per le clip già pronte).
5. **Documentazione nello stesso commit del codice**: [CHANGELOG](CHANGELOG.md)
   sempre; [note tecniche](NOTE-TECNICHE.md) per il perché e i vincoli;
   [manuale](MANUALE.md) per ciò che vede chi usa Fluxus;
   [comandi](COMANDI.md) per ciò che si lancia da terminale;
   [README](../README.md), [architettura](ARCHITETTURA.md) e
   [roadmap](ROADMAP.md) quando cambia lo stato o la struttura. Mai dichiarare
   "fatto" o "collaudato" ciò che non è stato provato: si scrive cosa è stato
   provato e cosa no.
6. Funzioni non collaudate **non si lasciano accese di default**: si spengono
   o si segnano *sperimentale* nell'interfaccia (come l'hotspot e il cambio di
   rete dalla 0.4.14).

Messaggio di commit: in italiano, una riga che dice cosa cambia per chi usa
Fluxus, con la versione in fondo — `Cue scaduti: si cancella solo la clip…:
0.4.13`.

## 5. Collaudo

Si prova **sempre** sull'istanza di collaudo, **mai** sulla produzione.

```bash
# 1. nessuna registrazione in corso in produzione (non deve stampare niente)
sudo -u www-data sqlite3 -cmd ".timeout 5000" /var/lib/<istanza>/db/fluxus_media.db \
  "SELECT id, source_name FROM recordings WHERE status='recording';"

# 2. prova a vuoto, poi installazione sul collaudo
sudo ./install.sh --instance fluxus-dev --dry-run
sudo ./install.sh --instance fluxus-dev
sudo fluxus --instance fluxus-dev status
```

- ⚠️ **File condivisi.** Alcune cose sono uniche per tutta la macchina e le
  usa anche la produzione: il comando `/usr/local/bin/fluxus`, gli helper in
  `/usr/local/bin` (`fluxus-enable-volume.sh`, `fluxus-network.sh`,
  `fluxus-write-connect-conf.sh`), l'unit dell'hotspot. Installare il collaudo
  le aggiorna anche per la produzione. La prova a vuoto lo mostra: se succede,
  lo si decide prima, non dopo.
- **Prove di registrazione video** sul collaudo: si crea una sorgente
  `rtmp-push` e la si alimenta con un segnale di prova di ffmpeg sulla porta
  RTMP del collaudo, per esempio
  `ffmpeg -re -f lavfi -i testsrc2=size=640x360:rate=30 -f lavfi -i sine … -f flv rtmp://127.0.0.1:<porta>/<id-sorgente>`,
  con segmenti corti per provare i casi a cavallo fra due file. Finita la
  prova, sorgente e registrazione di prova si tolgono.
- Le scadenze (retention) si provano invecchiando le date **nel database del
  collaudo**, mai in quello di produzione.
- Le parti che toccano la rete (hotspot, cambio WiFi o IP) si provano solo con
  un collegamento di riserva — cavo Ethernet — e meglio su una seconda
  macchina: con una sola scheda WiFi tolgono la rete anche alla produzione.
- Quando una prova scarica da Internet o installa qualcosa, lo si fa in una
  cartella di prova, senza toccare ciò che usa la produzione.

## 6. Rilascio

Quando una versione su `dev` è fatta e collaudata:

1. La sua sezione nel [CHANGELOG](CHANGELOG.md) perde l'indicazione *in
   lavorazione*; README e roadmap riportano lo stato.
2. Commit e push su `dev`, poi il tag:
   ```bash
   git tag -a vX.Y.Z -m "Fluxus X.Y.Z"
   git push origin dev vX.Y.Z
   ```
3. **Solo per decisione esplicita di chi mantiene il progetto**, il branch
   stabile:
   ```bash
   # stessa serie: il branch avanza
   git push origin "vX.Y.Z^{commit}:refs/heads/X.Y"
   # serie nuova: nasce il branch, e diventa il predefinito
   git branch X.Y vX.Y.Z && git push origin X.Y
   gh api -X PATCH repos/<proprietario>/fluxus -f default_branch=X.Y
   ```
4. Controllo dal punto di vista di chi installa: il comando unico deve
   proporre la nuova versione come *ultima, consigliata*.
   ```bash
   bash <(curl -fsSL https://raw.githubusercontent.com/<proprietario>/fluxus/HEAD/get.sh) --dir /tmp/prova-fluxus --instance fluxus-dev --dry-run
   ```
5. Su `dev`, `VERSION` passa alla versione successiva, con la sua sezione
   *in lavorazione* nel CHANGELOG.

## 7. Aggiornare la produzione

Sempre **dopo** il collaudo, e con questi passi:

```bash
cd <sorgente>
# 1. nessuna registrazione in corso (non deve stampare niente)
sudo -u www-data sqlite3 -cmd ".timeout 5000" /var/lib/<istanza>/db/fluxus_media.db \
  "SELECT id, source_name FROM recordings WHERE status='recording';"
# 2. backup — indispensabile quando la versione porta una migrazione del database
sudo fluxus --instance <istanza> backup
# 3. prova a vuoto, poi installazione
sudo ./install.sh --instance <istanza> --dry-run
sudo ./install.sh --instance <istanza>
# 4. controllo
sudo fluxus --instance <istanza> status
```

Dopo l'aggiornamento si verifica che:
- versione e schema del database siano quelli attesi;
- applicazione, script e comandi installati coincidano con il sorgente;
- servizi e registrazioni programmate siano attivi;
- i file del database (compresi `-wal` e `-shm`) appartengano ancora a
  `www-data`.

Un'installazione fatta da GitHub si aggiorna con `sudo fluxus update` (le
correzioni della sua versione) e `sudo fluxus update --to X.Y` (un'altra
versione): vedi [COMANDI.md](COMANDI.md).

## 8. Manutenzione della macchina

- **Pulizie** fuori dal repository (vecchie installazioni, file di sistema,
  dati sui dischi): prima l'inventario completo — cosa c'è, chi lo usa, se un
  database ci punta ancora — poi la proposta divisa per gruppi, poi un
  archivio di ciò che si toglie in `/root/`, e solo allora la rimozione.
  Prima di togliere regole `sudo` si controlla la sintassi con `visudo -c`;
  dopo modifiche a nginx, `nginx -t`.
- I file che non appartengono a Fluxus — il vhost di nginx è spesso condiviso
  con altri progetti, e così le loro copie di sicurezza — non si toccano.
- Gli **helper di sistema** in `/usr/local/bin` sono condivisi fra istanze:
  l'installer non li sostituisce se sono diversi dal sorgente (`keep`) e lo
  segnala; si allineano con `--volume-helper overwrite` (e simili) quando
  nessun'altra installazione dipende dalla versione vecchia. L'installer
  lascia una copia di sicurezza accanto.

## 9. Decisioni riservate a chi mantiene il progetto

Queste azioni non si fanno mai di iniziativa, nemmeno quando sono il passo
successivo di un piano già approvato: ogni volta si chiede e si aspetta il
via.

1. creare un branch stabile o farlo avanzare da `dev`;
2. cambiare il branch predefinito su GitHub;
3. rinominare o cancellare branch o tag su GitHub, cambiare le impostazioni
   del repository;
4. qualunque azione sulla produzione: si preparano i comandi, li lancia chi
   la gestisce;
5. modifiche a file di sistema condivisi (helper in `/usr/local/bin`, regole
   `sudo`, unit di macchina, nginx) — anche quando arrivano installando il
   collaudo — e cancellazioni fuori dal repository;
6. cambiare queste regole.
