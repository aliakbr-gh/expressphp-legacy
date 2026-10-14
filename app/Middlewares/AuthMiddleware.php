<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\User;
use App\Models\UserSession;
use ExpressPHP\Core\Config;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class AuthMiddleware
{
    private readonly int $idleSeconds;
    private readonly int $touchSeconds;

    public function __construct(
        Config                       $config,
        private readonly User        $users = new User(),
        private readonly UserSession $sessions = new UserSession(),
    ) {
        $this->idleSeconds = (int)$config->get('auth.session_idle_seconds', 604800);
        $this->touchSeconds = (int)$config->get('auth.session_touch_seconds', 3600);
    }

    public function __invoke(Request $request, Response $response): ?Response
    {
        $token = $request->bearerToken();
        $session = $token === null ? null : $this->sessions->findActive($token);
        if ($session === null) {
            return $this->unauthorized($response);
        }

        $user = $this->users->findDetailed($session['user_id']);
        if ($user === null || !(bool)($user['is_active'] ?? false)) {
            $this->sessions->endForUser($session['user_id']);
            return $this->unauthorized($response);
        }

        $this->sessions->touch($session, $this->idleSeconds, $this->touchSeconds);
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
