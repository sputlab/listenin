<?php
declare(strict_types=1);

namespace ListenIn\Core;

final class Security
{
    /** Header HTTP di sicurezza comuni a tutte le risposte. */
    /** Server delle tessere della mappa (immagini) consentiti dalla CSP, ricavati dall'indirizzo configurato. */
    private static function tileHosts(): string
    {
        $url = (string) Config::get('map.tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
        $host = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}'], ['a', '0', '0', '0'], $url), PHP_URL_HOST);
        if (!$host || !preg_match('/^[a-z0-9.-]+$/i', $host) || !str_starts_with($url, 'https://')) {
            return '';
        }
        return ' https://' . (str_contains($url, '{s}') ? '*.' . preg_replace('/^a\./', '', $host) : $host);
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
            . "img-src 'self' data:" . self::tileHosts() . "; font-src 'self'; connect-src 'self'; object-src 'none'; worker-src 'self'; manifest-src 'self'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
            . (self::isHttps() ? '; upgrade-insecure-requests' : ''));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        if (Config::get('security.hsts') && self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /** Escaping contestuale per output HTML. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // ---- CSRF: token per sessione, verificato su ogni richiesta mutativa ----

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotateCsrf(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(Request $request): void
    {
        $sent = (string) ($request->header('X-CSRF-Token') ?? '');
        $known = (string) ($_SESSION['csrf'] ?? '');
        if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
            throw new ApiException(419, 'csrf_invalid', 'Sessione scaduta o token di sicurezza non valido. Ricarica la pagina.');
        }
        // Difesa aggiuntiva: l'origine deve coincidere con l'host dell'applicazione
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            $expected = parse_url((string) Config::get('app.base_url'), PHP_URL_HOST);
            $host = parse_url($origin, PHP_URL_HOST);
            if ($expected && $host && !hash_equals(strtolower($expected), strtolower($host))) {
                throw new ApiException(403, 'origin_mismatch', 'Origine della richiesta non consentita.');
            }
        }
    }

    // ---- Identità client e hashing ----

    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('security.trusted_proxies', []);
        if ($trusted && in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($chain as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trusted, true)) {
                    return $ip;
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /** Cifra un segreto (AES-256-GCM, chiave derivata da app.secret): per i dati che il server deve poter rileggere. */
    public static function seal(string $plain): string
    {
        $key = hash('sha256', 'seal|' . (string) Config::get('app.secret'), true);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function unseal(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $key = hash('sha256', 'seal|' . (string) Config::get('app.secret'), true);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) Config::get('app.secret'));
    }

    public static function ipHash(): string
    {
        return self::hmac('ip|' . self::clientIp());
    }

    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashPassword(string $password): string
    {
        $algo = Config::get('security.password_algo') === 'argon2id' && defined('PASSWORD_ARGON2ID')
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
        $options = $algo === PASSWORD_BCRYPT ? ['cost' => 12] : [];
        return password_hash($password, $algo, $options);
    }

    public static function passwordNeedsRehash(string $hash): bool
    {
        $algo = Config::get('security.password_algo') === 'argon2id' && defined('PASSWORD_ARGON2ID')
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo, $algo === PASSWORD_BCRYPT ? ['cost' => 12] : []);
    }
}
