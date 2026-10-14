<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\User;
use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\JWTException;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class AuthMiddleware
{
    public function __construct(private readonly User $users = new User())
    {
    }

    public function __invoke(Request $request, Response $response): ?Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return $this->unauthorized($response);
        }

        try {
            $claims = JWT::decode($token);
        } catch (JWTException) {
            return $this->unauthorized($response);
        }

        if (!is_string($claims['sub'] ?? null) || !is_string($claims['pwd'] ?? null)) {
            return $this->unauthorized($response);
        }

        // The user is reloaded on every request, so a deactivated or deleted account is refused immediately,
        // and role and permission changes apply without a new token. A token made before the latest password
        // change carries an old fingerprint and is refused.
        $fingerprint = $this->users->passwordFingerprint($claims['sub']);
        $user = $fingerprint !== null && hash_equals($fingerprint, $claims['pwd'])
            ? $this->users->findDetailed($claims['sub'])
            : null;
        if ($user === null || !(bool)($user['is_active'] ?? false)) {
            return $this->unauthorized($response);
        }

        $request->setUser($user);
        return null;
    }

    private function unauthorized(Response $response): Response
    {
        return $response
            ->header('WWW-Authenticate', 'Bearer realm="ExpressPHP API"')
            ->error('Invalid or expired token.', 401);
    }
}
