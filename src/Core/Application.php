<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\Password;
use ExpressPHP\Database\Database;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\CORS;
use ExpressPHP\Http\HttpException;
use ExpressPHP\Logging\ErrorLogger;
use ExpressPHP\Logging\RequestLogger;
use ExpressPHP\Mail\SMTPMailer;
use ExpressPHP\RateLimit\RateLimitDecision;
use ExpressPHP\RateLimit\RateLimiter;
use ExpressPHP\Routing\Router;
use ExpressPHP\Storage\FileUploader;
use ExpressPHP\Validation\ValidationException;
use PDOException;
use Throwable;

final class Application
{
    private Router $router;
    private RateLimiter $rateLimiter;

    /**
     * Configures every framework service from the application config and creates the router.
     */
    public function __construct(private readonly array $config = [])
    {
        $timezone = $config['timezone'] ?? 'UTC';
        $logging = [...($config['logging'] ?? []), 'timezone' => $timezone];

        Database::configure($config['databases'] ?? []);
        JWT::configure($config['jwt'] ?? []);
        Password::configure($config['password'] ?? []);
        SMTPMailer::configure($config['mail'] ?? []);
        FileUploader::configure($config['uploads'] ?? []);
        Debugger::configure([...($config['debug_dump'] ?? []), 'timezone' => $timezone]);
        RequestLogger::configure($logging);
        ErrorLogger::configure($logging);
        $this->rateLimiter = new RateLimiter($config['rate_limiter'] ?? []);
        $this->router = new Router($config['middleware'] ?? [], new Config($config));
    }

    /**
     * Registers a GET route.
     */
    public function get(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->get($path, $action, $middleware);
        return $this;
    }

    /**
     * Registers a POST route.
     */
    public function post(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->post($path, $action, $middleware);
        return $this;
    }

    /**
     * Registers a PUT route.
     */
    public function put(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->put($path, $action, $middleware);
        return $this;
    }

    /**
     * Registers a PATCH route.
     */
    public function patch(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->patch($path, $action, $middleware);
        return $this;
    }

    /**
     * Registers a DELETE route.
     */
    public function delete(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->delete($path, $action, $middleware);
        return $this;
    }

    /**
     * Registers routes under a shared path prefix and optional middleware.
     */
    public function group(
        string         $prefix,
        callable|array $handlersOrCallback,
        ?callable      $callback = null,
    ): self {
        $this->router->group($prefix, $handlersOrCallback, $callback);
        return $this;
    }

    /**
     * Returns a top-level configuration value.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Handles one request: HTTPS enforcement, CORS preflight, rate limiting, the body size limit, and routing.
     * Exceptions become JSON error responses, and security and CORS headers are added to every response.
     */
    public function dispatch(Request $request): Response
    {
        Debugger::setRequest($request);
        $CORS = new CORS($this->config['cors'] ?? []);
        ErrorHandler::setRequest($request, [
            'X-Request-ID' => $request->requestId(),
            ...$CORS->apply($request, new Response())->headers(),
        ]);
        $decision = null;

        try {
            if ((bool)($this->config['force_https'] ?? false) && !$request->secure()) {
                // Refuse rather than redirect: a client following a redirect would resend its credentials.
                return $this->finish($request, $CORS, (new Response())->error('HTTPS is required', 403));
            }

            if ($request->method() === 'OPTIONS') {
                return $this->finish($request, $CORS, (new Response())->noContent());
            }

            $decision = $this->rateLimiter->check($request);
            if (!$decision->allowed) {
                return $this->finish($request, $CORS, $decision->toResponse(new Response()));
            }

            if ($request->bodyTooLarge()) {
                $response = (new Response())->error('The request body is too large', 413);
            } else {
                $response = $this->router->dispatch($request, new Response());
            }
            return $this->finish($request, $CORS, $decision->withHeaders($response));
        } catch (ValidationException $exception) {
            $response = (new Response())->error($exception->getMessage(), 422, $exception->errors());
        } catch (HttpException $exception) {
            $response = (new Response())->error($exception->getMessage(), $exception->statusCode());
        } catch (PDOException $exception) {
            $response = $this->databaseErrorResponse($exception, $request);
        } catch (Throwable $exception) {
            ErrorLogger::log($exception, $request);
            $message = (bool)($this->config['debug'] ?? false)
                ? 'Internal Server Error: ' . $exception->getMessage()
                : 'Internal Server Error';
            $response = (new Response())->error($message, 500);
        }

        if ($decision instanceof RateLimitDecision) {
            $response = $decision->withHeaders($response);
        }

        return $this->finish($request, $CORS, $response);
    }

    /**
     * Captures the request, sends the response, finishes the connection, writes the request log, and exits.
     */
    public function run(?Request $request = null): never
    {
        $request ??= Request::capture(
            $this->config['trusted_proxies'] ?? [],
            (int)($this->config['request']['max_body_bytes'] ?? 0),
        );
        $response = $this->dispatch($request);

        try {
            $response->emit($request->method() !== 'HEAD');
        } catch (Throwable $exception) {
            // Streams fail after the status line is sent, so the failure can only be recorded.
            ErrorLogger::log($exception, $request);
        }

        // Finish the client response before touching the log file when PHP-FPM supports it.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        RequestLogger::write($request, $response);
        exit;
    }

    /**
     * Adds the request id, security headers and CORS headers to a response.
     */
    private function finish(Request $request, CORS $CORS, Response $response): Response
    {
        $response->header('X-Request-ID', $request->requestId());
        return $CORS->apply($request, $this->securityHeaders($response, $request));
    }

    /**
     * Maps database constraint violations to 409 or 422 responses. Any other database error is logged and returned as
     * a 500.
     */
    private function databaseErrorResponse(PDOException $exception, Request $request): Response
    {
        if ((string)$exception->getCode() !== '23000') {
            ErrorLogger::log($exception, $request);
            return (new Response())->error('Internal Server Error', 500);
        }

        $driverCode = (int)($exception->errorInfo[1] ?? 0);

        return match ($driverCode) {
            1062 => (new Response())->error('A record with the same unique value already exists', 409),
            1451 => (new Response())->error('This record cannot be deleted because it is currently in use', 409),
            1452 => (new Response())->error('The selected related record does not exist', 422),
            default => (new Response())->error('A database constraint prevented this operation', 409),
        };
    }

    /**
     * Adds no-store caching, nosniff, frame denial, referrer and permissions policies, and HSTS over HTTPS.
     */
    private function securityHeaders(Response $response, Request $request): Response
    {
        $response
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
