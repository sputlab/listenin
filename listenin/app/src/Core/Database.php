<?php
declare(strict_types=1);

namespace ListenIn\Core;

use PDO;
use PDOStatement;

/**
 * Accesso al database esclusivamente tramite PDO e prepared statement
 * con parametri vincolati (emulazione disattivata).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                (string) Config::get('db.dsn'),
                (string) Config::get('db.user'),
                (string) Config::get('db.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
            // Le date applicative sono scritte in ora locale da PHP: allineiamo la sessione SQL.
            // L'offset proviene da DateTime (formato fisso +HH:MM), non da input utente.
            $offset = (new \DateTime())->format('P');
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . $offset . "'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    private static int $depth = 0;

    /** Apre una transazione o, se già aperta, un SAVEPOINT annidato. */
    public static function begin(): void
    {
        self::$depth === 0 ? self::pdo()->beginTransaction() : self::pdo()->exec('SAVEPOINT sp' . self::$depth);
        self::$depth++;
    }

    public static function commit(): void
    {
        self::$depth--;
        self::$depth === 0 ? self::pdo()->commit() : self::pdo()->exec('RELEASE SAVEPOINT sp' . self::$depth);
    }

    public static function rollback(): void
    {
        self::$depth = max(0, self::$depth - 1);
        if (self::$depth === 0) {
            if (self::pdo()->inTransaction()) {
                self::pdo()->rollBack();
            }
        } else {
            self::pdo()->exec('ROLLBACK TO SAVEPOINT sp' . self::$depth);
        }
    }

    /** Esegue $fn in transazione; le chiamate annidate usano SAVEPOINT. */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
