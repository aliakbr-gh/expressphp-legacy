<?php

declare(strict_types=1);

namespace ExpressPHP\Database\Migrations;

use ExpressPHP\Database\Database;
use PDO;
use PDOException;
use RuntimeException;

final class Migrator
{
    private const LOCK_NAME = 'expressphp_migrations';
    private const LOCK_TIMEOUT_SECONDS = 10;

    private PDO $database;

    public function __construct(
        private readonly string $path,
        ?string                 $connection = null,
    ) {
        $this->database = Database::connection($connection);
    }

    /**
     * Runs pending migrations. MySQL DDL commits implicitly, so each up() should be safe to re-run
     * (for example CREATE TABLE IF NOT EXISTS) in case a later statement in it fails.
     */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $this->createRepository();
            $ran = $this->ran();
            $batch = $this->nextBatch();
            $completed = [];

            foreach ($this->files() as $name => $file) {
                if (isset($ran[$name])) {
                    continue;
                }
                $this->load($name, $file)->up($this->database);
                $statement = $this->database->prepare(
                    'INSERT INTO migrations (migration, batch, migrated_at) VALUES (:migration, :batch, CURRENT_TIMESTAMP)'
                );
                $statement->execute(['migration' => $name, 'batch' => $batch]);
                $completed[] = $name;
            }
            return $completed;
        });
    }

    public function rollback(): array
    {
        return $this->locked(function (): array {
            $this->createRepository();
            $rolledBack = [];

            foreach ($this->rollbackPlan() as $name) {
                $file = $this->files()[$name] ?? null;
                if ($file === null) {
                    throw new RuntimeException("Migration file [{$name}] is missing.");
                }
                $this->load($name, $file)->down($this->database);
                $delete = $this->database->prepare('DELETE FROM migrations WHERE migration = :migration');
                $delete->execute(['migration' => $name]);
                $rolledBack[] = $name;
            }
            return $rolledBack;
        });
    }

    /**
     * Lists the migrations the next rollback would reverse, newest first, without changing anything.
     *
     * @return list<string>
     */
    public function rollbackPlan(): array
    {
        if (!$this->repositoryExists()) {
            return [];
        }

        $batch = (int)$this->database->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
        if ($batch === 0) {
            return [];
        }
        $statement = $this->database->prepare('SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC');
        $statement->execute(['batch' => $batch]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function status(): array
    {
        $ran = $this->ran();
        $status = [];
        foreach ($this->files() as $name => $_file) {
            $status[] = ['name' => $name, 'status' => isset($ran[$name]) ? 'Ran' : 'Pending'];
        }
        return $status;
    }

    /**
     * Prevents two deploy hooks from applying the same migration at the same time.
     */
    private function locked(callable $callback): array
    {
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $callback();
        }

        $statement = $this->database->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration process is running. Try again when it finishes.');
        }

        try {
            return $callback();
        } finally {
            $this->database->prepare('SELECT RELEASE_LOCK(?)')->execute([self::LOCK_NAME]);
        }
    }

    private function load(string $name, string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration [{$name}] must return a Migration instance.");
        }
        return $migration;
    }

    private function createRepository(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS migrations (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'migration VARCHAR(255) NOT NULL UNIQUE, ' .
            'batch INT UNSIGNED NOT NULL, ' .
            'migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function repositoryExists(): bool
    {
        try {
            $this->database->query('SELECT 1 FROM migrations LIMIT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function files(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $mapped = [];
        foreach ($files as $file) {
            $mapped[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }
        return $mapped;
    }

    private function ran(): array
    {
        if (!$this->repositoryExists()) {
            return [];
        }
        $names = $this->database->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($names, true);
    }

    private function nextBatch(): int
    {
        return (int)$this->database->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
    }
}
