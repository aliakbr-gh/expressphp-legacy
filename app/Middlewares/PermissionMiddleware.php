<?php

declare(strict_types=1);

namespace App\Middlewares;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class PermissionMiddleware
{
    public function __invoke(Request $request, Response $response, string ...$required): ?Response
    {
        $user = $request->user();
        if ($user === null) {
            return $response->error('Unauthenticated', 401);
        }
        if (($user['role_slug'] ?? null) === 'super-admin') {
            return null;
        }

        if ($required === []) {
            return $response->error('You do not have permission to perform this action', 403);
        }

        $granted = array_column($user['permissions'] ?? [], 'slug');
        foreach ($required as $permission) {
            if (!in_array($permission, $granted, true)) {
                return $response->error('You do not have permission to perform this action', 403);
            }
        }

        return null;
    }
}
