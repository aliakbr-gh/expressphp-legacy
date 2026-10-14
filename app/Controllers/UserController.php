<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use ExpressPHP\Auth\Password;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\Pagination;

final class UserController
{
    public function __construct(
        private readonly User        $users = new User(),
        private readonly ActivityLog $activities = new ActivityLog(),
        private readonly UserSession $sessions = new UserSession(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;

        return $response->success(
            Pagination::payload(
                $this->users->paginateDetailed($limit, $offset),
                $this->users->count(),
                $limit,
                $offset,
            ),
            'Users loaded',
        );
    }

    public function show(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $user = $this->users->findDetailed($id);
        return $user === null
            ? $response->error('User not found', 404)
            : $response->success($user, 'User loaded');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'username' => 'required|string|min:3|max:50|unique:users,username',
            'password' => 'required|string|min:8|max:72',
            'is_active' => 'optional|boolean',
            'role_id' => 'required|integer|min:1|exists:roles,id',
        ]);
        $data['password'] = Password::hash($data['password']);
        $data['is_active'] ??= true;

        $user = $this->users->create($data);
        $created = $this->users->findDetailed($user['id']);
        $this->activities->record(
            $request,
            $this->userName($request) . ' created user "' . $created['name'] . '"',
        );
        return $response->success($created, 'User created', 201);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $user = $this->users->find($id);
        if ($user === null) {
            return $response->error('User not found', 404);
        }

        $data = $request->validate([
            'name' => 'optional|string|min:2|max:100',
            'username' => 'optional|string|min:3|max:50',
            'password' => 'optional|string|min:8|max:72',
            'is_active' => 'optional|boolean',
        ]);
        if (isset($data['username'])) {
            $existing = $this->users->findByUsername($data['username']);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                return $response->error('Username has already been taken', 409);
            }
        }
        // A new password or a deactivation signs the user out; a role change does not need to,
        // because permissions are reloaded from the database on every request.
        $endSession = isset($data['password'])
            || (array_key_exists('is_active', $data) && (bool)$data['is_active'] !== (bool)$user['is_active']);
        if (isset($data['password'])) {
            $data['password'] = Password::hash($data['password']);
        }

        $this->users->update($id, $data);
        if ($endSession) {
            $this->sessions->endForUser($id);
        }
        $updated = $this->users->findDetailed($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' updated user "' . $updated['name'] . '"',
        );
        return $response->success($updated, 'User updated');
    }

    public function destroy(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $user = $this->users->find($id);
        if ($user === null) {
            return $response->error('User not found', 404);
        }
        if ((int)($request->user()['id'] ?? 0) === $id) {
            return $response->error('You cannot delete your own account', 409);
        }

        $this->users->delete($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' deleted user "' . $user['name'] . '"',
        );
        return $response->success(null, 'User deleted');
    }

    public function role(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $user = $this->users->findDetailed($id);
        if ($user === null) {
            return $response->error('User not found', 404);
        }

        return $response->success([
            'id' => $user['role_id'],
            'name' => $user['role_name'],
            'slug' => $user['role_slug'],
        ], 'User role loaded');
    }

    public function assignRole(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $user = $this->users->find($id);
        if ($user === null) {
            return $response->error('User not found', 404);
        }

        $data = $request->validate(['role_id' => 'required|integer|min:1|exists:roles,id']);
        $this->users->update($id, ['role_id' => $data['role_id']]);
        $updated = $this->users->findDetailed($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' assigned role "' . $updated['role_name'] . '" to user "' . $updated['name'] . '"',
        );
        return $response->success($updated, 'Role assigned to user');
    }

    public function permissions(Request $request, Response $response): Response
    {
        $params = $request->validateParams([
            'id' => 'required|integer|min:1',
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $id = $params['id'];
        if ($this->users->find($id) === null) {
            return $response->error('User not found', 404);
        }

        $limit = $params['limit'] ?? 20;
        $offset = $params['offset'] ?? 0;
        return $response->success(
            Pagination::payload(
                $this->users->permissions($id, $limit, $offset),
                $this->users->permissionsCount($id),
                $limit,
                $offset,
            ),
            'User permissions loaded',
        );
    }

    private function id(Request $request): int
    {
        return $request->validateParams(['id' => 'required|integer|min:1'])['id'];
    }

    private function userName(Request $request): string
    {
        $user = $request->user();
        return (string)($user['name'] ?? $user['username'] ?? 'System');
    }
}
