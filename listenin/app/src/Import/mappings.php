<?php
/**
 * ListenIn — dizionari di armonizzazione per l'import da fonti esterne.
 * Tutte le chiavi di confronto sono normalizzate con Normalizer::key()
 * (minuscolo, senza accenti, solo lettere/cifre separate da spazio).
 * File modificabile senza toccare il codice dell'importatore.
 */
declare(strict_types=1);

return [
    // Sinonimi delle intestazioni di colonna → campo sorgente canonico
    'headers' => [
        'id'        => ['id', 'codice', 'id frequenza', 'record id'],
        'freq'      => ['frequenza', 'freq', 'frequency', 'frequenza mhz', 'mhz'],
        'mode'      => ['modo', 'mode', 'modulazione', 'emissione'],
        'service'   => ['servizio', 'service', 'utilizzo', 'utilizzatore', 'descrizione', 'nome'],
        'genere'    => ['genere', 'categoria', 'category', 'tipo'],
        'region'    => ['regione', 'region'],
        'area'      => ['provincia area', 'provincia', 'area', 'zona'],
        'status'    => ['stato', 'status'],
        'heard'     => ['ultimo ascolto', 'data ultimo ascolto', 'last heard', 'ultimo riscontro', 'ascoltata il'],
        'notes'     => ['note', 'notes', 'commento', 'commenti', 'dettagli'],
        'tone'      => ['tono', 'ctcss', 'subtono', 'tono subaudio', 'dcs', 'toni'],
        'locator'   => ['locatore', 'qth', 'locator', 'qth locator'],
        'callsign'  => ['nominativo', 'callsign', 'indicativo'],
        'city'      => ['comune', 'citta', 'localita', 'location'],
        // colonne opzionali con valori già strutturati (es. file preparati da ListenIn)
        'service_slug' => ['servizio listenin', 'slug servizio', 'service slug'],
        'entity'    => ['ente', 'gestore', 'ente gestore'],
        'channel'   => ['canale', 'channel'],
        'input'     => ['ingresso', 'frequenza ingresso', 'input'],
    ],

    /*
     * Pulizia delle descrizioni: refusi ricorrenti e forme abbreviate → forma estesa uniforme.
     * Applicati in ordine, senza distinzione maiuscole/minuscole. La descrizione originale resta
     * consultabile in listenin_frequency_sources.raw_label.
     */
    'label_fixes' => [
        // Refusi
        '/\ba(?:e)?reo\s?port/iu' => 'aeroport',
        '/\ba(?:e)?reonautic/iu' => 'aeronautic',
        '/\bvi[gb]li\b/iu' => 'vigili',
        '/\bcaribinieri\b/iu' => 'carabinieri',
        '/\b(?:vigilianza|viilanza|vigilinza)\b/iu' => 'vigilanza',
        '/\bautostada\b/iu' => 'autostrada',
        '/\bantincedio\b/iu' => 'antincendio',
        '/\bprtotezione\b/iu' => 'protezione',
        '/\bforstale\b/iu' => 'forestale',
        '/\bcopo forestale\b/iu' => 'corpo forestale',
        '/\br[fu]+gio\b/iu' => 'rifugio',
        '/\bsisurezza\b/iu' => 'sicurezza',
        '/\btrasmissoine\b/iu' => 'trasmissione',
        '/\bmanuntenzion/iu' => 'manutenzion',
        '/\bcumunicazioni\b/iu' => 'comunicazioni',
        '/\bferroriari/iu' => 'ferroviari',
        '/\becolocighe\b/iu' => 'ecologiche',
        '/\bliate\b/iu' => 'Linate',
        '/\bgrounf\b/iu' => 'ground',
        '/\bmontchiari\b/iu' => 'Montichiari',
        '/\bgioa del colle\b/iu' => 'Gioia del Colle',
        '/\beledttric/iu' => 'elettric',
        '/\binstambul\b/iu' => 'Istanbul',
        '/\bperscherecci\b/iu' => 'pescherecci',
        '/\bvolantari\b/iu' => 'volontari',
        '/\btoscan soccorso\b/iu' => 'Toscana Soccorso',
        '/\badetti\b/iu' => 'addetti',
        '/\baviosuperfice\b/iu' => 'aviosuperficie',
        '/\bparapendii\b/iu' => 'parapendio',
        '/\bbolettin/iu' => 'bollettin',
        '/\bcarrattezzi\b/iu' => 'carroattrezzi',
        '/(?<![\p{L}])a\.\s?p\.\s?r\.\s?s\.?/iu' => 'APRS',
        '/\bistana\b/iu' => 'Istrana',
        // Forme abbreviate → forma estesa
        '/(?<![\p{L}])(?:v\.?\s?v\.?\s?f\.?\s?f?\.?|v\.?d\.?f\.?)(?![\p{L}])/iu' => 'Vigili del Fuoco',
        '/(?<![\p{L}])(?:v\.?\s?v\.?\s?u\.?\s?u\.?|vvu|vu)(?![\p{L}])/iu' => 'Polizia Locale',
        '/\bvigili urbani\b|\bpolizia municipale\b|\bpolizia urbana\b|\bpolizia comunale\b|\bpol\.?\s?loc\b\.?/iu' => 'Polizia Locale',
        '/^(?:pl|p\.?\s?m\.?)\s+(?=\p{L})/iu' => 'Polizia Locale ',
        '/^p\.?\s?s\.?(?=\s|$)/iu' => 'Polizia di Stato',
        '/(?<![\p{L}])g\.?\s?d\.?\s?f\.?(?![\p{L}])/iu' => 'Guardia di Finanza',
        '/^c\.?\s?r\.?\s?i\.?(?![\p{L}])/iu' => 'Croce Rossa Italiana',
        '/^p\.\s?a\.\s*/iu' => 'Pubblica Assistenza ',
        '/(?<![\p{L}])c\.\s?f\.\s?s\.?(?![\p{L}])|^cfs\b/iu' => 'Corpo Forestale dello Stato',
        '/(?<![\p{L}])c\.\s?n\.\s?s\.\s?a\.\s?s\.?/iu' => 'CNSAS',
        '/(?<![\p{L}])a\.\s?i\.\s?b\.?(?![\p{L}])/iu' => 'AIB',
        '/(?<![\p{L}])c\.\s?i\.\s?s\.\s?a\.\s?r\.?/iu' => 'CISAR',
        '/(?<![\p{L}])te\.?\s?t\.?\s?ra\.?(?![\p{L}])/iu' => 'TETRA',
        '/(?<![\p{L}])ff\.?\s?oo\.?(?![\p{L}])/iu' => "Forze dell'Ordine",
        '/\bprot\.?\s?civ(?:ile)?\.?(?![\p{L}])|\bprociv\b|\bprotciv\b|\bprot\.\s?civ\b/iu' => 'Protezione Civile',
        '/\bpolstrada\b|\bpol\.\s?stradale\b/iu' => 'Polizia Stradale',
        '/\bsoc{1,2}\.\s?alpino\b/iu' => 'Soccorso Alpino',
        '/\bsocc\.\s?/iu' => 'Soccorso ',
        '/\bass\.\s?/iu' => 'Associazione ',
        '/\bassociaz\.\s?/iu' => 'Associazione ',
        '/\bcom\.\s?mont\.\s?/iu' => 'Comunità Montana ',
        '/\bcomunita\'?\s?montana\b|\bcomunit&agrave;\s?montana\b/iu' => 'Comunità Montana',
    ],

    // Record di servizio della fonte da non importare (prove, voci marcate per l'eliminazione)
    'skip_labels' => '/^(test( |$)|da eliminare$)/',

    // Regioni: varianti osservate nella sorgente → slug
    'regions' => [
        'piemonte' => 'piemonte', 'pie' => 'piemonte',
        'valle d aosta' => 'valle-d-aosta', 'valledaosta' => 'valle-d-aosta', 'vda' => 'valle-d-aosta',
        'lombardia' => 'lombardia', 'lom' => 'lombardia',
        'trentino alto adige' => 'trentino-alto-adige', 'trentinoaa' => 'trentino-alto-adige', 'trentino' => 'trentino-alto-adige',
        'veneto' => 'veneto',
        'friuli venezia giulia' => 'friuli-venezia-giulia', 'friulivg' => 'friuli-venezia-giulia', 'fvg' => 'friuli-venezia-giulia',
        'liguria' => 'liguria', 'ligura' => 'liguria',
        'emilia romagna' => 'emilia-romagna', 'emro' => 'emilia-romagna',
        'toscana' => 'toscana', 'umbria' => 'umbria', 'marche' => 'marche', 'lazio' => 'lazio',
        'abruzzo' => 'abruzzo', 'molise' => 'molise', 'campania' => 'campania', 'puglia' => 'puglia',
        'basilicata' => 'basilicata', 'calabria' => 'calabria', 'sicilia' => 'sicilia', 'sardegna' => 'sardegna',
    ],
    // Nomi e abbreviazioni di regione che compaiono nel campo area
    'area_regions' => [
        'friuli venezia giulia' => 'friuli-venezia-giulia', 'friuli veneziagiulia' => 'friuli-venezia-giulia', 'friuli' => 'friuli-venezia-giulia',
        'emilia romagna' => 'emilia-romagna', 'romagna' => 'emilia-romagna', 'emilia' => 'emilia-romagna', 'em rom' => 'emilia-romagna',
        'valle d aosta' => 'valle-d-aosta', 'lom' => 'lombardia', 'lomb' => 'lombardia', 'pie' => 'piemonte', 'piem' => 'piemonte',
        'trentino' => 'trentino-alto-adige', 'alto adige' => 'trentino-alto-adige', 'sudtirol' => 'trentino-alto-adige',
        'canavese' => 'piemonte', 'val susa' => 'piemonte', 'valle di susa' => 'piemonte', 'val di susa' => 'piemonte',
        'brianza' => 'lombardia', 'valtellina' => 'lombardia', 'oltrepo pavese' => 'lombardia', 'val brembana' => 'lombardia',
        'versilia' => 'toscana', 'casentino' => 'toscana', 'valdarno' => 'toscana', 'cilento' => 'campania', 'irpinia' => 'campania',
        'salento' => 'puglia', 'cadore' => 'veneto', 'lidi ferraresi' => 'emilia-romagna', 'ossola' => 'piemonte',
        'castelli romani' => 'lazio', 'ciociaria' => 'lazio', 'maremma' => 'toscana', 'garfagnana' => 'toscana', 'langhe' => 'piemonte',
    ],
    // Nazioni estere riconosciute nel campo area
    'area_countries' => [
        '/\b(svizzera|canton ticino|ticino|lugano|ginevra)\b/' => 'CH', '/\b(francia|nizza|marsiglia|monaco)\b/' => 'FR',
        '/\b(malta)\b/' => 'MT', '/\b(slovenia)\b/' => 'SI', '/\b(croazia|fiume)\b/' => 'HR', '/\b(austria)\b/' => 'AT',
        '/\b(san marino)\b/' => 'SM', '/\b(vaticano|citta del vaticano)\b/' => 'VA',
    ],

    // Valori che indicano copertura nazionale o assenza di regione
    'region_national' => ['nazionale', 'italia', 'nazionale', 'in tutta italia', 'tutte', 'ogni regione'],
    'region_none' => ['non specificata', 'non specificato', 'n d', ''],

    // Sigle provinciali storiche → sigla attuale
    'province_legacy' => ['OT' => 'SS', 'OG' => 'NU', 'CI' => 'SU', 'VS' => 'SU', 'FO' => 'FC', 'PS' => 'PU'],
    // Nomi di provincia alternativi → sigla
    'province_names' => [
        'monza' => 'MB', 'brianza' => 'MB', 'verbania' => 'VB', 'ossola' => 'VB', 'forli' => 'FC', 'cesena' => 'FC',
        'pesaro' => 'PU', 'urbino' => 'PU', 'massa' => 'MS', 'carrara' => 'MS', 'l aquila' => 'AQ',
        'bolzano' => 'BZ', 'reggio calabria' => 'RC', 'reggio emilia' => 'RE', 'la spezia' => 'SP', 'spezia' => 'SP',
    ],

    // Stato operativo
    'statuses' => [
        'confermata' => 'attivo', 'attiva' => 'attivo', 'attivo' => 'attivo',
        'da verificare' => 'da_verificare', 'contestata' => 'contestata',
        'non piu attiva' => 'inattivo', 'inattiva' => 'inattivo', 'dismessa' => 'inattivo',
        'sperimentale' => 'sperimentale',
    ],
    // Diciture nella descrizione che indicano un servizio cessato
    'inactive_markers' => '/\b(cessat[oa]|spent[oa]|dismess[oa]|non va piu|non piu attiv[oa]|migrat[oi])\b/',

    // Modi della sorgente → codice normalizzato
    'modes' => [
        'fm' => 'FM', 'nfm' => 'NFM', 'fmn' => 'NFM', 'fm n' => 'NFM', 'wfm' => 'WFM', 'am' => 'AM',
        'usb' => 'USB', 'lsb' => 'LSB', 'cw' => 'CW', 'dmr' => 'DMR', 'tetra' => 'TETRA', 'c4fm' => 'C4FM',
        'dstar' => 'DSTAR', 'd star' => 'DSTAR', 'nxdn' => 'NXDN', 'p25' => 'P25', 'dpmr' => 'DPMR',
        'acars' => 'ACARS', 'nd' => 'ND', 'pocsag' => 'POCSAG', 'packet' => 'PACKET', 'dab' => 'DAB',
    ],
    // Modo dedotto dal testo (ha la precedenza sul modo analogico generico della sorgente)
    'mode_from_label' => [
        '/\b(dmr|mototrbo|moto trbo)\b/' => 'DMR',
        '/\b(tetra|te t ra)\b/' => 'TETRA',
        '/\b(c4fm|ysf|system fusion)\b/' => 'C4FM',
        '/\b(dstar|d star)\b/' => 'DSTAR',
        '/\bnxdn\b/' => 'NXDN',
        '/\b(p25|apco)\b/' => 'P25',
        '/\bdpmr\b/' => 'DPMR',
        '/\b(pocsag|teledrin|cercapersone|pager)\b/' => 'POCSAG',
        '/\bacars\b/' => 'ACARS',
        '/\b(aprs|packet|ax25)\b/' => 'PACKET',
        '/\bdab\b/' => 'DAB',
    ],

    // Genere della sorgente → servizio di ripiego
    'genere' => [
        'prot civ' => 'emergenza-protezione-civile',
        'ham' => 'radioamatori-simplex',
        'socc sanitario' => 'emergenza-118',
        'socc pubblico' => 'emergenza',
        'taxi' => 'utility-taxi',
        'tv' => 'broadcast-tv',
        'aero' => 'aeronautico',
        'milair' => 'aero-militare',
        'vigilanza' => 'utility-vigilanza',
        'polizia locale' => 'utility-polizia-locale',
        'nautico' => 'marittimo',
        'autostrade' => 'utility-autostrade',
        'sicurezza aziend' => 'utility-aziende',
        'enti governativi' => 'utility-pa',
        'musei' => 'utility-pa',
        'centri commerciali' => 'utility-aziende',
        'associazioni' => 'utility-altro',
        'telecom' => 'utility-telemetria',
        'p a' => 'emergenza-volontariato',   // Pubblica Assistenza
        'forze armate' => 'utility-militare',
        'altro' => 'utility-altro',
    ],

    // Intervalli (Hz) usati come condizione dalle regole
    'ranges' => [
        'aero'    => [[108000000, 137000000], [225000000, 400000000]],
        'marine'  => [[156000000, 162050000]],
        'amateur' => [[1810000, 2000000], [3500000, 3800000], [7000000, 7200000], [14000000, 14350000],
                      [28000000, 29700000], [50000000, 52000000], [144000000, 146000000], [430000000, 440000000],
                      [1240000000, 1300000000]],
        'cb'      => [[26965000, 27405000]],
        'fm_bc'   => [[87500000, 108000000]],
    ],

    /*
     * Regole di classificazione, valutate in ordine sulla descrizione normalizzata.
     * 'in' limita la regola a uno degli intervalli sopra; 'repeater' sceglie il sottoservizio ponti-* dal modo.
     */
    'rules' => [
        ['re' => '/\b(pirat\w*|abusiv\w*)\b/', 'service' => 'utility-altro', 'note' => 'Uso non autorizzato segnalato dalla fonte'],
        ['re' => '/^(da definire|da identificare|da verificare|da eliminare|sconosciut\w*|non identificat\w*|non definit\w*|non si sa|ignoto|unknown|unid|servizio tbd|tbd|disturbi|giochini|amici|frequenza chiacchere|e|s|f)\b/', 'service' => 'utility-altro', 'note' => 'Servizio non identificato dalla fonte'],
        ['re' => '/^(dmr|nxdn|tetra|mototrbo|digi|digitale)( (forte sconosciuto|sconosciuto|fm dualmode|nex4|in francese))?$/', 'service' => 'utility-altro', 'note' => 'Trasmissione digitale non identificata'],
        ['re' => '/\b(cordless|baby ?monitor|microspia|microfono|radiomicrofono|interfono|cuffie|wireless|mouse|tastiera|garmin|telecomand\w*|apricancello|rete ism|contatori gas|wi fi|wlan)\b/', 'service' => 'ism'],
        ['re' => '/\b(noaa|meteosat|radiosond\w*|pallon\w* sonda|stazione meteo|fax meteo|anemometric\w*)\b/', 'service' => 'utility-telemetria'],
        ['re' => '/\b(acars|ads b)\b/', 'service' => 'aero-servizi'],
        ['re' => '/\b(segnale tempo|number station|rwm|onde corte|onde medie)\b/', 'service' => 'broadcast-onde'],
        ['re' => '/\b(satellit\w* militar\w*|milsat|fltsatcom|sicral|satcom)\b/', 'service' => 'utility-militare'],
        ['re' => '/\b(vigili del fuoco|vigile del fuoco|vv ?ff|vvf|v v f|vdf|vf|vigli del fuoco)\b/', 'service' => 'emergenza-vvf'],
        ['re' => '/\b(elisoccorso|eliambulanz\w*|pegaso|rega)\b/', 'service' => 'emergenza-elisoccorso'],
        ['re' => '/\b(118|areu|ares|soreu|suem|emergenza sanitaria|emergenza medica)\b/', 'service' => 'emergenza-118'],
        ['re' => '/\b(aib|antincendi\w*|anti incendi\w*|antiincendi\w*|forestal\w*|canadair|cfs)\b/', 'service' => 'emergenza-aib'],
        ['re' => '/\b(soccorso alpino|cnsas|socc alpino)\b/', 'service' => 'emergenza-soccorso-alpino'],
        ['re' => '/\b(croce \w+|cri|anpas|anaps|misericordi\w*|pubblica assistenza|ambulanz\w*|p a|sos|volontariato)\b/', 'service' => 'emergenza-volontariato'],
        ['re' => '/\b(protezione civile|prot civ\w*|prociv|protciv|fir cb|fir s e r|ser|servizio emergenza radio|volontari)\b/', 'service' => 'emergenza-protezione-civile'],
        ['re' => '/\b(polizia locale|polizia municipale|polizia urbana|polizia provinciale|vigili urbani|vibili urbani|vvuu|vv uu|vvu|vu|pl|municipale|ausiliari del traffico|polizia comunale|urbana|vigili|vigile)\b/', 'service' => 'utility-polizia-locale'],
        ['re' => '/\b(carabinieri|caribinieri|polizia di stato|polizia stradale|polstrada|stradale|questura|guardia di finanza|gdf|g d f|polfer|polaria|polizia penitenziaria|dapnet|ps|ff oo|forze dell ordine|interforze|polizia)\b/', 'service' => 'utility-forze-ordine'],
        ['re' => '/\b(vigilanza|vigilianza|metronotte|sicuritalia|mondialpol|ivri|civis|axitea|fidelitas|televigilanza|telesorveglianza|guardie giurate|portavalori|vigilantes|allsystem|telecontrol)\b/', 'service' => 'utility-vigilanza'],
        ['re' => '/\b(atis|volmet|fis|afis|flight information)\b/', 'service' => 'aero-informazioni', 'in' => 'aero'],
        ['re' => '/\b(vor|ils|ndb|outer marker|marker)\b/', 'service' => 'aero-radioassistenze', 'in' => 'aero'],
        ['re' => '/\b(aeronautica militare|aereonautica militare|milair|mil|militar\w*|frecce tricolori|frecce tricolore|pan|sccam|usaf|raf)\b/', 'service' => 'aero-militare', 'in' => 'aero'],
        ['re' => '/\b(twr|torre|tower|ground|gnd|delivery|clearance)\b/', 'service' => 'aero-torre', 'in' => 'aero'],
        ['re' => '/\b(app|approach|acc|radar|rdr|avvicinamento|director|ctr|ctr\w*|controllo)\b/', 'service' => 'aero-avvicinamento', 'in' => 'aero'],
        ['re' => '/\b(aviosuperfic\w*|volo libero|deltaplan\w*|parapend\w*|alian\w*|volo vela|ulm|vds|campo volo|air to air)\b/', 'service' => 'aero-aviazione-generale', 'in' => 'aero'],
        ['re' => '/\b(handling|apron|rampa|servizi aeroportuali|aeroport\w*|aereoport\w*|flightcare)\b/', 'service' => 'aero-servizi'],
        ['re' => '/\b(capitaneria|capitan porto|guardia costiera|compamare|locamare|ipd)\b/', 'service' => 'mare-guardia-costiera'],
        ['re' => '/\b(meteomar|meteo mare|meteo navale|avvisi ai naviganti|bollettin\w* (mare|nautico)|navtex|radio meteomar)\b/', 'service' => 'mare-meteo'],
        ['re' => '/\b(canale nautico|nautico|nautica|marittimo mobile|servizio mobile marittimo|vts)\b/', 'service' => 'mare-canali'],
        ['re' => '/\b(porto|portual\w*|ormeggiator\w*|piloti portuali|terminal portuale)\b/', 'service' => 'mare-porti'],
        ['re' => '/\b(traghett\w*|navigazione|navigarda|nave)\b/', 'service' => 'mare-navigazione'],
        ['re' => '/\b(pmr ?446|pmr)\b/', 'service' => 'pmr446'],
        ['re' => '/\blpd\b/', 'service' => 'lpd'],
        ['re' => '/\b(cb|banda cittadina)\b/', 'service' => 'cb', 'in' => 'cb'],
        ['re' => '/\b(ripetitore|ponte|rpt|r ?\d{1,2} ?(a|alfa)?|ru ?\d{1,2} ?(a|alfa)?|ir\d[a-z]+|iq\d[a-z]+)\b/', 'repeater' => true, 'in' => 'amateur'],
        ['re' => '/\b(echolink|nodo|e l)\b/', 'service' => 'radioamatori-nodi', 'in' => 'amateur'],
        ['re' => '/\b(beacon|bcn)\b/', 'service' => 'radioamatori-beacon'],
        ['re' => '/\b(iss|satellit\w*|amsat|ao \d+|stazione spaziale)\b/', 'service' => 'radioamatori-satelliti'],
        ['re' => '/\b(aprs|packet|ax25)\b/', 'service' => 'radioamatori-dati'],
        ['re' => '/\b(link|trasponder|transponder)\b/', 'service' => 'ponti-link', 'in' => 'amateur'],
        ['re' => '/\b(radioamator\w*|radio amator\w*|ham|ari|cisar|amatorial\w*)\b/', 'service' => 'radioamatori-simplex'],
        ['re' => '/\b(taxi|radiotaxi|radio taxi|ncc|noleggio con conducente)\b/', 'service' => 'utility-taxi'],
        ['re' => '/\b(autostrad\w*|autostada|anas|a\d{1,2}|viabilita|soccorso stradale|carro attrezzi|satap|autovie|brebemi|serravalle|coa)\b/', 'service' => 'utility-autostrade'],
        ['re' => '/\b(trenitalia|ferrovi\w*|fs|ff ss|rfi|manovratori|metropolitana|metro|atm|gtt|atac|amt|bus|autobus|autolinee|trasport\w*|tram|tramvia|mercitalia)\b/', 'service' => 'utility-trasporti'],
        ['re' => '/\b(rai|mediaset|regia|feed|la7|sky|dazn|trasferimento|studi tv|riprese|radiomicrofono)\b/', 'service' => 'broadcast-regia'],
        ['re' => '/\b(dab)\b/', 'service' => 'broadcast-dab'],
        ['re' => '/\bradio\b/', 'service' => 'broadcast-fm', 'in' => 'fm_bc'],
        ['re' => '/\b(commissariato|reparto mobile|pedinament\w*|scorta|mekpol|polix|rete dap|finanza|^cc)\b/', 'service' => 'utility-forze-ordine'],
        ['re' => '/\b(vigili giurati|i v r i|ivis|ivng|sorveglianza|steward|servizio d ordine|sicurezza|security)\b/', 'service' => 'utility-vigilanza'],
        ['re' => '/\b(guardi\w* (caccia|ecologic\w*|faunistic\w*|venatori\w*|parco|nazionale ambientale)|guardiaparco|gev|zoofila|ittico)\b/', 'service' => 'utility-pa'],
        ['re' => '/\b(sast|guide alpine)\b/', 'service' => 'emergenza-soccorso-alpino'],
        ['re' => '/\b(ana|anc|a n c|alpini|associazione nazionale alpini|associazione naz\w* carabinieri|odv|vab|v a b|vapc|consulta volontari|sommozzatori|pc nostrum|p c)\b/', 'service' => 'emergenza-protezione-civile'],
        ['re' => '/\b(sanitari\w*|sanita|ospedal\w*|asl|guardia medica|paramedic\w*|servizio sanitario|posto medico)\b/', 'service' => 'emergenza-118'],
        ['re' => '/\b(arrival|departure|information|informazioni|weather|meteo|volmet|info)\b/', 'service' => 'aero-informazioni', 'in' => 'aero'],
        ['re' => '/\b(fic|cta|gca|holding|arr|tne|revers|fir)\b/', 'service' => 'aero-avvicinamento', 'in' => 'aero'],
        ['re' => '/\b(alitalia|scalo|servizi a terra|servizi di pista|pulizia aeromobili|ops|coordinamento volo|linea tecnica|movimenti al suolo)\b/', 'service' => 'aero-servizi', 'in' => 'aero'],
        ['re' => '/\b(pattuglia acrobatica|frecce tricolor\w*|air refuelling)\b/', 'service' => 'aero-militare'],
        ['re' => '/\b(aereo|aero|avio|aeronautic\w*|airband|air|atc|civilair|comunicazioni aeree|traffico aereo|aviazione|sar|emergenza aerea|soccorso aereo|air emergency)\b/', 'service' => 'aeronautico', 'in' => 'aero'],
        ['re' => '/\b(elicotter\w*|helicopter|heliski|eliporto|helilavoro|paracadut\w*|aviolanc\w*)\b/', 'service' => 'aeronautico'],
        ['re' => '/\b(navale|naviganti|locavurnav|pescherecci|piattaform\w*|ormeggio|idroscalo|port info|chiamata marittima|coastal|navy coastal|olympia radio|monaco radio|istanbul radio|haifa radio)\b/', 'service' => 'marittimo'],
        ['re' => '/\b(radio (capital|deejay|cuore|maria|vaticana|italia|gr|101|24)|radio24|rtl|m2o|ray way|rai way|audio|canale televisivo|tv)\b/', 'service' => 'broadcast-regia'],
        ['re' => '/\b(nettezza urbana|ama|amiu|a a m p s|servizi ecologici|rifiuti|depurator\w*|acquedott\w*|aipo|amga|motorizzazione civile)\b/', 'service' => 'utility-pa'],
        ['re' => '/\b(radioassistenz\w*|marcatore)\b/', 'service' => 'aero-radioassistenze', 'in' => 'aero'],
        ['re' => '/\b(control\w*|contr\w*le|comunicazioni)\b/', 'service' => 'aero-avvicinamento', 'in' => 'aero'],
        ['re' => '/\b(radio|peretola|linate|malpensa|fiumicino|ciampino|caselle|ghedi|lfmn|liat|montichiari|tassignano|istrana|aviano)\b/', 'service' => 'aeronautico', 'in' => 'aero'],
        ['re' => '/\b(radio|marina|navali)\b/', 'service' => 'marittimo', 'in' => 'marine'],
        ['re' => '/\b(i[a-z]?\d[a-z]{1,4}|yaesu|wires x|c4fm|ysf)\b/', 'service' => 'radioamatori-simplex', 'in' => 'amateur'],
        ['re' => '/\b(nasa|esa|angelsat|sat)\b/', 'service' => 'utility-telemetria'],
        ['re' => '/\b(soccors\w*)\b/', 'service' => 'emergenza'],
        ['re' => '/\b(guardie)\b/', 'service' => 'utility-vigilanza'],
        ['re' => '/\b(esercito|marina militare|forze armate|militar\w*|nato|navy|difesa|brigata|caserma|poligono di tiro|alpini di guardia|americani|desvo|stanag|std 188 141|mil)\b/', 'service' => 'utility-militare'],
        ['re' => '/\b(telecontroll\w*|telemetri\w*|pocsag|teledrin|paging|pager|cercapersone|allarm\w*|radioallarme|teleallarm\w*|dati|data|data burst|criptat\w*|cripto|radiotelefon\w*|telefon\w*|centralino|portante fissa|link|ponte radio)\b/', 'service' => 'utility-telemetria'],
        ['re' => '/\b(giro d italia|giro italia|rally|gara|maratona|stadio|autodromo|manifestazion\w*|ciclistic\w*|ciclocorsa|radiocorsa|radio corsa|regata|eventi|evento|granfondo|corsa|racing|formula e|assistenza sportiva|concerto|party|ippodromo|juve)\b/', 'service' => 'utility-eventi'],
        ['re' => '/\b(comune|comuni|municipio|provincia|provinciale|amministrazione|regione|rete regionale|roma capitale|citta di|prefettura|quirinale|vaticano|ministero|museo|musei|citta metropolitana|comunita montana|parco|cimitero|foro romano|castello|cattedrale|religios\w*|politecnico|istituto)\b/', 'service' => 'utility-pa'],
        ['re' => '/\b(enel|snam|eni|agip|acea|a2a|hera|iren|italgas|raffineri\w*|centrale|industria\w*|aziend\w*|cantiere|cava|cave|impresa|ditta|centro commerciale|ikea|auchan|carrefour|rifugio|impianti sci\w*|sciistic\w*|funivia|piste|hotel|albergo|spa|energia|diga|tenaris|italcementi|fassa|freudenberg|finder|lubrani|sogit|terminali|italink|ski|funivie|pisteur|sestriere|via lattea|courmayeur|zoo|safari|tenuta|alpinistica|acqua|gas|liquigas|idrocarburi|saipem|elettric\w*|tralicci|edison|caldaie|idraulici|elettricisti|ascensori|manutenzion\w*|lavori stradali|scavi|calcestruzzi|fabbrica|stabilimento|acciaier\w*|ristorante|locanda|agriturismo|deposito|logistic\w*|interporto|scalo merci|corrieri|fattorini|pony|spedizioni|autotraspor\w*|autospurghi|trasloch\w*|privat\w*|club|fiera|teatro|cinema|sci|sci club|scuola sci|cabinovia|seggiovia|teleferica|capanna|guide|tecnici|assistenza tecnica|servizi|uffici)\b/', 'service' => 'utility-aziende'],
        ['re' => '/\b(soccorso|emergenza|sala operativa|allertamento|assistenza alla popolazione|kanal e)\b/', 'service' => 'emergenza'],
        ['re' => '/\b(trasporto|navette|raccordo|tangenziale|ativa|ataf|strade)\b/', 'service' => 'utility-trasporti'],
    ],

    // Ripiego per intervallo di frequenza quando nessuna regola né genere è applicabile
    'range_fallback' => [
        [118000000, 137000000, 'aeronautico'],
        [108000000, 118000000, 'aero-radioassistenze'],
        [156000000, 162050000, 'marittimo'],
        [446000000, 446200000, 'pmr446'],
        [433075000, 434775000, 'lpd'],
        [26965000, 27405000, 'cb'],
        [87500000, 108000000, 'broadcast-fm'],
        [144000000, 146000000, 'radioamatori'],
        [430000000, 440000000, 'radioamatori'],
    ],

    // Enti e gestori riconosciuti nella descrizione → nome canonico
    'entities' => [
        '/\b(vigili del fuoco|vv ?ff|vvf)\b/' => 'Corpo Nazionale dei Vigili del Fuoco',
        '/\bcarabinieri\b/' => 'Arma dei Carabinieri',
        '/\b(polizia di stato|questura|polizia stradale|polstrada)\b/' => 'Polizia di Stato',
        '/\b(guardia di finanza|gdf)\b/' => 'Guardia di Finanza',
        '/\b(croce rossa|cri)\b/' => 'Croce Rossa Italiana',
        '/\banpas\b/' => 'ANPAS',
        '/\b(cnsas|soccorso alpino)\b/' => 'CNSAS',
        '/\b(guardia costiera|capitaneria)\b/' => 'Guardia Costiera',
        '/\btrenitalia\b/' => 'Trenitalia',
        '/\brfi\b/' => 'RFI',
        '/\brai\b/' => 'RAI',
        '/\banas\b/' => 'ANAS',
        '/\benav\b/' => 'ENAV',
        '/\bari\b/' => 'A.R.I. Associazione Radioamatori Italiani',
        '/\bcisar\b/' => 'C.I.S.A.R.',
        '/\b(aeronautica militare|aereonautica militare)\b/' => 'Aeronautica Militare',
        '/\besercito\b/' => 'Esercito Italiano',
        '/\bmarina militare\b/' => 'Marina Militare',
    ],
];
