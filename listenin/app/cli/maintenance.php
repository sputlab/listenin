<?php
/**
 * Manutenzione periodica (cron consigliato ogni ora):
 *   php cli/maintenance.php
 * - elimina token scaduti o usati e registrazioni mai verificate (> 7 giorni)
 * - svuota i contatori di rate limiting scaduti
 * - azzera gli hash IP delle segnalazioni oltre il periodo di conservazione
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use ListenIn\Core\RateLimiter;
use ListenIn\Domain\ReportService;
use ListenIn\Domain\UserService;

printf("token/utenti rimossi: %d\n", UserService::purgeExpiredTokens());
printf("rate limit scaduti:   %d\n", RateLimiter::purgeExpired());
printf("hash IP azzerati:     %d\n", ReportService::purgeIpHashes());
