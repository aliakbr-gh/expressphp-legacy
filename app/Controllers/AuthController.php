<?php

declare(strict_types=1);

namespace App\Controllers;

use ExpressPHP\Auth\Password;
use ExpressPHP\Core\Config;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\RateLimit\RateLimiter;
use App\Models\User;
use App\Models\UserSession;

final class AuthController
{
    private readonly RateLimiter $limiter;
    private readonly int $loginMaxAttempts;
    private readonly int $loginWindowSeconds;
    private readonly int $loginAccountMaxAttempts;
    private readonly int $loginAccountWindowSeconds;
    private readonly int $sessionIdleSeconds;
    private readonly int $sessionLifetimeSeconds;

    public function __construct(
        Config                       $config,
        private readonly User        $users = new User(),
        private readonly UserSession $sessions = new UserSession(),
    ) {
        $this->limiter = new RateLimiter($config->get('rate_limiter', []));
        $this->loginMaxAttempts = (int)$config->get('rate_limiter.login_max_attempts', 5);
        $this->loginWindowSeconds = (int)$config->get('rate_limiter.login_window_seconds', 900);
        $this->loginAccountMaxAttempts = (int)$config->get('rate_limiter.login_account_max_attempts', 50);
        $this->loginAccountWindowSeconds = (int)$config->get('rate_limiter.login_account_window_seconds', 3600);
        $this->sessionIdleSeconds = (int)$config->get('auth.session_idle_seconds', 604800);
        $this->sessionLifetimeSeconds = (int)$config->get('auth.session_lifetime_seconds', 7776000);
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
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $limits = $this->loginLimits($data['username'], $request->ip());
        foreach ($limits as [$key, $maxAttempts, $windowSeconds]) {
            $decision = $this->limiter->attempts($key, $maxAttempts, $windowSeconds);
            if (!$decision->allowed) {
                return $decision->toResponse($response);
            }
        }

        $user = $this->users->authenticate($data['username'], $data['password']);
        if ($user === null) {
            foreach ($limits as [$key, $maxAttempts, $windowSeconds]) {
                $this->limiter->hit($key, $maxAttempts, $windowSeconds);
            }
            return $response->error('Invalid username or password.', 401);
        }

        // Only this client's count is reset; the account-wide count still expires on its own window.
        $this->limiter->clearKey($limits[0][0]);
        // Starting a session replaces any session on another device.
        $session = $this->sessions->start(
            $user['id'],
            $this->sessionIdleSeconds,
            $this->sessionLifetimeSeconds,
            $request->ip(),
            $request->userAgent(),
        );

        return $response->success([
            'user' => $user,
            'token' => $session['token'],
            'token_type' => 'Bearer',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $session['expires_at']),
            'absolute_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $session['absolute_expires_at']),
        ], 'Login successful');
    }

    /**
     * Returns the failed-login counters for this attempt as [key, max attempts, window seconds].
     *
     * Keys use the account id from a database lookup, so spellings the database treats as equal
     * ("akbar", "ÁKBÁR") share one count. Failures are counted per account and client network, so one
     * attacker cannot lock other people out, plus a higher account-wide limit against distributed guessing.
     * Unknown usernames are counted per client network.
     */
    private function loginLimits(string $username, string $ip): array
    {
        $client = $this->limiter->clientKey($ip);
        $account = $this->users->findByUsername($username);
        if ($account === null) {
            return [["login:client:{$client}", $this->loginMaxAttempts, $this->loginWindowSeconds]];
        }

        return [
            ["login:user:{$account['id']}:client:{$client}", $this->loginMaxAttempts, $this->loginWindowSeconds],
            ["login:user:{$account['id']}", $this->loginAccountMaxAttempts, $this->loginAccountWindowSeconds],
        ];
    }

    public function me(Request $request, Response $response): Response
    {
        return $response->success($request->user(), 'Authenticated user loaded');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->sessions->endForUser($request->user()['id']);
        return $response->success(null, 'Logout successful');
    }
}
