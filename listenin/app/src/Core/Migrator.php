<?php
declare(strict_types=1);

namespace ListenIn\Core;

/**
 * Aggiornamento del database tra una versione e l'altra, senza terminale.
 *
 * Ogni file in database/migrations/ restituisce una funzione che riceve la connessione PDO. Le migrazioni sono
 * idempotenti (controllano se tabelle e colonne esistono già) e vengono registrate in listenin_migrations.
 * Dopo aver caricato i nuovi file via FTP, la prima richiesta all'applicazione applica le migrazioni mancanti
 * (con un blocco per evitare esecuzioni concorrenti). Prima di aggiornare conviene sempre un backup del database.
 */
final class Migrator
{
    public static function dir(): string
    {
        return LISTENIN_ROOT . '/database/migrations';
    }

    /** @return string[] nomi delle migrazioni disponibili, in ordine */
    public static function available(): array
    {
        $names = array_map(static fn($f) => basename($f, '.php'), glob(self::dir() . '/*.php') ?: []);
        sort($names, SORT_STRING);
        return $names;
    }

    public static function latest(): string
    {
        $a = self::available();
        return $a ? end($a) : '';
    }

    private static function ensureTable(): void
    {
        $existed = self::tableExists('listenin_migrations');
        Database::pdo()->exec('CREATE TABLE IF NOT EXISTS listenin_migrations (
            name VARCHAR(80) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!$existed && self::tableExists('listenin_users')) {
            // installazione precedente al sistema di migrazioni: la struttura di base c'è già
            Database::run('INSERT IGNORE INTO listenin_migrations (name) VALUES (:n)', ['n' => '0003_baseline']);
        }
    }

    /** @return string[] */
    public static function applied(): array
    {
        self::ensureTable();
        return array_column(Database::all('SELECT name FROM listenin_migrations ORDER BY name'), 'name');
    }

    /** @return string[] */
    public static function pending(): array
    {
        return array_values(array_diff(self::available(), self::applied()));
    }

    /** Applica le migrazioni mancanti. @return string[] nomi applicati */
    public static function run(): array
    {
        $done = [];
        foreach (self::pending() as $name) {
            $fn = require self::dir() . '/' . $name . '.php';
            if (!is_callable($fn)) {
                throw new \RuntimeException("Migrazione $name non valida.");
            }
            try {
                $fn(Database::pdo());
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migrazione $name non riuscita: " . $e->getMessage(), 0, $e);
            }
            Database::run('INSERT IGNORE INTO listenin_migrations (name) VALUES (:n)', ['n' => $name]);
            $done[] = $name;
        }
        return $done;
    }

    /** Chiamata a ogni richiesta: costa solo la lettura di un piccolo file quando il database è aggiornato. */
    public static function ensureUpToDate(): void
    {
        $dir = LISTENIN_ROOT . '/storage/cache';
        $stamp = $dir . '/schema.stamp';
        $latest = self::latest();
        if ($latest === '' || (is_file($stamp) && trim((string) file_get_contents($stamp)) === $latest)) {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen($dir . '/migrate.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $latest) {
                return;   // un'altra richiesta ha già aggiornato
            }
            self::run();
            @file_put_contents($stamp, $latest);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    // ------------------------------------------------------------------ utilità per le migrazioni
    public static function tableExists(string $table): bool
    {
        return (bool) Database::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t', ['t' => $table]);
    }

    public static function columnExists(string $table, string $column): bool
    {
        return (bool) Database::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => $table, 'c' => $column]);
    }

    public static function indexExists(string $table, string $index): bool
    {
        return (bool) Database::value('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i LIMIT 1',
            ['t' => $table, 'i' => $index]);
    }

    public static function addColumn(string $table, string $column, string $definition): void
    {
        if (!self::columnExists($table, $column)) {
            Database::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    public static function addIndex(string $table, string $index, string $definition): void
    {
        if (!self::indexExists($table, $index)) {
            Database::pdo()->exec("ALTER TABLE `$table` ADD $definition");
        }
    }
}
