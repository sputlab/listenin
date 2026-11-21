<?php
declare(strict_types=1);

namespace ListenIn\Core;

/** Rate limiting a finestra fissa su database (o APCu se disponibile). */
final class RateLimiter
{
    /** Limiti predefiniti [richieste, secondi] per le azioni non presenti nella configurazione. */
    private const DEFAULTS = ['checkin' => [60, 3600], 'dup_check' => [180, 60], 'feed' => [60, 60], 'opendata' => [30, 600], 'webhook_write' => [20, 3600]];

    /**
     * Registra un tentativo e lancia 429 al superamento della soglia.
     * @param string $action chiave in config rate_limits
     * @param string $subject identificativo (ip, email, user id)
     */
    public static function hit(string $action, string $subject): void
    {
        [$max, $window] = Config::get('rate_limits.' . $action, self::DEFAULTS[$action] ?? [60, 60]);
        $now = time();
        $windowStart = $now - ($now % $window);
        $bucket = Security::hmac('rl|' . $action . '|' . $subject);

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $key = 'listenin_rl_' . $bucket . '_' . $windowStart;
            apcu_add($key, 0, $window + 5);
            $hits = (int) apcu_inc($key);
        } else {
            Database::run(
                'INSERT INTO listenin_rate_limits (bucket, window_start, hits, expires_at)
                 VALUES (:b, :w, 1, :e)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                ['b' => $bucket, 'w' => $windowStart, 'e' => $windowStart + $window]
            );
            $hits = (int) Database::value(
                'SELECT hits FROM listenin_rate_limits WHERE bucket = :b AND window_start = :w',
                ['b' => $bucket, 'w' => $windowStart]
            );
        }

        if ($hits > $max) {
            $retry = max(1, $windowStart + $window - $now);
            throw new ApiException(429, 'rate_limited',
                'Troppe richieste. Riprova tra ' . self::humanSeconds($retry) . '.',
                ['retry_after' => $retry], ['Retry-After' => (string) $retry]);
        }
    }

    /** Azzera un contatore (es. login riuscito). */
    public static function clear(string $action, string $subject): void
    {
        $bucket = Security::hmac('rl|' . $action . '|' . $subject);
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            return; // la finestra APCu scade da sola
        }
        Database::run('DELETE FROM listenin_rate_limits WHERE bucket = :b', ['b' => $bucket]);
    }

    public static function purgeExpired(): int
    {
        return Database::run('DELETE FROM listenin_rate_limits WHERE expires_at < :t', ['t' => time()])->rowCount();
    }

    private static function humanSeconds(int $s): string
    {
        return $s < 90 ? $s . ' secondi' : (int) ceil($s / 60) . ' minuti';
    }
}
