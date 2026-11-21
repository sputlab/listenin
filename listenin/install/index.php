<?php
/**
 * ListenIn — installazione guidata dal browser (per hosting condivisi senza terminale).
 *
 * Sicurezza: l'installer parte solo se esiste il file app/storage/ALLOW_INSTALL, che si crea via FTP
 * e viene cancellato a installazione conclusa. Se la configurazione esiste già, l'installer si rifiuta
 * di procedere. A fine lavoro si può eliminare l'intera cartella install/ (anche con un clic).
 */
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_name('listenin_install');
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$appDir = realpath((string) (require dirname(__DIR__) . '/app-path.php')) ?: '';
$configFile = $appDir . '/config/config.php';
$allowFile = $appDir . '/storage/ALLOW_INSTALL';
$lockFile = $appDir . '/storage/installed.lock';

function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function page(string $title, string $body): never
{
    echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . e($title) . ' · Installazione ListenIn</title><style>'
        . ':root{color-scheme:light dark;--bg:#EEF2F4;--card:#fff;--ink:#15212C;--soft:#4E5E6C;--rule:#C8D2DA;--panel:#16324F;--signal:#F2B544;--ok:#2F7D4F;--bad:#B3261E}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#0E1720;--card:#15212C;--ink:#E4EBF1;--soft:#9AABBA;--rule:#2C3C4A;--panel:#0A1420;--ok:#6CC490;--bad:#F08A80}}'
        . '*{box-sizing:border-box}body{margin:0;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}'
        . 'header{background:var(--panel);color:#fff;padding:1rem 1.25rem;font-weight:700;font-size:1.2rem;border-bottom:4px solid var(--signal)}'
        . 'main{max-width:46rem;margin:1.25rem auto;padding:0 1rem 3rem}.card{background:var(--card);border:1px solid var(--rule);border-radius:8px;padding:1.1rem 1.25rem;margin-bottom:1rem}'
        . 'h1{font-size:1.5rem;margin:.2rem 0 .8rem}h2{font-size:1.05rem;margin:0 0 .6rem}label{display:block;font-weight:600;font-size:.9rem;margin:.7rem 0 .2rem}'
        . 'input[type=text],input[type=password],input[type=email],input[type=url]{width:100%;padding:.55rem .65rem;border:1px solid var(--rule);border-radius:6px;font:inherit;background:var(--card);color:var(--ink)}'
        . '.hint{font-size:.85rem;color:var(--soft);margin:.2rem 0 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:0 1rem}'
        . 'button,.btn{display:inline-block;background:var(--panel);color:#fff;border:0;border-radius:6px;padding:.65rem 1.2rem;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
        . '.req{list-style:none;padding:0;margin:0}.req li{padding:.2rem 0;border-bottom:1px solid var(--rule)}.ok{color:var(--ok);font-weight:700}.bad{color:var(--bad);font-weight:700}'
        . '.err{border-left:4px solid var(--bad);padding:.6rem .9rem;background:var(--card);margin-bottom:1rem}code,pre{background:rgba(127,127,127,.15);padding:.1rem .35rem;border-radius:4px}'
        . 'pre{padding:.7rem;overflow:auto;white-space:pre-wrap}ol li{margin:.35rem 0}.check{display:flex;gap:.5rem;align-items:flex-start;margin-top:.7rem}.check input{margin-top:.35rem}'
        . '</style></head><body><header>ListenIn · installazione guidata</header><main>' . $body . '</main></body></html>';
    exit;
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

// ---------------------------------------------------------------- già installato
if ($appDir === '' || !is_dir($appDir)) {
    page('Errore', '<div class="card"><h1>Cartella dell\'applicazione non trovata</h1><p>Controlla il percorso indicato in <code>app-path.php</code>: deve puntare alla cartella <code>app</code>.</p></div>');
}

if (is_file($configFile) || is_file($lockFile)) {
    if (($_POST['action'] ?? '') === 'cleanup' && csrf_ok()) {
        $ok = @unlink(__FILE__);
        @rmdir(__DIR__);
        page('Installer eliminato', $ok
            ? '<div class="card"><h1>Fatto</h1><p>La cartella dell\'installer è stata eliminata. <a href="../">Apri ListenIn</a>.</p></div>'
            : '<div class="card"><h1>Eliminazione manuale necessaria</h1><p>Non è stato possibile eliminare il file automaticamente. Cancella la cartella <code>install</code> via FTP.</p></div>');
    }
    page('Già installato', '<div class="card"><h1>ListenIn è già installato</h1>'
        . '<p>Per ripetere l\'installazione bisogna prima eliminare <code>app/config/config.php</code> e <code>app/storage/installed.lock</code>. Attenzione: ripetere l\'installazione sullo stesso database cancella i dati.</p>'
        . '<p><a class="btn" href="../">Vai all\'applicazione</a></p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="action" value="cleanup">'
        . '<h2>Per sicurezza elimina l\'installer</h2><p class="hint">Non serve più. Se il pulsante non riesce a eliminarlo, cancella la cartella <code>install</code> via FTP.</p><button>Elimina l\'installer</button></form></div>');
}

// ---------------------------------------------------------------- protezione di avvio
if (!is_file($allowFile)) {
    page('Autorizzazione', '<div class="card"><h1>Prima di iniziare</h1>'
        . '<p>Per evitare che altri avviino l\'installazione al posto tuo, serve un\'autorizzazione esplicita:</p>'
        . '<ol><li>Con il tuo programma FTP (o il file manager dell\'hosting) apri la cartella <code>app/storage/</code> di ListenIn.</li>'
        . '<li>Crea al suo interno un file vuoto chiamato esattamente <code>ALLOW_INSTALL</code> (senza estensione).</li>'
        . '<li>Ricarica questa pagina.</li></ol><p class="hint">Il file viene cancellato automaticamente a installazione conclusa.</p></div>');
}

// ---------------------------------------------------------------- requisiti
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$writable = static fn(string $p): bool => is_dir($p) && is_writable($p);
$reqs = [
    ['PHP 8.2 o superiore (versione attuale: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80200, true],
    ['Estensione PDO MySQL', extension_loaded('pdo_mysql'), true],
    ['Estensione mbstring', extension_loaded('mbstring'), true],
    ['Estensione JSON', extension_loaded('json'), true],
    ['Cartella app/config scrivibile', $writable($appDir . '/config'), true],
    ['Cartella app/storage scrivibile', $writable($appDir . '/storage'), true],
    ['File di database presenti (schema.sql, seed.sql)', is_file($appDir . '/database/schema.sql') && is_file($appDir . '/database/seed.sql'), true],
    ['Hash delle password Argon2id (altrimenti si usa bcrypt, ugualmente sicuro)', defined('PASSWORD_ARGON2ID'), false],
    ['Connessione HTTPS (consigliata: protegge le password)', $https, false],
    ['Estensione intl (migliora la normalizzazione dei testi, facoltativa)', extension_loaded('intl'), false],
    ['Funzione mail() disponibile (per le email di conferma)', function_exists('mail'), false],
];
$blocked = false;
foreach ($reqs as [$label, $ok, $must]) {
    if (!$ok && $must) {
        $blocked = true;
    }
}

// ---------------------------------------------------------------- valori predefiniti
$host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$basePath = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')))), '/');
$defaults = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'base_url' => ($https ? 'https' : 'http') . '://' . $host . $basePath,
    'site_name' => 'ListenIn', 'contact_email' => '', 'from_email' => 'no-reply@' . preg_replace('/^www\./', '', explode(':', $host)[0]),
    'admin_email' => '', 'admin_nick' => '',
];
$in = $defaults;
$errors = [];

// ---------------------------------------------------------------- installazione
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with($t, ';')) {
            $out[] = $buf;
            $buf = '';
        }
    }
    if (trim($buf) !== '') {
        $out[] = $buf;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocked) {
    if (!csrf_ok()) {
        $errors[] = 'Sessione scaduta: ricarica la pagina e riprova.';
    }
    foreach (array_keys($defaults) as $k) {
        $in[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
    $overwrite = !empty($_POST['overwrite']);

    if ($in['db_name'] === '' || $in['db_user'] === '') {
        $errors[] = 'Indica il nome del database e l\'utente.';
    }
    if (!preg_match('~^https?://[a-z0-9.\-:\[\]]+(/[\w.\-/]*)?$~i', $in['base_url'])) {
        $errors[] = 'L\'indirizzo del sito non è valido (es. https://www.tuodominio.it/webapp/listenin).';
    }
    foreach (['contact_email' => 'email di contatto', 'from_email' => 'email mittente', 'admin_email' => 'email dell\'amministratore'] as $k => $l) {
        if (!filter_var($in[$k], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Indirizzo non valido: ' . $l . '.';
        }
    }
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}_.\-]{2,31}$/u', $in['admin_nick'])) {
        $errors[] = 'Il nickname dell\'amministratore deve avere 3-32 caratteri (lettere, numeri, punto, trattino, underscore).';
    }
    if (mb_strlen($adminPass) < 10) {
        $errors[] = 'La password dell\'amministratore deve avere almeno 10 caratteri.';
    } elseif ($adminPass !== $adminPass2) {
        $errors[] = 'Le due password non coincidono.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $hostPart = $in['db_host'];
            $port = '';
            if (preg_match('/^(.+):(\d{2,5})$/', $hostPart, $m)) {
                [$hostPart, $port] = [$m[1], ';port=' . $m[2]];
            }
            $pdo = new PDO('mysql:host=' . $hostPart . $port . ';dbname=' . $in['db_name'] . ';charset=utf8mb4', $in['db_user'], (string) $_POST['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $ex) {
            $errors[] = 'Connessione al database non riuscita: ' . $ex->getMessage() . ' Controlla host, nome del database, utente e password nel pannello dell\'hosting.';
        }
    }
    if ($pdo && !$errors) {
        $exists = $pdo->query("SHOW TABLES LIKE 'listenin\\_users'")->fetchColumn();
        if ($exists && !$overwrite) {
            $errors[] = 'Nel database esistono già le tabelle di ListenIn. Se vuoi davvero reinstallare (cancellando tutti i dati) spunta la casella di conferma.';
            $_SESSION['ask_overwrite'] = true;
        }
    }

    if ($pdo && !$errors) {
        // 1. configurazione
        $cfg = require $appDir . '/config/config.example.php';
        $cfg['app']['name'] = $in['site_name'] !== '' ? $in['site_name'] : 'ListenIn';
        $cfg['app']['base_url'] = rtrim($in['base_url'], '/');
        $cfg['app']['env'] = 'production';
        $cfg['app']['secret'] = bin2hex(random_bytes(32));
        $cfg['app']['contact_email'] = $in['contact_email'];
        $cfg['db'] = ['dsn' => 'mysql:host=' . $hostPart . $port . ';dbname=' . $in['db_name'] . ';charset=utf8mb4', 'user' => $in['db_user'], 'password' => (string) $_POST['db_pass']];
        $cfg['session']['cookie_secure'] = $https;
        $cfg['mail']['driver'] = 'mail';
        $cfg['mail']['from'] = $in['from_email'];
        $configCode = "<?php\n// Generato dall'installer di ListenIn il " . date('Y-m-d H:i') . ".\n// Contiene credenziali: non pubblicare e non versionare questo file.\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($configFile, $configCode, LOCK_EX) === false) {
            $errors[] = 'Non riesco a scrivere app/config/config.php: controlla i permessi della cartella app/config.';
        } else {
            @chmod($configFile, 0640);
        }
    }

    if ($pdo && !$errors) {
        // 2. database
        try {
            foreach (['schema.sql', 'seed.sql'] as $f) {
                foreach (split_sql((string) file_get_contents($appDir . '/database/' . $f)) as $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $ex) {
                        throw new RuntimeException($f . ': ' . $ex->getMessage() . ' — istruzione: ' . mb_substr(trim($stmt), 0, 120));
                    }
                }
            }
            // 3. migrazioni (versioni successive alla struttura di base)
            require_once $appDir . '/src/bootstrap.php';
            \ListenIn\Core\Migrator::run();
            @file_put_contents($appDir . '/storage/cache/schema.stamp', \ListenIn\Core\Migrator::latest());
            // 4. amministratore
            $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
            $hash = password_hash($adminPass, $algo, $algo === PASSWORD_BCRYPT ? ['cost' => 12] : []);
            $disc = (string) ($cfg['legal']['disclaimer_version'] ?? '');
            $pdo->prepare("INSERT INTO listenin_users (email, email_verified_at, nickname, password_hash, role, status, terms_accepted_at, disclaimer_version, disclaimer_accepted_at)
                           VALUES (:e, NOW(), :n, :p, 'admin', 'active', NOW(), :d, NOW())")
                ->execute(['e' => strtolower($in['admin_email']), 'n' => $in['admin_nick'], 'p' => $hash, 'd' => $disc]);
        } catch (Throwable $ex) {
            @unlink($configFile);   // l'installazione può essere ritentata
            $errors[] = 'Errore durante la creazione del database: ' . $ex->getMessage();
        }
    }

    if (!$errors) {
        @file_put_contents($lockFile, date('c'));
        @unlink($allowFile);
        unset($_SESSION['csrf']);
        $csv = is_file($appDir . '/data/manuale_frequenze_v2.csv');
        page('Installazione completata', '<div class="card"><h1>Installazione completata</h1>'
            . '<p class="ok">ListenIn è pronto.</p>'
            . '<ol><li><a href="' . e(rtrim($in['base_url'], '/')) . '/#/accedi">Accedi</a> con l\'email e la password dell\'amministratore.</li>'
            . ($csv ? '<li>Apri la voce <strong>Importazione</strong> del menu in alto (oppure Moderazione → Importazione) e importa i dati del manuale incluso: parti con una <em>simulazione</em>, poi importa davvero.</li>'
            . '<li>In <strong>Moderazione → Sistema</strong> premi «Importa i baricentri dei comuni»: abilita distanze e ricerca per prossimità.</li>' : '')
            . '<li>Per i dati di inascolto.org: nella stessa schermata carica il file CSV esportato e ripeti simulazione e importazione.</li>'
            . '<li>Elimina l\'installer (pulsante qui sotto). <strong>Non toglie nulla</strong>: l\'importazione dei dati resta sempre disponibile agli amministratori in <em>Moderazione → Importazione</em> (e nel menu in alto alla voce “Importazione”).</li></ol>'
            . (!$https ? '<p class="err"><strong>Attenzione:</strong> stai usando HTTP. Attiva HTTPS sul tuo dominio, poi imposta <code>session.cookie_secure</code> a <code>true</code> in <code>app/config/config.php</code> e <code>https</code> in <code>app.base_url</code>.</p>' : '')
            . '<form method="post" action=""><input type="hidden" name="csrf" value="' . e(csrf()) . '"><input type="hidden" name="action" value="cleanup"><button>Elimina l\'installer</button></form></div>');
    }
}

// ---------------------------------------------------------------- form
$list = '';
foreach ($reqs as [$label, $ok, $must]) {
    $list .= '<li><span class="' . ($ok ? 'ok' : ($must ? 'bad' : '')) . '">' . ($ok ? '✓' : ($must ? '✗' : '–')) . '</span> ' . e($label) . '</li>';
}
$err = $errors ? '<div class="err" role="alert"><strong>Controlla questi punti:</strong><ul>' . implode('', array_map(static fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '';
$f = static fn(string $k, string $label, string $type = 'text', string $hint = '', bool $req = true): string =>
    '<label for="' . $k . '">' . e($label) . '</label><input id="' . $k . '" name="' . $k . '" type="' . $type . '" value="' . ($type === 'password' ? '' : e($GLOBALS['in'][$k] ?? '')) . '"'
    . ($req ? ' required' : '') . ' autocomplete="off">' . ($hint ? '<p class="hint">' . e($hint) . '</p>' : '');

$form = $blocked
    ? '<div class="err"><strong>Mancano dei requisiti indispensabili.</strong> Risolvili (o chiedi al fornitore dell\'hosting) e ricarica la pagina.</div>'
    : '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . e(csrf()) . '">'
    . '<div class="card"><h2>1. Database</h2><p class="hint">Crea prima un database MySQL/MariaDB vuoto dal pannello dell\'hosting e annota i dati di accesso.</p><div class="grid">'
    . $f('db_host', 'Server del database', 'text', 'Di solito localhost') . $f('db_name', 'Nome del database')
    . $f('db_user', 'Utente del database') . $f('db_pass', 'Password del database', 'password', '', false) . '</div>'
    . (!empty($_SESSION['ask_overwrite']) ? '<div class="check"><input type="checkbox" id="overwrite" name="overwrite" value="1"><label for="overwrite" style="margin:0;font-weight:400">Il database contiene già un\'installazione di ListenIn: voglio reinstallare <strong>cancellando tutti i dati</strong>.</label></div>' : '')
    . '</div><div class="card"><h2>2. Sito</h2>'
    . $f('base_url', 'Indirizzo del sito', 'url', 'Senza slash finale. Deve coincidere con quello che digitano i visitatori, www compreso.')
    . '<div class="grid">' . $f('site_name', 'Nome del sito') . $f('contact_email', 'Email di contatto', 'email') . '</div>'
    . $f('from_email', 'Indirizzo mittente delle email di sistema', 'email', 'Meglio un indirizzo del tuo dominio: aumenta la consegna delle email di conferma.') . '</div>'
    . '<div class="card"><h2>3. Amministratore</h2><div class="grid">' . $f('admin_email', 'Email', 'email') . $f('admin_nick', 'Nickname')
    . $f('admin_pass', 'Password (almeno 10 caratteri)', 'password') . $f('admin_pass2', 'Ripeti la password', 'password') . '</div></div>'
    . '<button type="submit">Installa ListenIn</button></form>';

page('Installazione', '<h1>Installazione di ListenIn</h1>' . $err
    . '<div class="card"><h2>Requisiti del server</h2><ul class="req">' . $list . '</ul></div>' . $form);
