<?php
declare(strict_types=1);

namespace ListenIn\Core;

use ListenIn\Domain\ReportService;
use ListenIn\Domain\UserService;

/**
 * Pulizia periodica: token scaduti, registrazioni non verificate, contatori di rate limit, hash IP.
 * Senza cron (hosting condiviso) viene eseguita automaticamente, al massimo una volta all'ora,
 * dopo una richiesta qualsiasi; un amministratore può anche lanciarla a mano dal pannello.
 */
final class Maintenance
{
    private static function stampFile(): string
    {
        return LISTENIN_ROOT . '/storage/cache/maintenance.stamp';
    }

    public static function lastRun(): ?int
    {
        $f = self::stampFile();
        return is_file($f) ? (int) filemtime($f) : null;
    }

    /** Pianifica l'esecuzione a fine richiesta se è passata più di un'ora dall'ultima. */
    public static function maybeSchedule(): void
    {
        $last = self::lastRun();
        if ($last !== null && time() - $last < 3600) {
            return;
        }
        @touch(self::stampFile());   // evita esecuzioni concorrenti
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();   // il visitatore non aspetta la pulizia
            }
            try {
                self::run();
            } catch (\Throwable $e) {
                error_log('[listenin-maintenance] ' . $e->getMessage());
            }
        });
    }

    /** @return array{tokens:int,rate_limits:int,ip_hashes:int} */
    public static function run(): array
    {
        $r = [
            'tokens' => UserService::purgeExpiredTokens(),
            'rate_limits' => RateLimiter::purgeExpired(),
            'ip_hashes' => ReportService::purgeIpHashes(),
        ];
        try {
            \ListenIn\Domain\SnapshotService::maybeGenerate();
        } catch (\Throwable $e) {
            error_log('[listenin-opendata] ' . $e->getMessage());
        }
        try {
            \ListenIn\Domain\WebhookService::purge();
            \ListenIn\Domain\PointsService::awardMonthlyChampion();
        } catch (\Throwable $e) {
            error_log('[listenin-maintenance] ' . $e->getMessage());
        }
        @touch(self::stampFile());
        return $r;
    }
}
