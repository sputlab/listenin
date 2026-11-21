<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\Mailer;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Security;
use ListenIn\Core\Session;
use ListenIn\Core\Validator;

/** Gestione self-service degli account. */
final class UserService
{
    private const NICKNAME_RE = '/^[\p{L}\p{N}][\p{L}\p{N}_.\-]{2,31}$/u';
    private const GENERIC_SENT = 'Se l\'indirizzo è corretto riceverai a breve un\'email con le istruzioni.';

    // ------------------------------------------------------------------
    //  Registrazione e verifica
    // ------------------------------------------------------------------

    public static function register(array $in): array
    {
        RateLimiter::hit('register', Security::clientIp());
        $v = new Validator($in);
        $email = $v->email('email');
        $nickname = $v->string('nickname', 3, 32);
        $password = $v->password('password');
        if ($password !== null && ($in['password_confirm'] ?? null) !== $password) {
            $v->addError('password_confirm', 'Le password non coincidono.');
        }
        if ($nickname !== null && !preg_match(self::NICKNAME_RE, $nickname)) {
            $v->addError('nickname', 'Usa 3-32 caratteri tra lettere, numeri, punto, trattino e underscore.');
        }
        if ($password !== null && $email !== null && stripos($password, explode('@', $email)[0]) !== false) {
            $v->addError('password', 'La password non deve contenere il tuo indirizzo email.');
        }
        $v->accepted('accept_terms', 'Devi accettare i termini di utilizzo.');
        $v->accepted('accept_disclaimer', 'Devi confermare di aver preso visione del disclaimer.');
        if (!empty($in['website'])) { // honeypot
            $v->addError('email', 'Registrazione non accettata.');
        }
        $v->throwIfErrors();

        if (Database::value('SELECT 1 FROM listenin_users WHERE nickname = :n', ['n' => $nickname])) {
            throw ApiException::validation(['nickname' => 'Nickname già in uso.']);
        }
        $existing = Database::one('SELECT id, status FROM listenin_users WHERE email = :e', ['e' => $email]);
        if ($existing) {
            // Nessuna enumerazione degli account: stessa risposta, avviso al titolare
            Mailer::send($email, 'Tentativo di registrazione',
                "Qualcuno ha provato a registrarsi su " . Config::get('app.name') . " con il tuo indirizzo.\n"
                . "Se eri tu, puoi accedere o reimpostare la password da: " . Mailer::link('/password-dimenticata') . "\n"
                . "Se non eri tu, ignora questo messaggio.");
            return ['message' => 'Registrazione ricevuta. ' . self::GENERIC_SENT];
        }

        $now = Database::now();
        $id = Database::insert(
            'INSERT INTO listenin_users (email, nickname, password_hash, status, terms_accepted_at, disclaimer_version, disclaimer_accepted_at)
             VALUES (:e, :n, :p, \'pending\', :t, :dv, :t2)',
            ['e' => $email, 'n' => $nickname, 'p' => Security::hashPassword($password), 't' => $now,
                'dv' => (string) Config::get('legal.disclaimer_version'), 't2' => $now]
        );
        $token = self::issueToken($id, 'verify_email', (int) Config::get('tokens.verify_email_ttl', 86400));
        Mailer::send($email, 'Conferma il tuo indirizzo email',
            "Ciao {$nickname},\n\nper attivare il tuo account su " . Config::get('app.name') . " apri questo link entro 24 ore:\n"
            . Mailer::link('/verifica/' . $token) . "\n\nSe non hai richiesto la registrazione ignora questo messaggio.");
        return ['message' => 'Registrazione ricevuta. ' . self::GENERIC_SENT];
    }

    public static function resendVerification(array $in): array
    {
        RateLimiter::hit('password_reset', Security::clientIp());
        $email = (new Validator($in))->email('email');
        if ($email && ($u = Database::one("SELECT id, nickname FROM listenin_users WHERE email = :e AND status = 'pending'", ['e' => $email]))) {
            Database::run("DELETE FROM listenin_user_tokens WHERE user_id = :u AND purpose = 'verify_email'", ['u' => $u['id']]);
            $token = self::issueToken((int) $u['id'], 'verify_email', (int) Config::get('tokens.verify_email_ttl', 86400));
            Mailer::send($email, 'Conferma il tuo indirizzo email',
                "Ciao {$u['nickname']},\n\nper attivare il tuo account apri questo link:\n" . Mailer::link('/verifica/' . $token));
        }
        return ['message' => self::GENERIC_SENT];
    }

    public static function verifyEmail(string $token): array
    {
        $t = self::consumeToken($token, 'verify_email');
        Database::run(
            "UPDATE listenin_users SET status = 'active', email_verified_at = NOW() WHERE id = :id AND status = 'pending'",
            ['id' => $t['user_id']]
        );
        return ['message' => 'Indirizzo verificato: ora puoi accedere.'];
    }

    // ------------------------------------------------------------------
    //  Accesso
    // ------------------------------------------------------------------

    public static function login(array $in): array
    {
        $ip = Security::clientIp();
        RateLimiter::hit('login_ip', $ip);
        $v = new Validator($in);
        $email = $v->email('email');
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $v->throwIfErrors();
        RateLimiter::hit('login_account', $email);

        $u = Database::one('SELECT id, password_hash, status, failed_logins, locked_until FROM listenin_users WHERE email = :e', ['e' => $email]);
        // Verifica a tempo costante anche se l'utente non esiste
        $hash = $u['password_hash'] ?? '$2y$12$rEFnR1XWNf6zH79jEcP38.hdXl1Hpe.BsQD9RXBr0r3aPG7ANYm8O';
        $ok = password_verify($password, $hash) && $u !== null;

        if ($u && $u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()) {
            throw new ApiException(423, 'locked', 'Account temporaneamente bloccato per troppi tentativi. Riprova più tardi.');
        }
        if (!$ok) {
            if ($u) {
                $fails = (int) $u['failed_logins'] + 1;
                $lock = $fails >= (int) Config::get('security.login_max_failures', 8)
                    ? date('Y-m-d H:i:s', time() + 60 * (int) Config::get('security.login_lock_minutes', 15)) : null;
                Database::run('UPDATE listenin_users SET failed_logins = :f, locked_until = :l WHERE id = :id',
                    ['f' => $lock ? 0 : $fails, 'l' => $lock, 'id' => $u['id']]);
            }
            throw new ApiException(401, 'invalid_credentials', 'Email o password non corretti.');
        }
        if ($u['status'] === 'pending') {
            throw new ApiException(403, 'email_unverified', 'Conferma il tuo indirizzo email: controlla la posta in arrivo.');
        }
        if ($u['status'] === 'suspended') {
            throw new ApiException(403, 'suspended', 'Account sospeso. Contatta gli amministratori.');
        }
        if (Security::passwordNeedsRehash((string) $u['password_hash'])) {
            Database::run('UPDATE listenin_users SET password_hash = :h WHERE id = :id', ['h' => Security::hashPassword($password), 'id' => $u['id']]);
        }
        Database::run('UPDATE listenin_users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id', ['id' => $u['id']]);
        RateLimiter::clear('login_account', $email);
        Session::login((int) $u['id']);
        Auth::forget();
        return ['user' => Auth::publicView(Auth::user()), 'csrf' => Security::csrfToken()];
    }

    public static function logout(): array
    {
        Session::logout();
        Auth::forget();
        return ['csrf' => Security::csrfToken()];
    }

    /** Invalida le sessioni avviate prima dell'ultimo cambio password. */
    public static function enforceSessionValidity(): void
    {
        $uid = Session::userId();
        if (!$uid) {
            return;
        }
        $changed = Database::value('SELECT password_changed_at FROM listenin_users WHERE id = :id', ['id' => $uid]);
        if ($changed && strtotime((string) $changed) > (int) Session::get('auth_at', 0)) {
            Session::logout();
            Auth::forget();
        }
    }

    // ------------------------------------------------------------------
    //  Password dimenticata
    // ------------------------------------------------------------------

    public static function forgotPassword(array $in): array
    {
        RateLimiter::hit('password_reset', Security::clientIp());
        $email = (new Validator($in))->email('email');
        if ($email && ($u = Database::one("SELECT id, nickname FROM listenin_users WHERE email = :e AND status = 'active'", ['e' => $email]))) {
            RateLimiter::hit('password_reset', 'mail|' . $email);
            Database::run("DELETE FROM listenin_user_tokens WHERE user_id = :u AND purpose = 'reset_password'", ['u' => $u['id']]);
            $token = self::issueToken((int) $u['id'], 'reset_password', (int) Config::get('tokens.reset_password_ttl', 3600));
            Mailer::send($email, 'Reimposta la password',
                "Ciao {$u['nickname']},\n\nper scegliere una nuova password apri questo link entro un'ora:\n"
                . Mailer::link('/reimposta-password/' . $token) . "\n\nSe non hai fatto tu la richiesta, ignora questo messaggio.");
        }
        return ['message' => self::GENERIC_SENT];
    }

    public static function resetPassword(array $in): array
    {
        $v = new Validator($in);
        $password = $v->password('password');
        if ($password !== null && ($in['password_confirm'] ?? null) !== $password) {
            $v->addError('password_confirm', 'Le password non coincidono.');
        }
        $v->throwIfErrors();
        $t = self::consumeToken((string) ($in['token'] ?? ''), 'reset_password');
        Database::run(
            'UPDATE listenin_users SET password_hash = :h, password_changed_at = NOW(), failed_logins = 0, locked_until = NULL WHERE id = :id',
            ['h' => Security::hashPassword($password), 'id' => $t['user_id']]
        );
        Database::run("DELETE FROM listenin_user_tokens WHERE user_id = :u AND purpose = 'reset_password'", ['u' => $t['user_id']]);
        return ['message' => 'Password aggiornata. Ora puoi accedere.'];
    }

    // ------------------------------------------------------------------
    //  Profilo
    // ------------------------------------------------------------------

    public static function updateProfile(array $user, array $in): array
    {
        $v = new Validator($in);
        $nickname = $v->string('nickname', 3, 32);
        if ($nickname !== null && !preg_match(self::NICKNAME_RE, $nickname)) {
            $v->addError('nickname', 'Usa 3-32 caratteri tra lettere, numeri, punto, trattino e underscore.');
        }
        $locator = null;
        if (array_key_exists('default_locator', $in)) {
            $raw = $v->string('default_locator', 4, 8, false);
            if ($raw !== null && ($locator = Normalizer::normalizeLocator($raw)) === null) {
                $v->addError('default_locator', 'Locatore Maidenhead non valido (es. JN65tw).');
            }
        }
        $v->throwIfErrors();
        if (Database::value('SELECT 1 FROM listenin_users WHERE nickname = :n AND id <> :id', ['n' => $nickname, 'id' => $user['id']])) {
            throw ApiException::validation(['nickname' => 'Nickname già in uso.']);
        }
        Database::run('UPDATE listenin_users SET nickname = :n' . (array_key_exists('default_locator', $in) ? ', default_locator = :l' : '') . ' WHERE id = :id',
            ['n' => $nickname, 'id' => $user['id']] + (array_key_exists('default_locator', $in) ? ['l' => $locator] : []));
        Auth::forget();
        return ['user' => Auth::publicView(Auth::user())];
    }

    public static function requestEmailChange(array $user, array $in): array
    {
        RateLimiter::hit('account_change', 'u' . $user['id']);
        $v = new Validator($in);
        $email = $v->email('new_email');
        $v->throwIfErrors();
        self::checkPassword((int) $user['id'], (string) ($in['password'] ?? ''));
        if ($email === $user['email']) {
            throw ApiException::validation(['new_email' => 'È già il tuo indirizzo attuale.']);
        }
        if (!Database::value('SELECT 1 FROM listenin_users WHERE email = :e', ['e' => $email])) {
            Database::run("DELETE FROM listenin_user_tokens WHERE user_id = :u AND purpose = 'change_email'", ['u' => $user['id']]);
            $token = self::issueToken((int) $user['id'], 'change_email', (int) Config::get('tokens.change_email_ttl', 86400), $email);
            Mailer::send($email, 'Conferma il nuovo indirizzo email',
                "Ciao {$user['nickname']},\n\nper confermare il nuovo indirizzo apri questo link entro 24 ore:\n"
                . Mailer::link('/conferma-email/' . $token));
            Mailer::send((string) $user['email'], 'Richiesta di cambio email',
                "È stato richiesto il cambio dell'indirizzo email del tuo account verso un nuovo indirizzo.\n"
                . "Se non sei stato tu, cambia subito la password.");
        }
        return ['message' => 'Se il nuovo indirizzo è disponibile riceverai un link di conferma.'];
    }

    public static function confirmEmailChange(string $token): array
    {
        return Database::transaction(function () use ($token) {
            $t = self::consumeToken($token, 'change_email');
            if (Database::value('SELECT 1 FROM listenin_users WHERE email = :e', ['e' => $t['payload']])) {
                throw new ApiException(409, 'email_taken', 'Il nuovo indirizzo non è più disponibile.');
            }
            Database::run('UPDATE listenin_users SET email = :e, email_verified_at = NOW() WHERE id = :id',
                ['e' => $t['payload'], 'id' => $t['user_id']]);
            Auth::forget();
            return ['message' => 'Indirizzo email aggiornato.'];
        });
    }

    public static function changePassword(array $user, array $in): array
    {
        RateLimiter::hit('account_change', 'u' . $user['id']);
        $v = new Validator($in);
        $new = $v->password('new_password');
        if ($new !== null && ($in['new_password_confirm'] ?? null) !== $new) {
            $v->addError('new_password_confirm', 'Le password non coincidono.');
        }
        $v->throwIfErrors();
        self::checkPassword((int) $user['id'], (string) ($in['current_password'] ?? ''));
        Database::run('UPDATE listenin_users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id',
            ['h' => Security::hashPassword($new), 'id' => $user['id']]);
        // La sessione corrente resta valida, le altre decadono
        Session::login((int) $user['id']);
        Session::set('auth_at', time() + 1);
        Mailer::send((string) $user['email'], 'Password modificata', "La password del tuo account è stata modificata.\nSe non sei stato tu, usa subito il recupero password.");
        return ['message' => 'Password aggiornata.', 'csrf' => Security::csrfToken()];
    }

    public static function acceptDisclaimer(int $userId): void
    {
        Database::run('UPDATE listenin_users SET disclaimer_version = :v, disclaimer_accepted_at = NOW() WHERE id = :id',
            ['v' => (string) Config::get('legal.disclaimer_version'), 'id' => $userId]);
        Auth::forget();
    }

    // ------------------------------------------------------------------
    //  Storico personale
    // ------------------------------------------------------------------

    public static function contributions(int $userId, int $page): array
    {
        $per = 20;
        $total = (int) Database::value('SELECT COUNT(*) FROM listenin_revisions WHERE author_id = :u', ['u' => $userId]);
        $rows = Database::all(
            'SELECT r.id, r.frequency_id, r.action, r.status, r.origin, r.review_note, r.created_at, r.reviewed_at,
                    f.freq_hz, f.label,
                    (SELECT c.new_value FROM listenin_revision_changes c WHERE c.revision_id = r.id AND c.field = \'freq_hz\') AS proposed_hz,
                    (SELECT COUNT(*) FROM listenin_revision_changes c WHERE c.revision_id = r.id) AS n_changes
               FROM listenin_revisions r LEFT JOIN listenin_frequencies f ON f.id = r.frequency_id
              WHERE r.author_id = :u ORDER BY r.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            ['u' => $userId]
        );
        return ['items' => array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'frequency_id' => $r['frequency_id'] !== null ? (int) $r['frequency_id'] : null,
            'freq_mhz' => Normalizer::formatMHz($r['freq_hz'] !== null ? (int) $r['freq_hz'] : ($r['proposed_hz'] !== null ? (int) $r['proposed_hz'] : null)),
            'label' => $r['label'],
            'action' => $r['action'],
            'status' => $r['status'],
            'origin' => $r['origin'],
            'changes' => (int) $r['n_changes'],
            'review_note' => $r['review_note'],
            'created_at' => $r['created_at'],
            'reviewed_at' => $r['reviewed_at'],
        ], $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))];
    }

    public static function reports(int $userId, int $page): array
    {
        $per = 20;
        $total = (int) Database::value('SELECT COUNT(*) FROM listenin_reports WHERE reporter_id = :u', ['u' => $userId]);
        $rows = Database::all(
            'SELECT r.*, f.freq_hz, f.label AS freq_label, f.deleted_at AS freq_deleted, NULL AS reporter_nickname, NULL AS handler_nickname
               FROM listenin_reports r JOIN listenin_frequencies f ON f.id = r.frequency_id
              WHERE r.reporter_id = :u ORDER BY r.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            ['u' => $userId]
        );
        return ['items' => array_map([ReportService::class, 'present'], $rows), 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / $per))];
    }

    // ------------------------------------------------------------------
    //  Eliminazione dell'account (GDPR art. 17)
    // ------------------------------------------------------------------

    /**
     * Cancella definitivamente i dati personali. I contributi già approvati restano
     * nel dataset pubblico in forma anonima (author_id → NULL tramite le FK).
     */
    public static function deleteAccount(array $user, array $in): array
    {
        RateLimiter::hit('account_change', 'u' . $user['id']);
        self::checkPassword((int) $user['id'], (string) ($in['password'] ?? ''));
        if (($in['confirm'] ?? '') !== 'ELIMINA') {
            throw ApiException::validation(['confirm' => 'Scrivi ELIMINA per confermare.']);
        }
        if ($user['role'] === 'admin'
            && (int) Database::value("SELECT COUNT(*) FROM listenin_users WHERE role = 'admin' AND status = 'active'") <= 1) {
            throw new ApiException(409, 'last_admin', 'Sei l\'unico amministratore: assegna il ruolo a un altro utente prima di eliminare l\'account.');
        }
        $email = (string) $user['email'];
        $uid = (int) $user['id'];

        Database::transaction(function () use ($uid) {
            // Proposte non pubblicate: dati personali non entrati nel dataset → eliminate
            Database::run("DELETE FROM listenin_revisions WHERE author_id = :u AND status IN ('pending','rejected','superseded')", ['u' => $uid]);
            // Contributi approvati: restano, ma senza commenti potenzialmente personali
            Database::run("UPDATE listenin_revisions SET author_comment = NULL WHERE author_id = :u", ['u' => $uid]);
            // Segnalazioni aperte eliminate; chiuse anonimizzate
            Database::run("DELETE FROM listenin_reports WHERE reporter_id = :u AND status IN ('aperta','in_revisione')", ['u' => $uid]);
            Database::run('UPDATE listenin_reports SET message = NULL, reporter_ip_hash = NULL WHERE reporter_id = :u', ['u' => $uid]);
            // La cancellazione della riga utente porta a NULL author_id, reporter_id, created_by, reviewer_id, actor_id
            // e rimuove in cascata i token.
            Database::run('DELETE FROM listenin_users WHERE id = :u', ['u' => $uid]);
        });

        Session::logout();
        Auth::forget();
        Mailer::send($email, 'Account eliminato',
            "Il tuo account è stato eliminato insieme ai tuoi dati personali.\n"
            . "I contributi già approvati restano nel database pubblico in forma anonima.");
        return ['message' => 'Account eliminato. I tuoi dati personali sono stati cancellati.', 'csrf' => Security::csrfToken()];
    }

    // ------------------------------------------------------------------
    //  Token e utilità
    // ------------------------------------------------------------------

    private static function issueToken(int $userId, string $purpose, int $ttl, ?string $payload = null): string
    {
        $token = Security::randomToken();
        Database::run(
            'INSERT INTO listenin_user_tokens (user_id, purpose, token_hash, payload, expires_at) VALUES (:u, :p, :h, :pl, :e)',
            ['u' => $userId, 'p' => $purpose, 'h' => hash('sha256', $token), 'pl' => $payload, 'e' => date('Y-m-d H:i:s', time() + $ttl)]
        );
        return $token;
    }

    /** Valida e consuma (monouso) un token. */
    private static function consumeToken(string $token, string $purpose): array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{40,64}$/', $token)) {
            throw new ApiException(400, 'token_invalid', 'Link non valido.');
        }
        return Database::transaction(function () use ($token, $purpose) {
            $t = Database::one(
                'SELECT id, user_id, payload, expires_at, used_at FROM listenin_user_tokens WHERE token_hash = :h AND purpose = :p FOR UPDATE',
                ['h' => hash('sha256', $token), 'p' => $purpose]
            );
            if (!$t || $t['used_at'] !== null) {
                throw new ApiException(400, 'token_invalid', 'Link non valido o già utilizzato.');
            }
            if (strtotime((string) $t['expires_at']) < time()) {
                throw new ApiException(410, 'token_expired', 'Link scaduto: richiedine uno nuovo.');
            }
            Database::run('UPDATE listenin_user_tokens SET used_at = NOW() WHERE id = :id', ['id' => $t['id']]);
            return $t;
        });
    }

    private static function checkPassword(int $userId, string $password): void
    {
        $hash = (string) Database::value('SELECT password_hash FROM listenin_users WHERE id = :id', ['id' => $userId]);
        if ($password === '' || !password_verify($password, $hash)) {
            throw ApiException::validation(['password' => 'Password non corretta.', 'current_password' => 'Password non corretta.']);
        }
    }

    public static function purgeExpiredTokens(): int
    {
        return Database::run('DELETE FROM listenin_user_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL')->rowCount()
            + Database::run("DELETE FROM listenin_users WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 7 DAY)")->rowCount();
    }
}
