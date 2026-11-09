# Roadmap — da installazione unica a prodotto installabile

Fluxus funziona ed è in uso quotidiano, ma finora è esistito come **una sola
installazione**, cresciuta a mano su una macchina. Questo repository serve a
trasformarlo in un pacchetto che chiunque possa installare su un Raspberry Pi
o un mini-PC, con un comando, anche senza monitor collegato.

Le fasi sono pensate per essere lavorate **una per volta**, ognuna verificabile
per conto suo. **Il numero di fase e quello di versione coincidono**: la fase 1
chiude sulla `0.1.0`, la fase 4 sulla `0.4.0`. Le fasi 5 e 6 (configurazione
guidata, immagine SD) sono state spostate alla **1.5** il 2026-11-09: dalla
`0.4.x` si va alla `1.0.0`, vedi *Verso la 1.0* più sotto.

## Punto di partenza

Cosa manca oggi, in concreto:

- ~~**Nessun installer funzionante**~~ — risolto dalla fase 3.
- ~~**Percorsi cablati nel codice**~~ — risolto dalla fase 1.
- ~~**Dipendenza da Internet per l'interfaccia**~~ — risolto dalla fase 2.
- ~~**Nessun modo di configurare la rete** se non da riga di comando~~ —
  risolto dalla fase 4.

---

## Fase 1 — Un solo punto di verità per percorsi e nomi → `0.1.0` ✅

**Fatta.** Un file di configurazione unico, letto sia dall'applicazione sia
dagli script, che stabilisce cartella dati, radice web, sottopercorso, utente di
sistema, indirizzi del server RTMP e **nome dell'istanza**.

Il nome dell'istanza è ciò che permette di installare Fluxus **due volte sulla
stessa macchina** senza collisioni: da esso derivano i nomi dei servizi, del
file di configurazione, delle regole del server web e dei permessi. Serve
subito per collaudare il pacchetto accanto a un'installazione in produzione.

Servizi, regole del server web e permessi sono diventati **modelli** con
segnaposto: la fase 3 deve solo renderli. Vedi il
[changelog](CHANGELOG.md#010) e, per il perché di ogni scelta, la sezione
*Configurazione dell'istanza* delle [note tecniche](NOTE-TECNICHE.md).

## Fase 2 — L'interfaccia funziona senza Internet → `0.2.0` ✅

**Fatta.** Fogli di stile, icone, player e font sono nel pacchetto e li serve
Fluxus stesso. Nessuna pagina chiede più niente a nessuno.

Non era una rifinitura: è la fase 4 a dipenderne. Una macchina appena portata in
un posto nuovo **non ha ancora una connessione** — se la pagina che serve a
configurare il WiFi prendesse il foglio di stile da Internet, arriverebbe senza
stile e senza icone proprio nel momento in cui serve.

Sono circa 950 KB, non i 400 preventivati: quella stima valeva per i file
compressi, e hls.js da solo è più di un terzo del totale. In cambio è sparita
una dipendenza intera — Font Awesome, 106 KB fra foglio di stile e font per due
sole icone, ora disegnate in linea. Vedi il [changelog](CHANGELOG.md#020) e
`app/assets/vendor/README.md`.

## Fase 3 — Installer e comando di gestione → `0.3.0` ✅

**Fatta.** `install.sh` installa dipendenze, cartelle, applicazione, servizi,
permessi, server web e server RTMP, inizializza il database e stampa alla fine
l'indirizzo a cui collegarsi. Rilanciarlo è il modo di aggiornare: i valori non
ripetuti si rileggono dalla configurazione esistente e i dati non si toccano.
Con opzioni per percorsi, utente, sottopercorso, istanza e porte, e senza
domande quando non c'è un terminale.

Accanto, il comando `fluxus` — uno per macchina, che conosce tutte le istanze —
per stato, aggiornamento, backup, ripristino, log e disinstallazione.

È la prima fase **collaudata sul campo e non solo a vista**: l'installazione di
prova `fluxus-dev` esiste, registra da un push RTMP, estrae le clip dai cue e
convive con quella in produzione sulla stessa macchina senza sfiorarla. Da qui
in avanti ogni fase si prova lì.

La forma `curl -fsSL .../install.sh | sudo bash` resta per quando il repository
sarà pubblico: oggi non ci sarebbe niente da scaricare senza credenziali, quindi
l'installer lavora sul sorgente che ha accanto. Vedi il
[changelog](CHANGELOG.md#030) e la sezione *Installazione* delle
[note tecniche](NOTE-TECNICHE.md).

## Fase 4 — Rete e WiFi dal browser → `0.4.0` — collaudata in parte

Una pagina Rete: stato della connessione, scansione delle reti WiFi, cambio
rete, IP fisso o automatico, nome della macchina. Ogni modifica rischiosa
(cambio WiFi, cambio IP) si applica subito ma con un ripristino automatico a
tempo se nessuno la conferma. E l'**hotspot di primo avvio**: se al boot non
trova nessuna rete conosciuta, la macchina ne apre una propria a cui
collegarsi col telefono. Vedi il [changelog](CHANGELOG.md#040) e la sezione
*Rete* delle [note tecniche](NOTE-TECNICHE.md).

**Collaudati**: stato, scansione, nome macchina, la sola logica di
rilevamento dell'hotspot. **Mai collaudati dal vivo**: l'accensione vera
dell'hotspot, il cambio di rete WiFi e il cambio di IP con il loro
ripristino. Dalla `0.4.14` questi tre sono segnati *sperimentale*
nell'interfaccia e l'hotspot è **dormiente** (installato, non abilitato). Il
loro collaudo è spostato alla 1.5, dove serve davvero.

---

## Verso la 1.0

La 1.0 è Fluxus **completo per chi lo installa da terminale**: registra,
estrae, archivia, si installa da GitHub e si governa con `fluxus`. Niente di
non collaudato acceso di default.

- ~~**Licenza**~~ — AGPL-3.0, fatta il 2026-08-01. Vedi [LICENSE](../LICENSE) e
  la sezione *Licenza* del [README](../README.md).
- ~~Rotazione dei log~~ — fatta il 2026-08-01, decisa log per log. Vedi
  [NOTE-TECNICHE.md](NOTE-TECNICHE.md), sezione *Rotazione dei log*.
- ~~Decidere se il repository diventa pubblico~~ — pubblico dal 2026-08-01:
  <https://github.com/rufusinfabula/fluxus>.
- ~~**Fluxus Connect**~~ — marker/cue da console esterne tramite un broker
  pubblico separato (repository/prodotto a parte). Vedi
  [CHANGELOG.md](CHANGELOG.md#046) e la sezione *Fluxus Connect* delle
  [note tecniche](NOTE-TECNICHE.md).
- ~~**Catalogo verso Fluxus Connect**~~ — oltre allo stato attivo, anche
  registrazioni (storico), marker/cue, sorgenti e orari (`0.4.9`).
- ~~**Hotspot dormiente, rete sperimentale**~~ — `0.4.14`.
- ~~**Helper dei volumi allineato**~~ — `0.4.14`: la copia machine-wide era
  ancora quella dell'installazione unica di prima.
- ~~**Installazione senza password dichiarata**~~ — `0.4.14`: lo dicono
  l'installer, il README e il manuale.
- **Installazione su una macchina pulita** — da GitHub, su un sistema appena
  scritto, senza nulla di già presente. Finora l'installer è stato provato
  solo su una macchina che aveva già le dipendenze.

Sotto la 1.0 vale la regola "versione = fase". Dalla 1.0 in poi la numerazione
segue il prodotto: correzioni sulla terza cifra, novità sulla seconda.

---

## 1.5 — Pronto all'uso

Per chi un terminale non lo usa: si accende, ci si collega dal telefono, si
configura. Erano le fasi 5 e 6 della roadmap originale, spostate dopo la 1.0
perché non aggiungono funzioni a Fluxus ma un modo diverso di consegnarlo.

- **Collaudo dal vivo della rete**: accensione dell'hotspot, cambio di rete
  WiFi e di IP con ripristino. Va fatto con un percorso di rientro
  indipendente (cavo Ethernet), meglio su un secondo Raspberry che sulla
  macchina di produzione: con una sola scheda WiFi l'hotspot toglie la rete a
  tutto il resto. Poi l'hotspot torna abilitato, e il controllo all'avvio deve
  tenere conto anche del cavo.
- **Configurazione guidata al primo accesso** (ex fase 5): nome e fuso orario,
  rete, password, archiviazione, prima sorgente. Riutilizza le parti che già
  esistono (rilevamento dei dischi, verifica di raggiungibilità di una
  sorgente). Le installazioni esistenti devono risultare già configurate.
- **Immagine SD pronta** (ex fase 6): un'immagine da scrivere su microSD con
  tutto già installato. Ogni macchina flashata deve essere **distinta dalle
  altre** — identificativo, nome host e chiavi rigenerati al primo avvio —
  altrimenti due Fluxus sulla stessa rete si pestano i piedi.

---

## Più avanti

- **Federazione multi-nodo**: lo schema del database la prevede già (tabelle
  dei nodi remoti e relativo registro), ma non esiste alcuna interfaccia né
  alcun endpoint. Da riprendere solo se serve davvero. Da non confondere con
  **Fluxus Connect** (sopra, già implementata): quella farebbe sincronizzare
  *configurazione* fra nodi Fluxus paritari (scrittura, bidirezionale), Connect
  fa vedere/seguire *stato e catalogo* (sources/schedules/registrazioni, sola
  lettura) e controllare via marker/cue a console e software di scaletta
  terzi — problemi diversi, non un sostituto l'una dell'altra. Dalla `0.4.9`
  il catalogo sources/schedules in sola lettura passa già da Connect: se un
  giorno servirà la federazione vera (scrittura fra pari), non ripartirà da
  zero ma non è comunque la stessa cosa.
- **EDIT/TRIM per cue, segmenti e file unici** → `2.0`: una sezione EDIT unica
  con tre schede — Segmenti, File unici, Cue — da cui ritagliare una porzione
  di qualunque registrazione audio e salvarla come nuova clip, con editor a
  forma d'onda (punti IN/OUT, zoom, scorciatoie da tastiera). Riprende ed
  estende il ritaglio manuale già scritto ma disattivato — copre oggi solo i
  cue audio via `edit.php`/`edit-trim.php` — allargandolo anche ai segmenti e
  ai file audio unici in un'unica vista. Vedi la sezione *TRIM/EDIT manuale —
  in quarantena* in [NOTE-TECNICHE.md](NOTE-TECNICHE.md).
- **Bitrate garantito** al posto della qualità costante, se un giorno servisse
  un tetto di spazio prevedibile per slot.
