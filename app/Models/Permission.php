<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;

final class Permission extends Model
{
    protected string $table = 'permissions';
    protected array $fillable = ['name', 'slug'];

    public function findBySlug(string $slug): ?array
    {
        return $this->firstWhere('slug', $slug);
    }

    public function roles(int|string $id, ?int $limit = null, int $offset = 0): array
    {
        $sql =
            'SELECT roles.* FROM roles ' .
            'INNER JOIN role_permissions ON role_permissions.role_id = roles.id ' .
            'WHERE role_permissions.permission_id = :permission_id ORDER BY roles.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $statement = Database::connection()->prepare($sql);
        $statement->bindValue('permission_id', $id);
        if ($limit !== null) {
            $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
            $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        }
        $statement->execute();
        return $this->presentAll($statement->fetchAll());
    }

    public function rolesCount(int|string $id): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM role_permissions WHERE permission_id = :permission_id'
        );
        $statement->execute(['permission_id' => $id]);
        return (int)$statement->fetchColumn();
    }
}
