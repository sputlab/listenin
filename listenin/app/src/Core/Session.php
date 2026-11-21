<?php
declare(strict_types=1);

namespace ListenIn\Core;

/** Sessione PHP con cookie HttpOnly, Secure, SameSite=Strict e scadenze. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) { // opzioni deprecate da PHP 8.4, dove i default sono già adeguati
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        session_name((string) Config::get('session.name', 'listenin_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => (bool) Config::get('session.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        $now = time();
        $idle = (int) Config::get('session.idle_timeout', 7200);
        $absolute = (int) Config::get('session.absolute_ttl', 43200);
        $_SESSION['created'] ??= $now;
        $last = (int) ($_SESSION['last_seen'] ?? $now);
        if ($now - $last > $idle || $now - (int) $_SESSION['created'] > $absolute) {
            self::reset();
        }
        $_SESSION['last_seen'] = $now;
    }

    /** Cookie limitato al percorso dell'applicazione (es. /webapp/listenin/): nessuna collisione con altre webapp del dominio. */
    public static function cookiePath(): string
    {
        $path = (string) (parse_url((string) Config::get('app.base_url', ''), PHP_URL_PATH) ?? '');
        return rtrim($path, '/') . '/';
    }

    /** Svuota la sessione e assegna un nuovo identificativo. */
    public static function reset(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['created'] = time();
        $_SESSION['last_seen'] = time();
        Security::rotateCsrf();
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['created'] = time();
        $_SESSION['auth_at'] = time();
        Security::rotateCsrf();
    }

    public static function logout(): void
    {
        self::reset();
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }
}
