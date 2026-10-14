<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\Password;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class AuthController
{
    public function __construct(private readonly User $users = new User())
    {
    }

    public function register(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'username' => 'required|string|min:3|max:50|unique:users,username',
            'password' => 'required|string|min:8|max:72',
        ]);

        $data['password'] = Password::hash($data['password']);
        $data['role_id'] = $this->users->registrationRoleId();
        $data['is_active'] = true;
        $created = $this->users->create($data);
        $user = $this->users->findDetailed($created['id']);
        if ($user === null) {
            return $response->error('Registration failed', 500);
        }

        return $response->success($user, 'Registration successful', 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:1024',
        ]);

        $user = $this->users->authenticate($data['username'], $data['password']);
        if ($user === null) {
            return $response->error('Invalid username or password.', 401);
        }

        // A signed token the app stores and sends as "Authorization: Bearer <token>" until it gets a 401.
        // "pwd" ties the token to the current password, so changing the password signs out old tokens.
        $token = JWT::encode([
            'sub' => (string)$user['id'],
            'pwd' => $this->users->passwordFingerprint($user['id']),
        ]);

        return $response->success([
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', JWT::decode($token)['exp']),
        ], 'Login successful');
    }

    public function me(Request $request, Response $response): Response
    {
        return $response->success($request->user(), 'Authenticated user loaded');
    }
}
