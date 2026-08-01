# Roadmap — da installazione unica a prodotto installabile

Fluxus funziona ed è in uso quotidiano, ma finora è esistito come **una sola
installazione**, cresciuta a mano su una macchina. Questo repository serve a
trasformarlo in un pacchetto che chiunque possa installare su un Raspberry Pi
o un mini-PC, con un comando, anche senza monitor collegato.

Le fasi sono pensate per essere lavorate **una per volta**, ognuna verificabile
per conto suo. **Il numero di fase e quello di versione coincidono**: la fase 1
chiude sulla `0.1.0`, la fase 6 sulla `0.6.0`, poi la `1.0.0`.

## Punto di partenza

Cosa manca oggi, in concreto:

- ~~**Nessun installer funzionante**~~ — risolto dalla fase 3.
- ~~**Percorsi cablati nel codice**~~ — risolto dalla fase 1.
- ~~**Dipendenza da Internet per l'interfaccia**~~ — risolto dalla fase 2.
- **Nessun modo di configurare la rete** se non da riga di comando.

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

## Fase 4 — Rete e WiFi dal browser → `0.4.0`

Una pagina Rete: stato della connessione, scansione delle reti WiFi, cambio
rete, IP fisso o automatico, nome della macchina.

E soprattutto l'**hotspot di primo avvio**: se all'accensione non trova nessuna
rete conosciuta, la macchina ne apre una propria a cui collegarsi col telefono
per configurarla. È ciò che rende utilizzabile un Raspberry Pi senza schermo
portato in un ufficio nuovo, dove il WiFi è diverso e non c'è nessuno che sappia
usare un terminale.

## Fase 5 — Configurazione guidata al primo accesso → `0.5.0`

Al primo collegamento, cinque passi: nome e fuso orario, rete, password,
archiviazione, prima sorgente. Riutilizza le parti che già esistono
(rilevamento dei dischi, verifica di raggiungibilità di una sorgente).

## Fase 6 — Immagine SD pronta → `0.6.0`

Un'immagine da scrivere su microSD con tutto già installato: si accende e si
configura dal telefono.

Ogni macchina flashata deve essere **distinta dalle altre** — identificativo,
nome host e chiavi rigenerati al primo avvio — altrimenti due Fluxus sulla
stessa rete si pestano i piedi.

---

## Prima della 1.0

- **Licenza** da scegliere.
- Rotazione dei log (oggi assente).
- Decidere se il repository diventa pubblico.

## Più avanti

- **Federazione multi-nodo**: lo schema del database la prevede già (tabelle
  dei nodi remoti e relativo registro), ma non esiste alcuna interfaccia né
  alcun endpoint. Da riprendere solo se serve davvero.
- **Ritaglio manuale delle clip**: funzionalità completa ma disattivata, si
  riattiva da punti precisi documentati nelle note tecniche.
- **Bitrate garantito** al posto della qualità costante, se un giorno servisse
  un tetto di spazio prevedibile per slot.
