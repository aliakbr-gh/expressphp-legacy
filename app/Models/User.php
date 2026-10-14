<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Auth\Password;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = [
        'name',
        'username',
        'password',
        'is_active',
        'role_id',
    ];
    protected array $hidden = ['password'];

    public function allDetailed(): array
    {
        return array_map(
            $this->withPermissions(...),
            Database::connection()->query($this->detailsQuery() . ' ORDER BY users.id')->fetchAll(),
        );
    }

    public function paginateDetailed(int $limit, int $offset = 0): array
    {
        $statement = Database::connection()->prepare(
            $this->detailsQuery() . ' ORDER BY users.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $statement->execute();
        return array_map($this->withPermissions(...), $statement->fetchAll());
    }

    public function findDetailed(int|string $id): ?array
    {
        $statement = Database::connection()->prepare($this->detailsQuery() . ' WHERE users.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return $user === false ? null : $this->withPermissions($user);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->firstWhere('username', $username);
    }

    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->rawFirstWhere('username', $username);
        $hash = $user['password'] ?? null;

        // Exactly one bcrypt check runs on every path, and the password is checked before the active flag,
        // so response time reveals neither whether the username exists nor whether the account is active.
        $valid = is_string($hash) ? Password::verify($password, $hash) : Password::verifyMissing($password);
        if ($user === null || !$valid || !(bool)($user['is_active'] ?? false)) {
            return null;
        }

        if (Password::needsRehash($hash)) {
            $this->update($user['id'], ['password' => Password::hash($password)]);
        }

        return $this->findDetailed($user['id']);
    }

    public function permissions(int|string $userId, ?int $limit = null, int $offset = 0): array
    {
        $sql =
            'SELECT permissions.* FROM permissions ' .
            'INNER JOIN role_permissions ON role_permissions.permission_id = permissions.id ' .
            'INNER JOIN users ON users.role_id = role_permissions.role_id ' .
            'WHERE users.id = :user_id ORDER BY permissions.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }
        $statement = Database::connection()->prepare($sql);
        $statement->bindValue('user_id', $userId);
        if ($limit !== null) {
            $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
            $statement->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        }
        $statement->execute();
        return $this->presentAll($statement->fetchAll());
    }

    public function permissionsCount(int|string $userId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM role_permissions ' .
            'INNER JOIN users ON users.role_id = role_permissions.role_id WHERE users.id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        return (int)$statement->fetchColumn();
    }

    public function registrationRoleId(): int
    {
        $database = Database::connection();
        $userCount = (int)$database->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $slug = $userCount === 0 ? 'super-admin' : 'user';
        $statement = $database->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $roleId = $statement->fetchColumn();

        if ($roleId === false) {
            throw new \RuntimeException("Required role [{$slug}] does not exist. Run migrations first.");
        }

        return (int)$roleId;
    }

    private function withPermissions(array $user): array
    {
        $user = $this->present($user);
        $user['is_active'] = (bool)$user['is_active'];
        $user['permissions'] = $this->permissions($user['id']);
        return $user;
    }

    private function detailsQuery(): string
    {
        return 'SELECT users.*, roles.name AS role_name, roles.slug AS role_slug ' .
            'FROM users INNER JOIN roles ON roles.id = users.role_id';
    }
}
