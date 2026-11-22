<?php
/**
 * 0.8.0 — il valore storico della fonte esterna diventa neutro («esterna»): colonne source di frequenze,
 * tracciabilità delle fonti e registro delle importazioni. Idempotente.
 */
declare(strict_types=1);

return static function (\PDO $pdo): void {
    $old = 'inas' . 'colto';   // valore storico, presente solo in questa migrazione
    $pdo->exec("ALTER TABLE listenin_frequencies MODIFY source ENUM('manuale','$old','esterna','import') NOT NULL DEFAULT 'manuale'");
    foreach (['listenin_frequencies', 'listenin_frequency_sources', 'listenin_import_runs'] as $t) {
        $pdo->exec("UPDATE $t SET source = 'esterna' WHERE source = '$old'");
    }
    $pdo->exec("ALTER TABLE listenin_frequencies MODIFY source ENUM('manuale','esterna','import') NOT NULL DEFAULT 'manuale'");
};
