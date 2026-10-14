<?php

declare(strict_types=1);

namespace App\Middlewares;

use ExpressPHP\Core\Config;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\RateLimiter;
use ExpressPHP\Http\Response;
use ExpressPHP\Logging\ErrorLogger;
use RuntimeException;
use Throwable;

/**
 * Limits requests per signed-in user, for example 'throttle:120,60' for 120 requests per 60 seconds.
 * Place it after 'auth'. Guests are counted by IP address or IPv6 network instead.
 */
final class ThrottleMiddleware
{
    private readonly RateLimiter $limiter;

    public function __construct(Config $config)
    {
        $this->limiter = new RateLimiter($config->get('rate_limiter', []));
    }

    public function __invoke(Request $request, Response $response, string ...$parameters): ?Response
    {
        if (count($parameters) !== 2 || !ctype_digit($parameters[0]) || !ctype_digit($parameters[1])) {
            throw new RuntimeException('Use throttle:<max requests>,<window seconds>, for example throttle:120,60.');
        }
        [$maxRequests, $windowSeconds] = array_map('intval', $parameters);

        $user = $request->user();
        $identity = $user !== null ? 'user:' . $user['id'] : 'client:' . $this->limiter->clientKey($request->ip());

        try {
            $decision = $this->limiter->hit("throttle:{$maxRequests}:{$windowSeconds}:{$identity}", $maxRequests, $windowSeconds);
        } catch (Throwable $exception) {
            // A storage failure must not take the API down; the global limit still applies.
            ErrorLogger::log($exception, $request);
            return null;
        }

        return $decision->allowed ? null : $decision->toResponse($response);
    }
}
