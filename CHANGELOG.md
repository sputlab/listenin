# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il versionamento semantico.

## [0.7.1] - 2026-11-22

### Corretto
- Installazione: i nomi dei vincoli (chiavi esterne e controlli) hanno ora il prefisso `listenin_`, per evitare l'errore
  1022 «duplicate key» quando il database contiene già vincoli con lo stesso nome.

### Modificato
- Rimossi dalla documentazione e dai testi dell'interfaccia i riferimenti a fonti esterne specifiche.

## [0.7.0] - 2026-11-21

### Modificato
- Su schermi stretti tutte le voci di menu (account, tema e sezioni) sono raccolte in un **menu a burger**, apribile da
  tastiera, richiudibile con Esc e chiuso automaticamente a ogni cambio di pagina.

## [0.6.0] - 2026-11-21

### Aggiunto
- **Notizie e aggiornamenti** gestibili dagli amministratori (comunicazioni, piani di banda, resoconti delle importazioni),
  pagina pubblica, notizia in evidenza, feed RSS/Atom e bozza automatica dal resoconto di un'importazione.
- **Webhook** personali con filtri per regione, provincia e banda, eventi di nuova frequenza e di recupero, firma
  HMAC-SHA256 con timestamp, coda di consegna con 5 tentativi, disattivazione automatica, prova di invio, registro delle
  consegne, rotazione del segreto e controllo degli amministratori. Difesa contro le richieste verso reti interne (SSRF).
- Classifica **Punti del mese**; sei badge in più (Esploratore, Maratoneta, Veterano, Recuperatore seriale, Redattore,
  Campione del mese, quest'ultimo assegnato dalla manutenzione).
- Cifratura dei segreti conservati (AES-256-GCM) e pulizia periodica delle consegne vecchie.

## [0.5.0] - 2026-11-21

### Aggiunto
- **Cartina d'Italia** in SVG con regioni e province, mappa coropletica (numero di frequenze o quota ad affidabilità
  alta), zoom e filtro al clic, navigabile da tastiera. Confini ISTAT 2026 (CC BY 4.0).
- **Ricerca per prossimità** (10, 30, 50, 100 km da un comune o dal proprio QTH) e ordinamento per distanza; posizione
  calcolata di ogni record con indicazione della precisione. Importazione dei **baricentri dei 7.896 comuni**.
- **Postazioni e monti** con altitudine, elenco, scheda con mappa e vista aggregata degli impianti; campi nel modulo di
  proposta con suggerimenti.
- **Mappa Leaflet/OpenStreetMap** nella scheda, quota e cerchio di copertura (indicato o stimato).
- Pulsante **Sintonizza su…** (WebSDR di Twente per le onde corte, ricevitore personale configurabile).
- **URL parlanti** (`/frequenze/lombardia/milano/dmr`) e pulsante *Copia link*.
- **PWA**: manifest, icone, service worker, ricerche **salvate offline**, province preferite, pagina *Offline*.
- Province sarde della riforma 2026 (Sulcis Iglesiente, Ogliastra, Gallura Nord-Est, Medio Campidano).

### Modificato
- Il `.htaccess` ha una nuova regola di riscrittura; gli indirizzi nella pagina sono assoluti rispetto al percorso base.
- La CSP consente le tessere della mappa (immagini), il service worker e il manifest.

### Note di aggiornamento
- Dopo l'aggiornamento: *Moderazione → Sistema → Importa i baricentri dei comuni* (una volta).

## [0.4.0] - 2026-11-21

### Aggiunto
- **Aggiornamento automatico del database** (`database/migrations/`, tabella `listenin_migrations`): i file nuovi
  portano il database alla versione giusta alla prima visita; scheda *Moderazione → Sistema*; `cli/migrate.php`.
- **Indice di affidabilità** (alta, media, da verificare, mai verificata) con filtro, badge e statistiche; stato
  operativo **contestata**.
- **Conferma ascolto** a un tocco (con RS/RST e antenna), **Recuperi**, punti, badge, **Logbook SWL** esportabile in CSV,
  **Missioni di recupero**, classifiche generali, mensili e per recuperi.
- **Trust Score** con approvazione automatica delle modifiche minori; punti e Trust Score alimentati da approvazioni e
  rifiuti.
- **Controllo doppioni in tempo reale** (identici, ±5 kHz, ±12,5 kHz nella stessa provincia) e **shift automatico** dal
  piano di banda nel modulo di proposta.
- **Distanza e azimut** da QTH (profilo, locatore digitato o posizione del dispositivo).
- Esportazione **CHIRP** e **OpenGD77**, **feed RSS/Atom**, **statistiche**, archivio **open data** con copie periodiche.
- Tema **notte** e scelta persistente del tema, scorciatoie da tastiera, aree di tocco da 44 px, confronto visivo
  prima/dopo con evidenziazione delle parole variate, menu secondario.

### Modificato
- Lo stato «Contestata» della fonte esterna viene importato come tale (prima diventava «da verificare»).
- Le revisioni di conferma ascolto (origine «checkin») proteggono il record dalla sovrascrittura delle importazioni.

## [0.3.2] - 2026-11-21

### Aggiunto
- Voce **Importazione** nel menu in alto per gli amministratori, scorciatoia `#/importazione` e avviso a database vuoto.
- Lettura dei **file Excel (.xlsx)** in PHP puro (senza estensione `zip`): ISTAT pubblica l'elenco dei comuni solo in
  quel formato. Accettati anche nei caricamenti del pannello.
- **Download diretto** dell'elenco comuni ISTAT dal pannello, limitato a siti autorizzati (`import.fetch_hosts`),
  HTTPS, reindirizzamenti controllati, dimensione e durata massime. Il pannello mostra quanti comuni sono stati
  saltati per sigla di provincia sconosciuta.
- Nell'installer: chiarito che eliminarlo non toglie l'importazione.

### Corretto
- Dopo un aggiornamento il browser poteva mantenere vecchi moduli JavaScript in cache per 7 giorni (pannello senza la
  scheda Importazione): gli import contengono ora la versione e script e stili vengono ricontrollati a ogni visita.

## [0.3.1] - 2026-11-21

### Corretto
- Importazione di file grandi su hosting con poca memoria: con circa 20.000 righe e 64 MB la deduplicazione si
  esauriva. Ora tiene in memoria solo i profili di confronto, rilegge i record dal disco e interroga il database
  per frequenza (nessuna mappa globale). Verificato su 19.824 righe con 64 MB.
- Il confronto dei duplicati ignora le parole generiche "arma" e "corpo" ("Carabinieri" e "Arma dei Carabinieri"
  vengono ora riconosciuti come lo stesso servizio).

## [0.3.0] - 2026-11-21

### Aggiunto
- **Dati del manuale PDF** (4.956 record, `app/data/manuale_frequenze_v2.csv`) convertiti con correzione degli errori
  di OCR e controllo di banda e canalizzazione; elenco dei 23 valori da rivedere a mano.
- **Installazione guidata dal browser** (`install/`): requisiti, creazione di database e configurazione, primo
  amministratore. Protetta da `ALLOW_INSTALL`, si blocca a installazione conclusa.
- **Importazione dal pannello** (*Moderazione → Importazione*): scelta o caricamento del file, simulazione,
  importazione a passi brevi con avanzamento e ripresa, download dell'elenco duplicati, caricamento dei comuni ISTAT.
- **Manutenzione automatica** (una volta all'ora, senza cron) e pulsante manuale.
- Colonne strutturate negli import (servizio, ente, canale, ingresso, località) e confronto dei duplicati per località.

### Modificato
- Motore di importazione riscritto in `ImportEngine`, condiviso da pannello web e riga di comando.
- Deduplicazione più rigorosa: record regionali e provinciali, località diverse, descrizioni generiche e servizi
  diversi della stessa zona non vengono più uniti né segnalati in blocco.
- Un record "da verificare" confermato da un'altra fonte diventa attivo (se non curato dalla comunità).

## [0.2.0] - 2026-11-20

### Modificato
- Nome del progetto: da Spettro a **ListenIn**; struttura per `/htdocs/webapp/listenin/`, cartella privata `app/`.

### Aggiunto
- Importatore con armonizzazione e deduplicazione, tracciabilità delle righe sorgente (`listenin_frequency_sources`),
  segnalazioni dei possibili duplicati ai moderatori.

## [0.1.0] - 2026-11-20

### Aggiunto
- Prima versione: schema dati, consultazione e filtri, proposte e moderazione, segnalazioni, account self-service,
  disclaimer, importatore iniziale.
