<?php
/**
 * ListenIn — configurazione.
 * Copiare in config/config.php e personalizzare. config.php NON va versionato.
 */
declare(strict_types=1);

return [
    'app' => [
        'name'        => 'ListenIn',
        'tagline'     => 'Database collaborativo delle frequenze radio',
        // URL pubblico completo, senza slash finale (determina anche il percorso del cookie)
        'base_url'    => 'https://www.example.org/webapp/listenin',
        'env'         => 'production',                      // production | development
        // Segreto per HMAC (IP hash, rate limit). Generare con: php -r "echo bin2hex(random_bytes(32));"
        'secret'      => 'CAMBIAMI-con-64-caratteri-esadecimali-casuali',
        'timezone'    => 'Europe/Rome',
        'contact_email' => 'info@example.org',
    ],

    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=listenin;charset=utf8mb4',
        'user'     => 'listenin',
        'password' => '',
    ],

    'session' => [
        'name'          => 'listenin_sid',
        'idle_timeout'  => 7200,     // secondi di inattività
        'absolute_ttl'  => 43200,    // durata massima della sessione
        // In sviluppo su http://localhost impostare a false, in produzione SEMPRE true
        'cookie_secure' => true,
    ],

    'security' => [
        // Argon2id se disponibile, altrimenti bcrypt (fallback automatico)
        'password_algo'      => 'argon2id',
        'password_min_length'=> 10,
        'login_max_failures' => 8,       // blocco temporaneo dell'account dopo N errori
        'login_lock_minutes' => 15,
        'hsts'               => true,
        // Proxy fidati (es. Cloudflare): se valorizzato si legge X-Forwarded-For solo da questi IP
        'trusted_proxies'    => [],
    ],

    // Limiti per finestra: [richieste, secondi]
    'rate_limits' => [
        'login_ip'       => [20, 900],
        'login_account'  => [8, 900],
        'register'       => [5, 3600],
        'password_reset' => [5, 3600],
        'report_anon'    => [5, 3600],
        'report_user'    => [30, 3600],
        'proposal'       => [30, 3600],
        'api_read'       => [240, 60],
        'export'         => [10, 600],
        'account_change' => [10, 3600],
    ],

    // Reputazione e collaborazione (valori predefiniti; tutti facoltativi)
    'trust' => [
        'auto_approve_min' => 20,   // Trust Score oltre il quale le modifiche minori vengono approvate in automatico
    ],
    'points' => ['checkin' => 2, 'recupero' => 10, 'proposta' => 5, 'segnalazione' => 3],
    'checkin' => [
        'max_recoveries_per_day' => 40,   // oltre questa quota i check-in restano validi ma non contano come recuperi
    ],
    'webhooks' => [
        'max_per_user'    => 5,          // webhook per utente
        'max_failures'    => 10,         // errori consecutivi prima della disattivazione automatica
        'allowed_ports'   => [443],
        'include_imports' => false,      // vero = avvisa anche per ogni frequenza creata da un'importazione di massa
    ],
    'opendata' => [
        'auto_days' => 30,   // ogni quanti giorni generare una nuova copia completa (0 = solo a mano)
        // Licenza dei dati: ODbL 1.0 (confermata); verificare gli accordi con le fonti (es. inascolto.org)
        'license' => 'Open Database License (ODbL) 1.0',
        'license_url' => 'https://opendatacommons.org/licenses/odbl/1-0/',
    ],

    'reports' => [
        // true: chiunque può segnalare (con limiti più stretti e casella di presa visione)
        // false: solo utenti autenticati con email verificata
        'allow_anonymous'     => true,
        'ip_hash_retention_days' => 30,
    ],

    'export' => [
        'max_rows' => 20000,
    ],

    'legal' => [
        // Incrementare quando cambia il testo del disclaimer: verrà richiesta una nuova presa visione
        'disclaimer_version' => '2026-11',
        'terms_version'      => '2026-11',
    ],

    'mail' => [
        // 'mail' usa la funzione mail() di PHP; 'log' scrive in storage/logs/mail.log (sviluppo)
        'driver'    => 'mail',
        'from'      => 'no-reply@example.org',
        'from_name' => 'ListenIn',
    ],

    'tokens' => [
        'verify_email_ttl'   => 86400,   // 24 ore
        'change_email_ttl'   => 86400,
        'reset_password_ttl' => 3600,    // 1 ora
    ],

    'import' => [
        'max_upload_mb' => 25,     // dimensione massima dei file caricati dal pannello (entro i limiti di PHP)
        'fetch_hosts'   => ['www.istat.it'],   // siti da cui il pannello può scaricare file (solo HTTPS)
        'fetch_max_mb'  => 30,
        'step_seconds'  => 12,     // durata di ogni passo di importazione: abbassare se l'hosting ha limiti di tempo molto bassi
        // Regole di shift automatico per i ripetitori (Hz). Applicate solo ai servizi "ponti-*"
        // quando il dato sorgente non indica l'ingresso. Verificare con il band plan IARU/ARI in uso.
        'repeater_offsets' => [
            ['min' => 145600000, 'max' => 145800000, 'offset' => -600000],   // 2 m, uscite R0–R7a
            // ['min' => 430000000, 'max' => 430400000, 'offset' => 1600000], // 70 cm: da validare
        ],
    ],
];
