<?php
/**
 * Aggiorna il database alla versione del codice (facoltativo: avviene già da solo alla prima richiesta web).
 *   php cli/migrate.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use ListenIn\Core\Migrator;

$done = Migrator::run();
@file_put_contents(LISTENIN_ROOT . '/storage/cache/schema.stamp', Migrator::latest());
echo $done ? 'Applicate: ' . implode(', ', $done) . PHP_EOL : 'Il database è già aggiornato.' . PHP_EOL;
