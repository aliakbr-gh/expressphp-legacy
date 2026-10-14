<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static array $config = [];
    private static array $connections = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$connections = [];
    }

    public static function connection(?string $name = null): PDO
    {
        $name ??= (string)(self::$config['default'] ?? 'mysql');

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $connection = self::$config['connections'][$name] ?? null;
        if (!is_array($connection)) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        $driver = (string)($connection['driver'] ?? 'mysql');
        $dsn = match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $connection['host'] ?? '127.0.0.1',
                $connection['port'] ?? 3306,
                $connection['database'] ?? '',
                $connection['charset'] ?? 'utf8mb4',
            ),
            'sqlite' => 'sqlite:' . ($connection['database'] ?? ':memory:'),
            default => throw new RuntimeException("Unsupported database driver [{$driver}]."),
        };

        $options = $connection['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] ??= PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] ??= PDO::FETCH_ASSOC;
        $options[PDO::ATTR_EMULATE_PREPARES] ??= false;
        // A stalled database server must not hold every PHP worker indefinitely.
        $options[PDO::ATTR_TIMEOUT] ??= max(1, (int)($connection['timeout'] ?? 5));

        $pdo = new PDO(
            $dsn,
            (string)($connection['username'] ?? ''),
            (string)($connection['password'] ?? ''),
            $options,
        );

        if ($driver === 'mysql') {
            // TIMESTAMP columns are stored in UTC; reading them in UTC keeps them consistent with PHP.
            $pdo->prepare('SET time_zone = ?')->execute([(string)($connection['timezone'] ?? '+00:00')]);
            $timeout = (int)($connection['statement_timeout_ms'] ?? 0);
            if ($timeout > 0) {
                self::setStatementTimeout($pdo, $timeout);
            }
        }

        return self::$connections[$name] = $pdo;
    }

    /**
     * Limits how long one statement may run on this MySQL or MariaDB session; 0 removes the limit.
     * ATTR_TIMEOUT only covers connecting, so without this a slow query holds a PHP worker indefinitely.
     * MySQL applies the limit to read-only SELECT statements; MariaDB applies it to all statements.
     */
    public static function setStatementTimeout(PDO $pdo, int $milliseconds): void
    {
        // The value is an int clamped here, so it is written into the statement: MySQL rejects these
        // variables when they arrive as bound string parameters.
        $milliseconds = max(0, $milliseconds);
        if (str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
            $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.3F', $milliseconds / 1000));
            return;
        }
        $pdo->exec('SET SESSION max_execution_time = ' . $milliseconds);
    }

    /**
     * Runs the callback in a transaction and returns its result. A call made while a
     * transaction is already open joins it instead of committing early.
     */
    public static function transaction(callable $callback, ?string $name = null): mixed
    {
        $pdo = self::connection($name);
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function disconnect(?string $name = null): void
    {
        if ($name === null) {
            self::$connections = [];
            return;
        }
        unset(self::$connections[$name]);
    }
}
