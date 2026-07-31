# Roadmap — da installazione unica a prodotto installabile

Fluxus funziona ed è in uso quotidiano, ma finora è esistito come **una sola
installazione**, cresciuta a mano su una macchina. Questo repository serve a
trasformarlo in un pacchetto che chiunque possa installare su un Raspberry Pi
o un mini-PC, con un comando, anche senza monitor collegato.

Le fasi sono pensate per essere lavorate **una per volta**, ognuna verificabile
per conto suo.

## Punto di partenza

Cosa manca oggi, in concreto:

- **Nessun installer funzionante.** Quello ereditato è fermo a una versione
  molto precedente e non installa nemmeno i file dell'applicazione.
- **Percorsi cablati nel codice**: ogni script ridichiara per conto suo la
  cartella dati, l'applicazione ha l'utente di sistema e gli indirizzi del
  server RTMP scritti in chiaro in più punti.
- **Dipendenza da Internet per l'interfaccia**: fogli di stile, icone e player
  arrivano da CDN esterni.
- **Nessun modo di configurare la rete** se non da riga di comando.

---

## Fase 1 — Un solo punto di verità per percorsi e nomi → `0.2.0`

Un file di configurazione unico, letto sia dall'applicazione sia dagli script,
che stabilisce cartella dati, radice web, sottopercorso, utente di sistema,
indirizzi del server RTMP e **nome dell'istanza**.

Il nome dell'istanza è ciò che permette di installare Fluxus **due volte sulla
stessa macchina** senza collisioni: da esso derivano i nomi dei servizi, del
file di configurazione, delle regole del server web e dei permessi. Serve
subito per collaudare il pacchetto accanto a un'installazione in produzione.

## Fase 2 — L'interfaccia funziona senza Internet → `0.3.0`

Fogli di stile, icone, player e font inclusi nel pacchetto invece che presi da
CDN esterni. Circa 400 KB.

Non è una rifinitura: è la fase 4 a dipenderne. Una macchina appena portata in
un posto nuovo **non ha ancora una connessione** — se la pagina che serve a
configurare il WiFi prende il foglio di stile da Internet, arriva senza stile e
senza icone proprio nel momento in cui serve.

## Fase 3 — Installer e comando di gestione → `0.4.0`

Uno script solo, rieseguibile senza perdere dati, che funziona sia in un
terminale con monitor sia via SSH su una macchina senza schermo:

```
curl -fsSL .../install.sh | sudo bash
```

Installa dipendenze, cartelle, applicazione, servizi, permessi, server web e
server RTMP; inizializza il database; stampa alla fine l'indirizzo a cui
collegarsi. Con opzioni per percorsi, utente, sottopercorso e istanza, e una
modalità completamente automatica per gli script.

Accanto, un comando `fluxus` per stato, aggiornamento, backup, ripristino, log
e disinstallazione.

## Fase 4 — Rete e WiFi dal browser → `0.5.0`

Una pagina Rete: stato della connessione, scansione delle reti WiFi, cambio
rete, IP fisso o automatico, nome della macchina.

E soprattutto l'**hotspot di primo avvio**: se all'accensione non trova nessuna
rete conosciuta, la macchina ne apre una propria a cui collegarsi col telefono
per configurarla. È ciò che rende utilizzabile un Raspberry Pi senza schermo
portato in un ufficio nuovo, dove il WiFi è diverso e non c'è nessuno che sappia
usare un terminale.

## Fase 5 — Configurazione guidata al primo accesso → `0.6.0`

Al primo collegamento, cinque passi: nome e fuso orario, rete, password,
archiviazione, prima sorgente. Riutilizza le parti che già esistono
(rilevamento dei dischi, verifica di raggiungibilità di una sorgente).

## Fase 6 — Immagine SD pronta → `0.7.0`

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
