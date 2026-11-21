-- =====================================================================
--  ListenIn — dati di riferimento (seed.sql) · versione 0.3.2
--  Eseguire DOPO schema.sql
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  Ambiti e servizi (8 ambiti principali + sottoservizi)
-- ---------------------------------------------------------------------
INSERT INTO listenin_services (id, parent_id, slug, name, color, sort_order) VALUES
 (1,  NULL, 'radioamatori',      'Radioamatori',                    '#3B7A57', 10),
 (2,  NULL, 'ponti-ripetitori',  'Ponti ripetitori',                '#4F5BB5', 20),
 (3,  NULL, 'emergenza',         'Emergenza e protezione civile',   '#B8362B', 30),
 (4,  NULL, 'aeronautico',       'Aeronautico',                     '#1D6FA5', 40),
 (5,  NULL, 'marittimo',         'Marittimo',                       '#0B7A80', 50),
 (6,  NULL, 'pmr-lpd',           'PMR, LPD e uso libero',           '#A86A12', 60),
 (7,  NULL, 'utility',           'Utility',                         '#5E6673', 70),
 (8,  NULL, 'broadcast',         'Broadcast',                       '#8A3FA0', 80);

INSERT INTO listenin_services (parent_id, slug, name, color, sort_order) VALUES
 (1, 'radioamatori-simplex',      'Simplex e QSO',                         NULL, 1),
 (1, 'radioamatori-beacon',       'Beacon',                                NULL, 2),
 (1, 'radioamatori-satelliti',    'Satelliti e ISS',                       NULL, 3),
 (1, 'radioamatori-dati',         'APRS, packet e modi digitali',          NULL, 4),
 (1, 'radioamatori-nodi',         'Nodi Echolink e gateway',               NULL, 5),
 (2, 'ponti-analogici',           'Ripetitori analogici',                  NULL, 1),
 (2, 'ponti-dmr',                 'Ripetitori DMR',                        NULL, 2),
 (2, 'ponti-dstar',               'Ripetitori D-STAR',                     NULL, 3),
 (2, 'ponti-c4fm',                'Ripetitori C4FM',                       NULL, 4),
 (2, 'ponti-link',                'Link e trasponder',                     NULL, 5),
 (3, 'emergenza-vvf',             'Vigili del Fuoco',                      NULL, 1),
 (3, 'emergenza-118',             'Emergenza sanitaria 118',               NULL, 2),
 (3, 'emergenza-elisoccorso',     'Elisoccorso',                           NULL, 3),
 (3, 'emergenza-protezione-civile','Protezione civile',                    NULL, 4),
 (3, 'emergenza-aib',             'Antincendio boschivo',                  NULL, 5),
 (3, 'emergenza-soccorso-alpino', 'Soccorso alpino e speleologico',        NULL, 6),
 (3, 'emergenza-volontariato',    'Croce Rossa e pubbliche assistenze',    NULL, 7),
 (4, 'aero-torre',                'Torri di controllo e ground',           NULL, 1),
 (4, 'aero-avvicinamento',        'Avvicinamento, ACC e radar',            NULL, 2),
 (4, 'aero-informazioni',         'ATIS, VOLMET e FIS',                    NULL, 3),
 (4, 'aero-servizi',              'Servizi aeroportuali e handling',       NULL, 4),
 (4, 'aero-militare',             'Aeronautica militare',                  NULL, 5),
 (4, 'aero-radioassistenze',      'Radioassistenze (VOR, ILS)',            NULL, 6),
 (4, 'aero-aviazione-generale',   'Aviosuperfici e volo libero',           NULL, 7),
 (5, 'mare-canali',               'Canali nautici VHF',                    NULL, 1),
 (5, 'mare-guardia-costiera',     'Capitanerie e Guardia costiera',        NULL, 2),
 (5, 'mare-porti',                'Servizi portuali',                      NULL, 3),
 (5, 'mare-meteo',                'Meteomar e avvisi ai naviganti',        NULL, 4),
 (5, 'mare-navigazione',          'Navigazione e traghetti',               NULL, 5),
 (6, 'pmr446',                    'PMR446',                                NULL, 1),
 (6, 'lpd',                       'LPD 433 MHz',                           NULL, 2),
 (6, 'cb',                        'CB 27 MHz',                             NULL, 3),
 (6, 'civili-43',                 'Civili 43 MHz',                         NULL, 4),
 (6, 'ism',                       'Dispositivi ISM e telecomandi',         NULL, 5),
 (7, 'utility-forze-ordine',      'Forze dell''ordine',                    NULL, 1),
 (7, 'utility-polizia-locale',    'Polizia locale',                        NULL, 2),
 (7, 'utility-vigilanza',         'Vigilanza privata',                     NULL, 3),
 (7, 'utility-trasporti',         'Trasporti e ferrovie',                  NULL, 4),
 (7, 'utility-autostrade',        'Autostrade e viabilità',                NULL, 5),
 (7, 'utility-taxi',              'Taxi e NCC',                            NULL, 6),
 (7, 'utility-aziende',           'Aziende e industria',                   NULL, 7),
 (7, 'utility-pa',                'Enti e pubblica amministrazione',       NULL, 8),
 (7, 'utility-eventi',            'Eventi, sport e manifestazioni',        NULL, 9),
 (7, 'utility-militare',          'Forze armate',                          NULL, 10),
 (7, 'utility-telemetria',        'Telecontrolli, telemetria e paging',    NULL, 11),
 (7, 'utility-altro',             'Altro e da classificare',               NULL, 99),
 (8, 'broadcast-fm',              'Radio FM',                              NULL, 1),
 (8, 'broadcast-dab',             'DAB+',                                  NULL, 2),
 (8, 'broadcast-tv',              'Televisione',                           NULL, 3),
 (8, 'broadcast-regia',           'Regie, feed e trasferimenti',           NULL, 4),
 (8, 'broadcast-onde',            'Onde lunghe, medie e corte',            NULL, 5);

-- ---------------------------------------------------------------------
--  Modi operativi normalizzati
-- ---------------------------------------------------------------------
INSERT INTO listenin_modes (code, name, family, default_bandwidth_hz, access_code, sort_order) VALUES
 ('FM',      'FM (16K0F3E)',               'analogico', 16000,   'none',       10),
 ('NFM',     'FM a banda stretta',         'analogico', 11000,   'none',       11),
 ('WFM',     'FM a banda larga',           'analogico', 180000,  'none',       12),
 ('AM',      'AM',                         'analogico', 6000,    'none',       20),
 ('USB',     'SSB banda laterale superiore','analogico', 2700,   'none',       30),
 ('LSB',     'SSB banda laterale inferiore','analogico', 2700,   'none',       31),
 ('CW',      'CW (telegrafia)',            'analogico', 500,     'none',       40),
 ('DMR',     'DMR (ETSI TS 102 361)',      'digitale',  12500,   'color_code', 50),
 ('DSTAR',   'D-STAR',                     'digitale',  6250,    'none',       51),
 ('C4FM',    'C4FM / System Fusion',       'digitale',  12500,   'none',       52),
 ('NXDN',    'NXDN',                       'digitale',  6250,    'ran',        53),
 ('P25',     'APCO P25',                   'digitale',  12500,   'nac',        54),
 ('TETRA',   'TETRA',                      'digitale',  25000,   'none',       55),
 ('DPMR',    'dPMR',                       'digitale',  6250,    'none',       56),
 ('DAB',     'DAB / DAB+',                 'digitale',  1536000, 'none',       57),
 ('DVBT',    'DVB-T / DVB-T2',             'digitale',  8000000, 'none',       58),
 ('POCSAG',  'POCSAG (paging)',            'dati',      12500,   'none',       60),
 ('ACARS',   'ACARS',                      'dati',      6000,    'none',       61),
 ('PACKET',  'Packet / APRS (AFSK)',       'dati',      12500,   'none',       62),
 ('DATI',    'Dati / telemetria',          'dati',      NULL,    'none',       63),
 ('ND',      'Non determinato',            'analogico', NULL,    'none',       99);

-- ---------------------------------------------------------------------
--  Bande ITU
-- ---------------------------------------------------------------------
INSERT INTO listenin_itu_bands (code, name, min_hz, max_hz) VALUES
 ('VLF', 'Very Low Frequency',        3000,        30000),
 ('LF',  'Low Frequency',             30000,       300000),
 ('MF',  'Medium Frequency',          300000,      3000000),
 ('HF',  'High Frequency',            3000000,     30000000),
 ('VHF', 'Very High Frequency',       30000000,    300000000),
 ('UHF', 'Ultra High Frequency',      300000000,   3000000000),
 ('SHF', 'Super High Frequency',      3000000000,  30000000000);

-- ---------------------------------------------------------------------
--  Segmenti del piano delle frequenze (estendibili dal pannello DB)
--  Viene assegnato automaticamente il segmento più stretto che contiene la frequenza.
-- ---------------------------------------------------------------------
INSERT INTO listenin_band_plans (slug, name, min_hz, max_hz, service_id) VALUES
 ('onde-medie',        'Radiodiffusione onde medie',      526500,     1606500,    8),
 ('cb-27',             'CB 27 MHz (40 canali)',           26965000,   27405000,   6),
 ('hf-10m',            '10 m radioamatori',               28000000,   29700000,   1),
 ('vhf-6m',            '6 m radioamatori',                50000000,   52000000,   1),
 ('fm-broadcast',      'Radiodiffusione FM',              87500000,   108000000,  8),
 ('aero-radionav',     'Radionavigazione aeronautica',    108000000,  117975000,  4),
 ('aero-vhf',          'Comunicazioni aeronautiche VHF',  117975000,  137000000,  4),
 ('vhf-2m',            '2 m radioamatori',                144000000,  146000000,  1),
 ('vhf-2m-ripetitori', '2 m ripetitori (uscite R0–R7a)',  145600000,  145800000,  2),
 ('nautico-vhf',       'Servizio mobile marittimo VHF',   156000000,  162050000,  5),
 ('dab-banda-iii',     'DAB banda III',                   174000000,  240000000,  8),
 ('aero-uhf-mil',      'Aeronautico militare UHF',        225000000,  400000000,  4),
 ('tetra-pubblico',    'TETRA sicurezza pubblica',        380000000,  400000000,  7),
 ('uhf-70cm',          '70 cm radioamatori',              430000000,  440000000,  1),
 ('lpd-433',           'LPD 433 MHz',                     433075000,  434775000,  6),
 ('pmr-446',           'PMR446',                          446000000,  446200000,  6),
 ('uhf-23cm',          '23 cm radioamatori',              1240000000, 1300000000, 1);

-- ---------------------------------------------------------------------
--  Nazioni
-- ---------------------------------------------------------------------
INSERT INTO listenin_countries (iso2, name) VALUES
 ('IT','Italia'), ('SM','San Marino'), ('VA','Città del Vaticano'), ('CH','Svizzera'),
 ('FR','Francia'), ('AT','Austria'), ('SI','Slovenia'), ('HR','Croazia'), ('MT','Malta');

-- ---------------------------------------------------------------------
--  Regioni italiane (codice ISTAT)
-- ---------------------------------------------------------------------
INSERT INTO listenin_regions (id, country_iso2, istat_code, slug, name) VALUES
 (1,'IT','01','piemonte','Piemonte'),
 (2,'IT','02','valle-d-aosta','Valle d''Aosta'),
 (3,'IT','03','lombardia','Lombardia'),
 (4,'IT','04','trentino-alto-adige','Trentino-Alto Adige'),
 (5,'IT','05','veneto','Veneto'),
 (6,'IT','06','friuli-venezia-giulia','Friuli-Venezia Giulia'),
 (7,'IT','07','liguria','Liguria'),
 (8,'IT','08','emilia-romagna','Emilia-Romagna'),
 (9,'IT','09','toscana','Toscana'),
 (10,'IT','10','umbria','Umbria'),
 (11,'IT','11','marche','Marche'),
 (12,'IT','12','lazio','Lazio'),
 (13,'IT','13','abruzzo','Abruzzo'),
 (14,'IT','14','molise','Molise'),
 (15,'IT','15','campania','Campania'),
 (16,'IT','16','puglia','Puglia'),
 (17,'IT','17','basilicata','Basilicata'),
 (18,'IT','18','calabria','Calabria'),
 (19,'IT','19','sicilia','Sicilia'),
 (20,'IT','20','sardegna','Sardegna');

-- ---------------------------------------------------------------------
--  Province (sigla automobilistica)
-- ---------------------------------------------------------------------
INSERT INTO listenin_provinces (region_id, code, name) VALUES
 (1,'AL','Alessandria'),(1,'AT','Asti'),(1,'BI','Biella'),(1,'CN','Cuneo'),(1,'NO','Novara'),
 (1,'TO','Torino'),(1,'VB','Verbano-Cusio-Ossola'),(1,'VC','Vercelli'),
 (2,'AO','Aosta'),
 (3,'BG','Bergamo'),(3,'BS','Brescia'),(3,'CO','Como'),(3,'CR','Cremona'),(3,'LC','Lecco'),
 (3,'LO','Lodi'),(3,'MN','Mantova'),(3,'MI','Milano'),(3,'MB','Monza e Brianza'),(3,'PV','Pavia'),
 (3,'SO','Sondrio'),(3,'VA','Varese'),
 (4,'BZ','Bolzano'),(4,'TN','Trento'),
 (5,'BL','Belluno'),(5,'PD','Padova'),(5,'RO','Rovigo'),(5,'TV','Treviso'),(5,'VE','Venezia'),
 (5,'VR','Verona'),(5,'VI','Vicenza'),
 (6,'GO','Gorizia'),(6,'PN','Pordenone'),(6,'TS','Trieste'),(6,'UD','Udine'),
 (7,'GE','Genova'),(7,'IM','Imperia'),(7,'SP','La Spezia'),(7,'SV','Savona'),
 (8,'BO','Bologna'),(8,'FE','Ferrara'),(8,'FC','Forlì-Cesena'),(8,'MO','Modena'),(8,'PR','Parma'),
 (8,'PC','Piacenza'),(8,'RA','Ravenna'),(8,'RE','Reggio Emilia'),(8,'RN','Rimini'),
 (9,'AR','Arezzo'),(9,'FI','Firenze'),(9,'GR','Grosseto'),(9,'LI','Livorno'),(9,'LU','Lucca'),
 (9,'MS','Massa-Carrara'),(9,'PI','Pisa'),(9,'PT','Pistoia'),(9,'PO','Prato'),(9,'SI','Siena'),
 (10,'PG','Perugia'),(10,'TR','Terni'),
 (11,'AN','Ancona'),(11,'AP','Ascoli Piceno'),(11,'FM','Fermo'),(11,'MC','Macerata'),(11,'PU','Pesaro e Urbino'),
 (12,'FR','Frosinone'),(12,'LT','Latina'),(12,'RI','Rieti'),(12,'RM','Roma'),(12,'VT','Viterbo'),
 (13,'AQ','L''Aquila'),(13,'CH','Chieti'),(13,'PE','Pescara'),(13,'TE','Teramo'),
 (14,'CB','Campobasso'),(14,'IS','Isernia'),
 (15,'AV','Avellino'),(15,'BN','Benevento'),(15,'CE','Caserta'),(15,'NA','Napoli'),(15,'SA','Salerno'),
 (16,'BA','Bari'),(16,'BT','Barletta-Andria-Trani'),(16,'BR','Brindisi'),(16,'FG','Foggia'),
 (16,'LE','Lecce'),(16,'TA','Taranto'),
 (17,'MT','Matera'),(17,'PZ','Potenza'),
 (18,'CZ','Catanzaro'),(18,'CS','Cosenza'),(18,'KR','Crotone'),(18,'RC','Reggio Calabria'),(18,'VV','Vibo Valentia'),
 (19,'AG','Agrigento'),(19,'CL','Caltanissetta'),(19,'CT','Catania'),(19,'EN','Enna'),(19,'ME','Messina'),
 (19,'PA','Palermo'),(19,'RG','Ragusa'),(19,'SR','Siracusa'),(19,'TP','Trapani'),
 (20,'CA','Cagliari'),(20,'NU','Nuoro'),(20,'OR','Oristano'),(20,'SS','Sassari'),(20,'SU','Sud Sardegna');
