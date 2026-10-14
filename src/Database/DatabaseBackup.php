<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DatabaseBackup
{
    private const ROWS_PER_INSERT = 100;

    /**
     * Configures a backup of one MySQL database into a private directory. $timezone is used in the file name.
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly string $database,
        private readonly string $path,
        private readonly string $timezone,
    ) {
    }

    /**
     * Writes a ZIP containing an SQL dump and returns its path. The plaintext SQL file is always
     * removed before returning; the caller owns the ZIP and must delete it when finished.
     *
     * @return array{path: string, filename: string}
     */
    public function createZIP(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZIP PHP extension is required for database backups.');
        }
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Database backups support MySQL connections only.');
        }
        // START TRANSACTION would silently commit the caller's open transaction.
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Database backups need a connection without an open transaction.');
        }

        $this->ensureDirectory();
        $stamp = (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d-h-i-A');
        $safeDatabase = preg_replace('/[^A-Za-z0-9_-]/', '', $this->database) ?: 'database';
        $basename = $safeDatabase . '-' . $stamp;
        $token = bin2hex(random_bytes(16));
        $sqlPath = $this->path . '/' . $token . '.sql';
        $zipPath = $this->path . '/' . $token . '.zip';

        try {
            $this->writeSQL($sqlPath, $basename . '.sql', $stamp);
            $archive = new ZipArchive();
            if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the backup archive.');
            }
            if ($archive->addFile($sqlPath, $basename . '.sql') !== true) {
                $archive->close();
                throw new RuntimeException('Unable to add the backup file to the archive.');
            }
            // ZipArchive reads the source file during close(), so a failure here means an incomplete archive.
            if ($archive->close() !== true) {
                throw new RuntimeException('Unable to finalize the backup archive.');
            }
            @chmod($zipPath, 0600);
        } catch (Throwable $exception) {
            $this->delete($zipPath);
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('Unable to create the database backup.', 0, $exception);
        } finally {
            $this->delete($sqlPath);
        }

        return [
            'path' => $zipPath,
            'filename' => $basename . '.zip',
        ];
    }

    /**
     * Writes every base table's structure and rows to an SQL file from one consistent, read-only snapshot.
     */
    private function writeSQL(string $sqlPath, string $sqlName, string $stamp): void
    {
        $handle = @fopen($sqlPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the backup file.');
        }
        @chmod($sqlPath, 0600);

        // A dump legitimately runs long, so any per-statement limit on this session is removed.
        Database::setStatementTimeout($this->connection, 0);
        // One snapshot for every table, so related rows are captured at the same moment.
        $this->connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->connection->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            $this->write(
                $handle,
                "-- ExpressPHP database backup\n" .
                '-- File: ' . $sqlName . "\n" .
                '-- Created: ' . $stamp . "\n\n" .
                "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone = '+00:00';\n\n",
            );

            foreach ($this->tables() as $table) {
                $quoted = $this->quoteName($table);
                $create = $this->connection->query('SHOW CREATE TABLE ' . $quoted)->fetch();
                $statement = is_array($create) ? (string)($create['Create Table'] ?? $create[1] ?? '') : '';
                if ($statement === '') {
                    throw new RuntimeException('Unable to read the table structure.');
                }

                $this->write($handle, 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $statement . ";\n\n");
                $this->writeRows($handle, $quoted, $this->storedColumns($table));
                $this->write($handle, "\n");
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to write the backup file.');
            }
        } finally {
            $this->connection->exec('ROLLBACK');
            fclose($handle);
        }
    }

    /**
     * Streams every row with an unbuffered query, so memory stays flat and no OFFSET paging is needed.
     *
     * @param list<string> $columns
     */
    private function writeRows($handle, string $quoted, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $columnList = implode(', ', array_map($this->quoteName(...), $columns));
        $prefix = 'INSERT INTO ' . $quoted . ' (' . $columnList . ') VALUES ';
        $attribute = $this->bufferedQueryAttribute();
        $this->connection->setAttribute($attribute, false);
        $statement = null;

        try {
            $statement = $this->connection->query('SELECT ' . $columnList . ' FROM ' . $quoted);
            $batch = [];
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $batch[] = '(' . implode(', ', array_map($this->SQLValue(...), $row)) . ')';
                if (count($batch) === self::ROWS_PER_INSERT) {
                    $this->write($handle, $prefix . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->write($handle, $prefix . implode(",\n", $batch) . ";\n");
            }
        } finally {
            // An open unbuffered cursor blocks every later query on this connection, including ROLLBACK.
            $statement?->closeCursor();
            $this->connection->setAttribute($attribute, true);
        }
    }

    /**
     * Generated columns are computed by MySQL and cannot be inserted, so they are left out of the dump.
     *
     * @return list<string>
     */
    private function storedColumns(string $table): array
    {
        $statement = $this->connection->prepare(
            'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS ' .
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$table]);

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $extra]) {
            $extra = strtoupper((string)$extra);
            if (!str_contains($extra, 'GENERATED') && !str_contains($extra, 'VIRTUAL') && !str_contains($extra, 'PERSISTENT')) {
                $columns[] = (string)$name;
            }
        }
        return $columns;
    }

    /**
     * Returns the PDO attribute that turns MySQL result buffering on or off.
     */
    private function bufferedQueryAttribute(): int
    {
        // PHP 8.4 adds Pdo\Mysql constants; the PDO::MYSQL_* aliases are deprecated from PHP 8.5.
        return class_exists('Pdo\Mysql')
            ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    /**
     * Converts one column value to an SQL literal for the dump.
     */
    private function SQLValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $quoted = $this->connection->quote((string)$value);
        if ($quoted === false) {
            throw new RuntimeException('Unable to encode a backup value.');
        }

        return $quoted;
    }

    /**
     * Returns the names of the base tables in the database, excluding views.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $statement = $this->connection->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = $row[0] ?? null;
            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * Quotes a table or column name after checking it against a strict identifier pattern.
     */
    private function quoteName(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return '`' . $name . '`';
    }

    /**
     * Writes a string to the dump file, retrying short writes and failing on errors.
     */
    private function write($handle, string $sql): void
    {
        $length = strlen($sql);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($sql, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Unable to write the backup file.');
            }
            $written += $result;
        }
    }

    /**
     * Creates the private backup directory when it is missing.
     */
    private function ensureDirectory(): void
    {
        if (!is_dir($this->path) && !@mkdir($this->path, 0750, true) && !is_dir($this->path)) {
            throw new RuntimeException('Unable to create the backup storage directory.');
        }
    }

    /**
     * Deletes a temporary backup file if it exists.
     */
    private function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
