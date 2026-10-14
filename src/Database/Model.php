<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use PDO;
use PDOStatement;
use RuntimeException;

abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected ?string $connection = null;
    protected array $fillable = [];
    // Columns removed from every row this model returns, such as password hashes.
    protected array $hidden = [];
    // UTC TIMESTAMP/DATETIME columns returned as ISO 8601, for example 2026-10-13T08:15:00Z.
    protected array $dates = ['created_at', 'updated_at'];

    /**
     * Returns every row, or the first $limit rows, with hidden columns removed and dates formatted.
     */
    public function all(?int $limit = null): array
    {
        $sql = 'SELECT * FROM ' . $this->identifier($this->table);
        if ($limit === null) {
            return $this->presentAll($this->pdo()->query($sql)->fetchAll());
        }

        return $this->presentAll($this->run($sql . ' LIMIT :limit', ['limit' => max(1, $limit)])->fetchAll());
    }

    /**
     * Returns one page of rows, newest primary key first.
     */
    public function paginate(int $limit, int $offset = 0): array
    {
        $sql = sprintf(
            'SELECT * FROM %s ORDER BY %s DESC LIMIT :limit OFFSET :offset',
            $this->identifier($this->table),
            $this->identifier($this->primaryKey),
        );
        return $this->presentAll($this->run($sql, ['limit' => max(1, $limit), 'offset' => max(0, $offset)])->fetchAll());
    }

    /**
     * Counts all rows in the table.
     */
    public function count(): int
    {
        return (int)$this->pdo()
            ->query('SELECT COUNT(*) FROM ' . $this->identifier($this->table))
            ->fetchColumn();
    }

    /**
     * Finds one row by primary key.
     */
    public function find(int|string $id): ?array
    {
        return $this->firstWhere($this->primaryKey, $id);
    }

    /**
     * Returns the rows whose column equals the value, optionally limited.
     */
    public function where(string $column, mixed $value, ?int $limit = null): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value',
            $this->identifier($this->table),
            $this->identifier($column),
        );
        $bindings = ['value' => $value];
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            $bindings['limit'] = max(1, $limit);
        }
        return $this->presentAll($this->run($sql, $bindings)->fetchAll());
    }

    /**
     * Returns the first row whose column equals the value, or null.
     */
    public function firstWhere(string $column, mixed $value): ?array
    {
        $row = $this->rawFirstWhere($column, $value);
        return $row === null ? null : $this->present($row);
    }

    /**
     * Inserts the fillable attributes and returns the stored row.
     */
    public function create(#[\SensitiveParameter] array $attributes): array
    {
        $attributes = $this->fillable($attributes);
        if ($attributes === []) {
            throw new RuntimeException('No fillable attributes were provided.');
        }

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($this->table),
            implode(', ', array_map($this->identifier(...), $columns)),
            implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)),
        );
        $this->run($sql, $attributes);
        return $this->find((string)$this->pdo()->lastInsertId()) ?? $this->present($attributes);
    }

    /**
     * Updates the fillable attributes of one row by primary key. Returns false when no fillable attribute was given.
     */
    public function update(int|string $id, #[\SensitiveParameter] array $attributes): bool
    {
        $attributes = $this->fillable($attributes);
        if ($attributes === []) {
            return false;
        }
        $sets = array_map(
            fn(string $column): string => $this->identifier($column) . ' = :' . $column,
            array_keys($attributes),
        );
        $attributes['_primary_key'] = $id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :_primary_key',
            $this->identifier($this->table),
            implode(', ', $sets),
            $this->identifier($this->primaryKey),
        );
        $this->run($sql, $attributes);
        return true;
    }

    /**
     * Deletes one row by primary key.
     */
    public function delete(int|string $id): bool
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :id',
            $this->identifier($this->table),
            $this->identifier($this->primaryKey),
        );
        $this->run($sql, ['id' => $id]);
        return true;
    }

    /**
     * Returns the connection this model uses.
     */
    protected function pdo(): PDO
    {
        return Database::connection($this->connection);
    }

    /**
     * Reads one row including hidden columns, for internal checks such as password verification.
     */
    protected function rawFirstWhere(string $column, mixed $value): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value LIMIT 1',
            $this->identifier($this->table),
            $this->identifier($column),
        );
        $row = $this->run($sql, ['value' => $value])->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Prepares and executes a statement, binding each value with a PDO type matching its PHP type.
     * Binding everything as a string would turn false into '', which strict MySQL rejects for integers.
     */
    protected function run(string $sql, #[\SensitiveParameter] array $bindings = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($bindings as $name => $value) {
            [$value, $type] = match (true) {
                $value === null => [null, PDO::PARAM_NULL],
                is_bool($value) => [(int)$value, PDO::PARAM_INT],
                is_int($value) => [$value, PDO::PARAM_INT],
                default => [(string)$value, PDO::PARAM_STR],
            };
            $statement->bindValue(is_int($name) ? $name + 1 : $name, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    /**
     * Removes hidden columns from a row and formats its date columns as ISO 8601 UTC.
     */
    protected function present(array $row): array
    {
        foreach ($this->hidden as $column) {
            unset($row[$column]);
        }
        foreach ($this->dates as $column) {
            $value = $row[$column] ?? null;
            if (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) === 1) {
                $row[$column] = str_replace(' ', 'T', $value) . 'Z';
            }
        }
        return $row;
    }

    /**
     * Applies present() to every row.
     */
    protected function presentAll(array $rows): array
    {
        return array_map($this->present(...), $rows);
    }

    /**
     * Quotes a table or column name after checking it against a strict identifier pattern.
     */
    protected function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException("Unsafe SQL identifier [{$identifier}].");
        }
        return '`' . $identifier . '`';
    }

    /**
     * Keeps only the attributes listed in $fillable.
     */
    private function fillable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip($this->fillable));
    }
}
