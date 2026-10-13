<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;
use RuntimeException;

final class Role extends Model
{
    protected string $table = 'roles';
    protected array $fillable = ['name', 'slug'];

    public function findBySlug(string $slug): ?array
    {
        return $this->firstWhere('slug', $slug);
    }

    public function details(int|string $id): ?array
    {
        $role = $this->find($id);
        if ($role === null) {
            return null;
        }

        $role['permissions'] = $this->permissions($id);
        $role['users_count'] = $this->usersCount($id);
        return $role;
    }

    public function permissions(int|string $id, ?int $limit = null, int $offset = 0): array
    {
        $sql =
            'SELECT permissions.* FROM permissions ' .
            'INNER JOIN role_permissions ON role_permissions.permission_id = permissions.id ' .
            'WHERE role_permissions.role_id = :role_id ORDER BY permissions.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $statement = Database::connection()->prepare($sql);
        $statement->bindValue('role_id', $id);
        if ($limit !== null) {
            $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
            $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        }
        $statement->execute();
        return $this->presentAll($statement->fetchAll());
    }

    public function permissionsCount(int|string $id): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id'
        );
        $statement->execute(['role_id' => $id]);
        return (int)$statement->fetchColumn();
    }

    public function permissionSlugs(int|string $id): array
    {
        return array_column($this->permissions($id), 'slug');
    }

    public function usersCount(int|string $id): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE role_id = :role_id');
        $statement->execute(['role_id' => $id]);
        return (int)$statement->fetchColumn();
    }

    public function syncPermissions(int|string $roleId, array $permissionIds): array
    {
        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $database = Database::connection();
        $ownsTransaction = !$database->inTransaction();
        if ($ownsTransaction) {
            $database->beginTransaction();
        }

        try {
            if ($permissionIds !== []) {
                $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
                $statement = $database->prepare("SELECT COUNT(*) FROM permissions WHERE id IN ({$placeholders})");
                $statement->execute($permissionIds);
                if ((int)$statement->fetchColumn() !== count($permissionIds)) {
                    throw new RuntimeException('One or more permissions do not exist.');
                }
            }

            $database->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')
                ->execute(['role_id' => $roleId]);
            $insert = $database->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)'
            );
            foreach ($permissionIds as $permissionId) {
                $insert->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
            if ($ownsTransaction) {
                $database->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }

        return $this->permissions($roleId);
    }

    public function attachPermission(int|string $roleId, int|string $permissionId): void
    {
        Database::connection()->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)'
        )->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    public function detachPermission(int|string $roleId, int|string $permissionId): void
    {
        Database::connection()->prepare(
            'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id'
        )->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
    }
}
