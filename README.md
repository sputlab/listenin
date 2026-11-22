# ListenIn 0.7.1

Database collaborativo per consultare, censire e aggiornare le frequenze radio. Consultazione pubblica senza
registrazione, contributi moderati con storico completo, segnalazioni rapide, esportazione CSV e JSON.

Pensato per un **hosting condiviso senza terminale**: installazione guidata dal browser, importazione dei dati
dal pannello, nessun cron da configurare.

Stack: HTML5, CSS3, JavaScript a moduli (nessuna dipendenza), PHP 8.2+, MySQL 8 / MariaDB 10.6+.
Tutte le tabelle usano il prefisso `listenin_`.

---

## 1. Perché le frequenze del PDF non comparivano

Del PDF *«Tutte le frequenze segrete VHF UHF»* avevo usato soltanto la prefazione (per il disclaimer): le
frequenze non erano mai state caricate. Ora il pacchetto contiene i dati del manuale già convertiti e puliti
(`app/data/manuale_frequenze_v2.csv`, **4.956 record**) e una schermata per importarli con due clic
(vedi punto 3).

Come sono stati letti i 125 PDF:

- **Sezioni**: Vigili del Fuoco, Polizia di Stato, Guardia di Finanza, Carabinieri, Polizia Stradale,
  autostrade, Polizia Locale, antincendio boschivo, ambulanze e croci, emergenza sanitaria, elisoccorso,
  soccorso alpino, rifugi, guide, funivie, ANAS, ferrovie, ENEL, AGIP-SNAM, RAI, ciclismo, civili 43 MHz, LPD,
  satelliti, San Marino, aeroporti civili, VOR, aeronautica militare, marina civile, Meteomar, piloti, marina militare.
  Non importate le tabelle dei canali televisivi.
- **Correzione degli errori di OCR**: il testo del PDF è una scansione e contiene errori. Le frequenze scritte
  come `73,000`, `78 275`, `172825`, `74,337.5` o `162. 037.5` vengono riconosciute. Ogni valore deve cadere nella
  banda del servizio (es. Vigili del Fuoco 73-74,6 MHz) e sulla canalizzazione (multipli di 2,5 o 6,25 kHz). Per i
  canali marittimi, il numero del canale fissa la frequenza e corregge un errore di cifra.
- **23 valori sono stati esclusi** perché non superano i controlli (cifre probabilmente confuse dall'OCR). Si
  scaricano dal pannello (*«scarica l'elenco da rivedere»*) o da `app/data/frequenze_da_rivedere.csv`, con pagina,
  descrizione e motivo, per verificarli a mano.
- **Stato "da verificare"**: il manuale è del 2008 circa e molti servizi nel frattempo sono migrati (digitale,
  TETRA). Tutti i record del manuale partono quindi come *da verificare*; se arriva una conferma da una fonte esterna,
  il record diventa *attivo* con la data di ascolto.

---

## 2. Installazione su hosting condiviso (senza terminale)

1. **Crea il database.** Dal pannello dell'hosting crea un database MySQL/MariaDB vuoto e un utente con tutti i
   permessi su quel database. Annota host (di solito `localhost`), nome, utente e password.
2. **Carica i file via FTP.** Copia la cartella `listenin/` in `/htdocs/webapp/listenin/` (il contenuto della
   cartella, comprese le sottocartelle `app`, `api`, `assets`, `install` e i file nascosti come `.htaccess`).
3. **Autorizza l'installazione.** Con l'FTP, dentro `app/storage/` crea un file **vuoto** chiamato esattamente
   `ALLOW_INSTALL` (senza estensione). Serve a evitare che qualcun altro avvii l'installazione al posto tuo.
4. **Apri** `https://www.tuodominio.it/webapp/listenin/install/` e compila i tre blocchi (database, sito,
   amministratore). L'installer controlla i requisiti, crea le tabelle e i dati di base, scrive la configurazione e
   crea l'amministratore. A fine lavoro cancella da solo `ALLOW_INSTALL`.
5. **Accedi** e importa i dati: *Moderazione → Importazione* (punto 3).
6. **Elimina l'installer** con il pulsante nella pagina finale (oppure cancella la cartella `install/` via FTP).

Note utili:

- **Permessi**: se l'installer segnala cartelle non scrivibili, dal file manager dell'hosting imposta i permessi
  `755` (o `775`) su `app/config` e `app/storage` e sulle loro sottocartelle.
- **HTTPS**: il `.htaccess` forza HTTPS e il cookie di sessione è `Secure`. Se il dominio non ha ancora il
  certificato, attivalo dal pannello dell'hosting (di solito gratuito con Let's Encrypt) prima di installare.
- **Non vedo «Importazione» o altre novità dopo un aggiornamento**: era la cache del browser (in 0.3.1 i moduli
  non avevano la versione nell'indirizzo). Dalla 0.3.2 non succede più; se capita, Ctrl+F5.
- **Errore 500** subito dopo il caricamento: l'hosting non consente la direttiva `Options`; apri
  `listenin/.htaccess` e cancella la riga `Options -Indexes`.
- **Percorso diverso** da `/webapp/listenin/`: aggiorna `RewriteBase` in `listenin/.htaccess`. L'indirizzo
  del sito si indica nell'installer.
- **Cartella privata fuori da htdocs** (facoltativo, più sicuro): sposta `app/` in un percorso non pubblico e
  indicalo in `app-path.php`. Se lo fai prima dell'installazione, ricorda che `ALLOW_INSTALL` va creato nella nuova
  cartella `storage`.
- **Email di conferma**: usano `mail()` di PHP. Se i messaggi non arrivano, chiedi all'hosting un mittente del tuo
  dominio o, per SMTP autenticato, sostituisci il corpo di `Mailer::send()` con PHPMailer.
- **Nessun cron**: la pulizia periodica (token scaduti, registrazioni non verificate, contatori anti-abuso) parte
  da sola, al massimo una volta all'ora, dopo una richiesta qualsiasi. Si può lanciare anche a mano da
  *Importazione → Manutenzione*.

---

## 3. Importare i dati dal pannello

La voce **Importazione** compare nel menu in alto per gli amministratori (e dentro *Moderazione → Importazione*).
Non dipende dall'installer: eliminare la cartella `install/` non la fa sparire. Se non la vedi, accedi con un
account amministratore e svuota la cache del browser (Ctrl+F5, o una finestra anonima).

1. **Scegli il file**: il manuale incluso, oppure carica un CSV, un file Excel (XLSX), un JSON o una tabella HTML
   (l'esportazione di una fonte esterna). Se il file supera il limite di caricamento del server, caricalo via FTP in
   `app/storage/import/inbox/` e ricarica la pagina.
2. **Simula**: fa tutto il lavoro tranne il salvataggio e mostra quanti record verrebbero creati e quali
   duplicati verrebbero uniti. Scarica anche l'elenco delle unificazioni (CSV) con testo originale e armonizzato.
3. **Importa**. L'importazione procede a passi di circa 12 secondi, quindi rispetta i limiti di tempo
   dell'hosting; la pagina mostra l'avanzamento. Se la chiudi, puoi riprendere dall'elenco *Ultime importazioni*.

### Comuni ISTAT (facoltativo, una volta sola)

Migliora il riconoscimento delle località. **ISTAT non pubblica un CSV ma un file Excel (.xlsx)**, all'indirizzo
permanente `https://www.istat.it/storage/codici-unita-amministrative/Elenco-comuni-italiani.xlsx` (pagina
*Codici delle unità amministrative* su istat.it; ultimo aggiornamento noto: 21 febbraio 2026). Nel riquadro
*Comuni ISTAT* il pulsante **«Scarica da ISTAT e importa»** lo scarica dal server e lo importa da solo. Se l'hosting
blocca le connessioni in uscita, scarica il file dal browser e caricalo nello stesso riquadro (CSV o Excel).

Il file viene letto per nome delle colonne («Codice Comune formato alfanumerico», «Denominazione in italiano»,
«Sigla automobilistica»). La tabella delle province di ListenIn è quella precedente alla riforma della Sardegna
in vigore dal 2026 (nuove province di Gallura, Ogliastra, Sulcis Iglesiente, Medio Campidano): i comuni la cui sigla
non è presente vengono saltati e il pannello ne mostra il numero.

Il download è consentito solo da siti elencati in `import.fetch_hosts` nel file di configurazione (predefinito:
`www.istat.it`), solo in HTTPS, con dimensione e durata massime.

### Come ottenere dati da una fonte esterna

L'applicazione **non fa scraping**: i dati si caricano da un file ottenuto con il consenso dei gestori della fonte.
Formati accettati: CSV, XLSX, JSON, oppure una tabella HTML salvata dalle pagine dei risultati (viene letta la prima
tabella con intestazioni; è un lavoro manuale, adatto solo a piccoli insiemi).

Se rilanci la stessa importazione, nulla viene duplicato. Le righe invariate restano com'erano, quelle cambiate
alla fonte vengono aggiornate, i record modificati da utenti o moderatori ricevono solo i campi mancanti, e i
record rimossi da un moderatore non vengono ricreati.

### Armonizzazione

- **Frequenze** in Hz interi da qualsiasi formato; **modo** dal campo o dal testo (DMR, TETRA, C4FM, D-STAR, NXDN…).
- **Descrizioni**: entità HTML, oltre 30 refusi ricorrenti, sigle sciolte in forma estesa (VVF → Vigili del Fuoco,
  VVUU → Polizia Locale, PS, GdF, CRI, CFS, FF.OO.…), maiuscole uniformi, "?" e "forse" trasformati in nota,
  "CESSATO"/"SPENTO" in stato inattivo. L'originale resta registrato.
- **Servizio**: 76 regole calibrate su dati reali, poi il genere della fonte, poi la banda.
- **Geografia**: sigle singole o multiple, nomi di provincia, "Comune (XX)", regioni, aree storiche, nazioni estere;
  con i comuni ISTAT caricati anche i comuni.

### Deduplicazione

Si confrontano solo record con la **stessa frequenza esatta**. Due record sono *certamente* lo stesso se hanno la
stessa area e la stessa descrizione; sono *da verificare* se simili ma non abbastanza; sono *distinti* in tutti gli
altri casi. In particolare non vengono mai uniti:

- stessa frequenza in regioni o province diverse (le frequenze sono riassegnate per territorio);
- località diverse (due pubbliche assistenze della stessa città, il distaccamento e la sua provincia);
- canale regionale o nazionale e relativa istanza provinciale;
- analogico e digitale sulla stessa frequenza (segnalati ai moderatori);
- nominativi o canali diversi.

I casi incerti compaiono tra le segnalazioni come richieste di rimozione per duplicato. Il moderatore sceglie
*«Conferma il duplicato e rimuovi»* (le righe della fonte passano al record conservato) oppure *«Non è un duplicato»*.
Sul manuale: 27 righe unite, 6 coppie da verificare.

---

## 4. Collaborazione, affidabilità e comunità (novità 0.4)

### Aggiornare da una versione precedente

1. **Fai un backup del database** (dal pannello dell'hosting, es. phpMyAdmin → Esporta).
2. Carica via FTP il contenuto di `listenin/`, **senza sovrascrivere** `app/config/config.php`, `app/data/` e `app/storage/`.
3. Apri il sito: alla prima visita il database si aggiorna da solo (nuove tabelle e colonne, nessun dato perso).
   In *Moderazione → Sistema* vedi le migrazioni applicate e puoi verificare o forzare l'aggiornamento.
   Se la prima visita mostra «Aggiornamento del database non riuscito», il dettaglio è in `app/storage/logs/php-error.log`.

### Indice di affidabilità

Calcolato al momento dalla data dell'ultimo ascolto: **Alta** entro 90 giorni, **Media** tra 91 e 365, **Da verificare**
oltre 365, **Mai verificata** se nessun ascolto è registrato. Una frequenza *contestata* non supera mai «Da verificare».
Si può filtrare per affidabilità e compare in tabella, nella scheda, nelle statistiche e nei feed.

### Conferma ascolto, Recuperi e logbook

- **Ascoltata** (un tocco, in ogni riga) oppure **Conferma ascolto** nella scheda, con rapporto RS/RST e antenna facoltativi.
  Serve un account con email verificata; una conferma al giorno per frequenza.
- La conferma aggiorna l'ultimo ascolto con una revisione di origine «checkin» (resta nello storico). Se la frequenza era
  *da verificare*, diventa *attiva*. Se era *inattiva* o *contestata*, parte una proposta di riattivazione per i moderatori.
- È un **Recupero** quando la frequenza era contestata, da verificare, mai verificata o ferma da oltre un anno.
  Contano nel contatore globale e nel punteggio personale; oltre 40 recuperi al giorno per utente (configurabile) i
  check-in restano validi ma non contano più come recuperi, per evitare abusi.
- **Logbook SWL** nel profilo: storico dei tuoi ascolti, esportabile in CSV.
- Punti: 2 per ascolto, 10 in più per un recupero, 5 per una proposta approvata, 3 per una segnalazione accolta.
  Badge: Primo ascolto, Sentinella dello Spettro (50 ascolti), Cacciatore di Ponti (10 ponti diversi),
  Archeologo RF (10 recuperi di frequenze mai verificate o ferme da oltre un anno), Collaboratore (5 proposte approvate).
- **Missioni di recupero**: elenco automatico delle frequenze da recuperare per regione o provincia, le più vecchie in cima.

### Trust Score e approvazione automatica

Cresce con le proposte approvate (+2) e le segnalazioni accolte (+1), cala con quelle respinte (−3). Oltre la soglia
(20, configurabile in `trust.auto_approve_min`) le modifiche **minori** (note, toni, coordinate, locatore, canale, ente,
ultimo ascolto…) sono pubblicate subito. Cambiare frequenza, modo, servizio o provincia richiede sempre la revisione.
Le modifiche automatiche restano nello storico e i moderatori possono annullarle.

### Controllo doppioni e shift automatico (modulo di proposta)

- Mentre si digita la frequenza, il sistema avvisa se esiste un record identico o entro ±5 kHz o ±12,5 kHz nella stessa
  provincia (senza provincia, solo le frequenze identiche in tutta Italia).
- Dal piano di banda propone lo shift: −600 kHz per le uscite 145,600–145,800 MHz; +1,6 MHz o +5 MHz tra 430,000 e 431,999 MHz.
  Il valore è sempre modificabile. **I valori sui 70 cm seguono le indicazioni del progetto e vanno confrontati con il piano
  di banda ARI/IARU in uso**: si cambiano in configurazione (`bandplan.rules`).

### Distanza e azimut

Imposta il tuo QTH (locatore nel profilo, oppure nella barra dei filtri: per la sessione o dalla posizione del
dispositivo, che non viene mai inviata al server): ogni frequenza con coordinate o locatore mostra distanza e azimut
(formula di Haversine, azimut iniziale). Per i record senza posizione il dato non è disponibile.

### Esportazione per gli apparati, feed e open data

- Menu **Esporta**: CSV, JSON, **CHIRP** e **OpenGD77** (qualsiasi vista filtrata). **Attenzione**: i tracciati sono
  ricostruiti dalla documentazione dei programmi e non sono stati provati con CHIRP e con la CPS di OpenGD77 reali:
  importa prima pochi canali. CHIRP: modi analogici e D-STAR; OpenGD77: FM e DMR; gli altri modi sono omessi.
- **Feed RSS/Atom** di nuove frequenze e recuperi, filtrabili per regione, provincia, banda o evento
  (`/feed?format=rss&regione=lombardia&evento=recuperi`).
- **Open data**: copie complete in CSV e JSON, generate a mano (*Open data* o *Moderazione → Sistema*) e ogni 30
  giorni in automatico, ultime 6 conservate. La licenza dei dati è l'**ODbL 1.0**, confermata
  (`opendata.license`); il codice resta sotto AGPL-3.0. Restano da verificare gli accordi con le fonti.

### Interfaccia

Tema **automatico, chiaro, scuro e notte** (rosso su nero, ad alto contrasto, per l'uso notturno) con scelta ricordata;
menu secondario (Frequenze, Missioni, Statistiche, Open data); **Statistiche** con classifiche; scorciatoie da tastiera
(`/` ricerca, frecce per scorrere i risultati, Invio per aprire, `?` per l'elenco, Esc per chiudere); aree di tocco di
almeno 44×44 px; confronto visivo prima/dopo nello storico e nella moderazione, con le parole variate evidenziate.

## 5. Mappe, postazioni e uso offline (novità 0.5)

### Aggiornare dalla 0.4

Come per ogni aggiornamento: backup del database, caricamento dei file senza sovrascrivere `app/config/config.php`,
`app/data/` e `app/storage/`, prima visita (il database si aggiorna da solo). Poi, **una volta sola**, in
*Moderazione → Sistema → Dati geografici* premi **«Importa i baricentri dei comuni»** (circa 2 secondi): carica
le coordinate dei 7.896 comuni italiani, crea quelli mancanti e porta i comuni sardi nelle nuove province. Senza questo
passo la ricerca per prossimità usa solo coordinate, locatori, postazioni e centro provincia.

**Servono i file `.htaccess` aggiornati** (nuova regola di riscrittura per gli URL parlanti) e `mod_rewrite` attivo.

### Cartina d'Italia

Pannello «Cartina d'Italia» sopra i risultati, in SVG puro: clic su una regione per filtrare e zoomare, poi clic su una
provincia; secondo clic per toglierla. Si colora per **numero di frequenze** o per **quota con affidabilità alta**, ed è
navigabile da tastiera. Avvertenze: ogni frequenza conta solo nella sua provincia principale; le frequenze nazionali
senza provincia (circa un quarto con i dati del manuale) non hanno una posizione; le province sarde seguono la
riforma 2026 e «Sud Sardegna» (soppressa) conta nella regione ma non compare sulla cartina.

### Ricerca per prossimità e distanza

Nel menu dei filtri, «Vicino a…»: scegli un comune o il tuo QTH e un raggio di 10, 30, 50 o 100 km. I risultati si
ordinano per distanza. La posizione di ogni record è la migliore disponibile, nell'ordine: coordinate del record,
postazione, locatore Maidenhead, centro del comune, centro della provincia. Le ultime due sono **approssimate**: il
centro provincia è escluso salvo la casella «Includi posizioni approssimate», e le distanze calcolate sul centro del
comune sono segnate «(circa)».

### Postazioni e monti

Il campo **Postazione o monte** (con suggerimenti) e l'**altitudine** compaiono nel modulo di proposta. Tutti gli
impianti con la stessa postazione si vedono insieme in *Postazioni* (elenco per regione, provincia o nome, con i servizi
installati) e nella scheda della postazione, con mappa. I moderatori possono correggere nome, coordinate, quota e locatore:
le posizioni delle frequenze collegate si ricalcolano.

### Mappa nella scheda e ascolto online

Nella scheda di ogni frequenza con posizione: mappa **Leaflet con tessere OpenStreetMap**, marcatore, quota e cerchio
di copertura (quello indicato nel record, oppure la stima dall'orizzonte radio, 4,12·(√quota + √10) km, **indicativa**).
Leaflet (licenza BSD-2) è incluso nel pacchetto, nessun CDN. Le tessere sono servite da OpenStreetMap secondo le sue
[regole d'uso](https://operations.osmfoundation.org/policies/tiles/): per un traffico elevato configura un fornitore
proprio con `map.tile_url`. Pulsante **Sintonizza su…**: il WebSDR dell'Università di Twente (0–29,16 MHz, formato
`?tune=<kHz><modo>`, confermato dalla documentazione WebSDR) per le onde corte; ogni utente può indicare un proprio
ricevitore KiwiSDR o WebSDR, ricordato solo nel suo browser (formato KiwiSDR `?f=<kHz><modo>`, **non verificato** con un
ricevitore reale). Altri ricevitori si aggiungono in `sdr.receivers`.

### URL parlanti

Le ricerche hanno indirizzi condivisibili: `/frequenze/lombardia`, `/frequenze/lombardia/milano/dmr`,
`/frequenze/italia/tutte/ponti-ripetitori` (il terzo elemento è un modo o un servizio). Il pulsante **Copia link**
fornisce l'indirizzo della ricerca; i vecchi link con `?region=…` vengono riscritti. Il resto dei filtri resta in
query (`?affidabilita=alta`). Serve `mod_rewrite`: senza, i link parlanti darebbero errore 404 (le pagine del sito
continuano a funzionare).

### Uso senza copertura dati (PWA)

L'app è installabile («Installa» o «Aggiungi a Home» nel browser) e un **service worker** conserva il guscio dell'app
e le ricerche già viste. **Salva offline** (barra dei risultati) conserva sul dispositivo fino a 2.500 frequenze della
ricerca corrente; **province preferite** (stella nel menu dei filtri) le salva in automatico. Senza rete, la ricerca
mostra la copia salvata con un avviso; *Offline* elenca le ricerche, con ricerca testuale locale e scheda di base.
Mai conservati: accesso, account, moderazione, amministrazione, esportazioni e qualsiasi dato personale; le tessere della
mappa non si salvano. Il service worker richiede HTTPS.

### Dati e licenze

Confini e baricentri: ISTAT, *Confini amministrativi al 1° gennaio 2026*, **CC BY 4.0**, nella redistribuzione
dell'archivio «geojson-italy» (openpolis/guglielmo), semplificati per la cartina (`tools/build_geo.py` rigenera i file).
Mappa: © contributori di OpenStreetMap (ODbL). Leaflet: BSD-2. Queste attribuzioni compaiono anche nell'interfaccia.

## 6. Notizie, webhook e classifiche (novità 0.6)

### Aggiornare dalla 0.5

Come sempre: backup, caricamento dei file senza sovrascrivere `app/config/config.php`, `app/data/` e `app/storage/`,
prima visita (il database si aggiorna da solo: nuove tabelle per notizie e webhook, 6 badge in più).

### Notizie e aggiornamenti

Gli amministratori pubblicano comunicazioni, novità sui piani di banda e resoconti delle importazioni da
*Moderazione → Notizie* (bozza o pubblicata). Pagina pubblica **Notizie** nel menu, ultima notizia in evidenza sopra i
risultati per 14 giorni, feed **RSS e Atom** (`/news/feed?format=rss|atom`). Il testo è semplice (paragrafi e link https):
nessun HTML, quindi nessun rischio di codice iniettato. Dopo un'importazione, il pulsante **«Crea una bozza di notizia con
il resoconto»** prepara il testo con i numeri del risultato, da rivedere e pubblicare.

### Webhook

Ogni utente con account può creare fino a 5 webhook (*Account → Webhook*): quando una frequenza viene **aggiunta** o
**recuperata** in una regione, provincia o banda scelta, ListenIn invia all'indirizzo indicato una richiesta POST con un
JSON firmato:

```
POST https://tuo.dominio/hook
X-ListenIn-Event: frequency.created        (oppure frequency.recovered, ping)
X-ListenIn-Delivery: 1234
X-ListenIn-Signature: t=1763740800,v1=<HMAC-SHA256 di "timestamp.corpo" con il tuo segreto>
{"event":"frequency.created","occurred_at":"…","frequency":{"id":…,"freq_mhz":"…","mode":"FM","label":"…",
 "service":"…","region":"…","province":"…","municipality":"…","status":"…","reliability":"…","last_heard_at":…,"url":"…"}}
```

- Il **segreto** si mostra una sola volta alla creazione (e quando lo si rigenera); nel database è conservato cifrato.
  La pagina del webhook ha un esempio di verifica della firma; controlla anche che il timestamp sia recente.
- Rispondi con un codice **2xx entro 8 secondi**. Altrimenti si riprova (5 tentativi con attese di 1 min, 5 min, 30 min,
  2 ore) e dopo **10 errori consecutivi** il webhook si disattiva (si riattiva a mano). *Invia prova* e *Registro consegne*
  aiutano a configurarlo; gli amministratori vedono tutti i webhook (*Moderazione → Sistema*) e possono disattivarli.
- Le consegne partono **dopo la risposta al visitatore** (con PHP-FPM) e si riprovano senza cron. Senza PHP-FPM l'utente
  che genera l'evento può attendere fino a qualche secondo per ogni consegna in coda: in tal caso il limite resta basso
  (max 25 consegne ogni elaborazione, 8 secondi).
- Le importazioni di massa **non** generano avvisi (opzione `webhooks.include_imports`, sconsigliata).
- Richiede le estensioni PHP **cURL** e **OpenSSL**; altrimenti la scheda lo segnala.

**Sicurezza.** Chi crea un webhook sceglie un indirizzo che il *tuo server* chiamerà, quindi le richieste verso reti interne
(SSRF) sono bloccate: solo `https://nome-di-dominio` sulla porta 443, mai indirizzi IP o credenziali nell'URL; il nome deve
risolvere solo in indirizzi pubblici (niente reti private, loopback, link-local, CGNAT); all'invio si risolve di nuovo e
si fissa l'indirizzo (nessun DNS rebinding); nessun reindirizzamento; la risposta viene scartata (all'utente si mostra solo il
codice HTTP). L'opzione `webhooks.allow_private` esiste **solo per lo sviluppo in locale: non attivarla in produzione.**

### Classifiche e badge

Classifiche **Punti reputazione**, **Punti del mese**, **Recuperi del mese** e **Recuperi totali** (pagina *Statistiche*).
Badge: ai 5 precedenti si aggiungono **Esploratore** (ascolti in 5 regioni), **Maratoneta** (7 giorni consecutivi),
**Veterano** (250 ascolti), **Recuperatore seriale** (50 recuperi), **Redattore** (25 proposte approvate) e
**Campione del mese** (primo in classifica recuperi del mese precedente, con almeno 3 recuperi, assegnato dalla
manutenzione automatica).

## 7. Struttura del pacchetto

```
README.md, CHANGELOG.md          documentazione (non va caricata sul server)
tools/pdf2csv.py                 convertitore PDF → CSV (solo riferimento: i dati sono già convertiti)
tools/stamp_version.py           per chi aggiorna il codice: aggiunge la versione agli import JavaScript e a sw.js (vedi sotto)
tools/build_geo.py               rigenera cartina e baricentri dai confini ISTAT (richiede shapely)
listenin/                      → da caricare in /htdocs/webapp/listenin/
├── index.php, pagina.php        applicazione e testi legali
├── app-path.php                 percorso della cartella privata app/
├── .htaccess                    HTTPS, protezione di app/
├── install/index.php            installer guidato (da eliminare a fine lavoro)
├── api/index.php                API REST/JSON
├── assets/                      css/app.css, js/ (moduli e viste)
└── app/                         PRIVATA, mai servita via web
    ├── config/config.example.php
    ├── data/                    manuale_frequenze_v2.csv, frequenze_da_rivedere.csv
    ├── database/                schema.sql, seed.sql, migrations/ (aggiornamenti automatici del database)
    ├── src/                     Core, Domain, Controllers, Import, Content
    ├── cli/                     script da terminale (facoltativi)
    └── storage/                 log, file caricati, importazioni (scrivibile da PHP)
```

Gli URL del frontend sono relativi e l'API è raggiunta come `api/index.php?route=/…`: funziona in qualsiasi
sottocartella e anche senza mod_rewrite.

### Aggiornare l'applicazione

Per sostituire i file con una versione più recente basta ricaricare `listenin/` via FTP (non sovrascrivere
`app/config/config.php`, `app/storage/` e `app/data/` se contengono tuoi dati). Gli indirizzi dei moduli JavaScript
contengono la versione (`?v=…`) e `.htaccess` fa ricontrollare script e fogli di stile a ogni visita, quindi il
browser non resta bloccato su file vecchi. Chi modifica il codice deve cambiare `app/VERSION` ed eseguire
`python3 tools/stamp_version.py` prima di pubblicare.

### Script da terminale (facoltativi)

Chi ha accesso SSH può usare `app/cli/`: `import_inascolto.php --file=… [--dry-run]`, `migrate.php`, `create_admin.php`,
`maintenance.php`, `import_comuni_istat.php`. Eseguono la stessa logica del pannello.

---

## 8. Sicurezza (OWASP)

| Requisito | Implementazione |
|---|---|
| SQL injection | Solo PDO con prepared statement, emulazione disattivata; filtri validati contro le tassonomie |
| XSS | DOM costruito solo con `textContent`; `htmlspecialchars(ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` lato server; JSON con `JSON_HEX_*`; testi legali con sanificatore ad allowlist |
| Header | CSP senza `unsafe-inline`, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS in HTTPS |
| Password | Argon2id (fallback bcrypt cost 12), rehash automatico, verifica a tempo costante |
| Sessioni | Cookie `HttpOnly`, `Secure`, `SameSite=Strict`, limitato al percorso dell'app; ID rigenerato a login, logout e cambio password; scadenze per inattività e assoluta |
| CSRF | Token per sessione su ogni POST, PUT, PATCH e DELETE con `hash_equals`, più controllo dell'Origin |
| Rate limiting | Login per IP e per account con blocco temporaneo; registrazione, reset, segnalazioni, proposte, lettura API, export |
| Installer | Parte solo con `ALLOW_INSTALL` creato via FTP, si blocca dopo l'installazione, token CSRF, nessun dato precompilato |
| Importazione | Solo amministratori; nomi di file validati, caricamenti in cartella privata, solo file di testo con estensioni ammesse |
| Altro | Nessuna enumerazione degli account, token email monouso salvati come SHA-256, campo trappola anti-bot, protezione da CSV injection, IP salvati solo come HMAC per 30 giorni |

## 9. API

Chiamate come `api/index.php?route=/percorso`. Risposte JSON; le richieste mutative richiedono `X-CSRF-Token`
(da `/meta`). Principali rotte pubbliche: `/meta`, `/frequencies` (filtri `q`, `service`, `mode`, `band`, `plan`,
`region`, `province`, `status`, `affidabilita`, `missione`, `fmin`, `fmax`, `locator`, `callsign`, `digital`, `sort`,
`dir`, `page`), `/frequencies/{id}`, `/frequencies/{id}/history`, `/frequencies/check?freq=&province=` (doppioni),
`/export?format=csv|json|chirp|opengd77`, `/frequencies/{id}/reports`, `/stats`, `/leaderboard?period=`,
`/feed?format=rss|atom&regione=&provincia=&banda=&evento=`, `/opendata`, `/opendata/download?name=`,
`/geo/summary`, `/geo/place?q=`, `/sites`, `/sites/suggest?q=`, `/sites/show?slug=`, `/news`, `/news/show?slug=`, `/news/feed`; filtri `near=lat,lon&km=&approx=1` e `site=`; `sort=distance`.
Con account: proposte, `/frequencies/{id}/checkins`, `/account/*` (profilo, logbook, `/account/logbook/export`, badge).
Moderatori: `/moderation/*`. Amministratori: `/admin/users`, `/admin/import/*`, `/admin/system`,
`/admin/system/migrate`, `/admin/opendata/generate`, `/admin/geo/comuni`, `/admin/news*`, `/admin/webhooks*`; moderatori: `PUT /sites/{id}`; con account: `/account/webhooks*`.

## 10. Disclaimer e testi legali

Il disclaimer è la prefazione del manuale (v. 2.0), in `app/src/Content/disclaimer.html`: linkato nel footer di ogni
pagina, in pagina dedicata (`pagina.php?doc=disclaimer`, anche senza JavaScript) e richiesto con casella obbligatoria
alla registrazione e al primo invio di contributi o segnalazioni.

Da rivedere prima della pubblicazione:

- il disclaimer cita il "ministero delle comunicazioni" (oggi MIMIT) e un campo di 26–900 MHz;
- `termini.html` e `privacy.html` sono bozze, con il titolare da completare;
- i termini vanno integrati con il riferimento agli accordi con le fonti e all'attribuzione dei dati.

## 11. Verifiche eseguite (PHP 8.3, MariaDB 10.11)

- **0.6**: aggiornamento automatico da un'installazione 0.5.0; notizie (bozza, pubblicazione, feed RSS e Atom XML
  validi, permessi, validazioni, testo non interpretato come HTML); classificazione di 15 indirizzi IP pubblici e
  privati; rifiuto di 11 tipi di indirizzi pericolosi nei webhook; consegne reali a un ricevitore locale con firma
  verificata, filtri per regione, evento di recupero, errori con 5 tentativi, disattivazione al decimo errore,
  rotazione del segreto, isolamento tra utenti e controllo degli amministratori; badge aggiuntivi, campione del mese
  e classifica mensile; interfaccia nel browser di tutte queste funzioni.
- **Non verificato**: la consegna dei webhook verso server reali su Internet (dal mio ambiente non c'è rete esterna) e
  la risoluzione DNS pubblica; il comportamento con PHP-FPM (`fastcgi_finish_request`) sull'hosting reale.

- **0.5**: aggiornamento automatico da un'installazione 0.4.0 (4.929 frequenze e check-in intatti) e dal pannello
  importazione dei baricentri (7.896 comuni in 2 secondi con 64 MB di memoria); posizione dei record, prossimità e
  ordinamento per distanza; postazioni (creazione, suggerimenti, modifica da moderatore, ricalcolo delle posizioni);
  cartina (clic su regione e provincia, zoom, metriche, filtri, URL parlanti, apertura diretta e riscrittura dei vecchi
  link); mappa Leaflet nella scheda; link WebSDR e ricevitore personale; service worker con rete spenta (guscio,
  copia salvata, visualizzatore locale, nessun dato privato in cache); regressione delle funzioni 0.4.
- **Non verificato**: il caricamento reale delle tessere OpenStreetMap (dal mio ambiente non sono raggiungibili); il
  formato dei link KiwiSDR; le regole di `.htaccess` per gli URL parlanti su Apache reale (provate con un router
  equivalente); l'installazione della PWA su telefono.

- **0.4**: aggiornamento automatico di un database 0.3.2 con 4.931 frequenze (nessun dato perso); check-in con
  recuperi, punti, badge e logbook (anche CSV); Trust Score con approvazione automatica e rifiuto; controllo doppioni
  (identici, ±5 kHz, ±12,5 kHz); filtri di affidabilità e missione; esportazioni CHIRP e OpenGD77 (righe di ripetitori
  con shift, tono e DCS); feed RSS e Atom (XML valido); statistiche, classifiche, open data con difesa dal path
  traversal; interfaccia nel browser: tema persistente, scorciatoie, check-in da riga e da scheda, distanza e azimut,
  missioni, statistiche, shift automatico e avvisi doppioni nel modulo, profilo con QTH, confronto visivo.
- **Non verificato**: i file CHIRP e OpenGD77 con i programmi reali; i valori di shift sui 70 cm; l'uso di tutte le
  funzioni sull'hosting reale.

- **Sullo zip finale**, estratto in una copia pulita e provato con limiti da hosting economico (`memory_limit` 64 MB,
  30 secondi per richiesta): installazione dal browser, eliminazione dell'installer, importazione del manuale
  (simulazione e importazione vera), ricerca pubblica.
- **Prova di carico**: un file da 19.824 righe si importa in 7 passi (67 secondi in tutto, passo più lungo 12 secondi)
  restando sotto i 64 MB. La deduplicazione tiene in memoria solo i profili di confronto e rilegge i record dal disco.
  Righe uguali a record già presenti si collegano a quelli invece di duplicarsi.
- Installazione guidata da browser su database nuovo, con errori di compilazione e password del database errata;
  blocco alla seconda visita.
- Importazione del manuale dal pannello: simulazione e importazione vera, 4.929 record, nessun errore di validazione.
- Sovrapposizione con un file di una fonte esterna: righe collegate ai record del manuale, stato aggiornato ad "attivo",
  duplicati interni unificati.
- Lettura di un file Excel generato come quello di ISTAT (titolo sopra la tabella, accenti, codici con zeri iniziali),
  download da un server locale autorizzato e rifiuto di indirizzi non consentiti, pagine HTML al posto del file,
  errori 404 e protocolli non HTTPS; importazione dal browser; avviso a database vuoto; voce di menu per l'admin.
- Non verificato: il download reale da istat.it (dal mio ambiente non è raggiungibile) e il contenuto esatto del file
  ISTAT di febbraio 2026; il riconoscimento delle colonne si basa sulle intestazioni descritte da ISTAT.
- Flussi precedenti: registrazione, proposte, moderazione, segnalazioni, export, account, GDPR, rate limit.

MySQL 8 non è stato provato direttamente; lo schema usa sintassi comune ai due motori. Il comportamento reale
dell'hosting (limiti di tempo, di memoria, permessi, invio email) va verificato in fase di installazione.

## 12. Stato del progetto e punti aperti

Con la 0.6 sono presenti tutte le funzioni richieste nella specifica. Restano da **verificare o decidere** da parte tua:

- **Dati di fonti esterne**: il formato reale dell'esportazione non è mai stato visto; le colonne si riconoscono per nome
  ma la prima importazione va controllata. Non è previsto alcuno scraping (vedi sezione 3).
- **Testi legali**: il disclaimer è quello del manuale (2008, con riferimenti da aggiornare); termini e informativa privacy
  sono bozze da far rivedere. La **licenza open data** è l'ODbL 1.0 (confermata); vanno ancora verificati gli accordi con le fonti.
- **Piano di banda 70 cm** (+1,6 MHz e +5 MHz): da confrontare con ARI/IARU; modificabile in `bandplan.rules`.
- **Esportazioni CHIRP/OpenGD77**: tracciati ricostruiti dalla documentazione, non provati con i programmi reali.
- **Ricevitori SDR**: formato KiwiSDR (`?f=`) non verificato; WebSDR di Twente confermato.
- **Hosting reale**: regole `.htaccess` per gli URL parlanti, limiti di tempo e memoria, invio delle email, PWA su
  telefono, consegna dei webhook verso Internet e MySQL 8 non sono stati provati.
- **Tessere OpenStreetMap**: per un traffico elevato usa un fornitore proprio (`map.tile_url`).

## 13. Licenza

ListenIn è software libero rilasciato con la **GNU Affero General Public License v3.0** (AGPL-3.0): il testo completo è
in [LICENSE](LICENSE). Chi offre il programma, anche modificato, come servizio in rete deve mettere a disposizione degli
utenti il relativo codice sorgente. I dati del database e gli open data sono invece rilasciati con la **Open Database License (ODbL) 1.0**. Le librerie e i dati di terze parti mantengono le proprie licenze (vedi *Dati e licenze*).

