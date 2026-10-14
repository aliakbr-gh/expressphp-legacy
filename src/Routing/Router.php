<?php

declare(strict_types=1);

namespace ExpressPHP\Routing;

use ExpressPHP\Core\Config;
use ExpressPHP\Core\Debugger;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Router
{
    private array $routes = [];
    private array $registered = [];
    private array $prefixStack = [];
    private array $middlewareStack = [];

    /**
     * Creates a router with middleware aliases. Config is passed to constructors that ask for it.
     */
    public function __construct(
        private readonly array   $middlewareAliases = [],
        private readonly ?Config $config = null,
    ) {
    }

    /**
     * Registers a GET route.
     */
    public function get(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('GET', $path, $action, $middleware);
    }

    /**
     * Registers a POST route.
     */
    public function post(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('POST', $path, $action, $middleware);
    }

    /**
     * Registers a PUT route.
     */
    public function put(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('PUT', $path, $action, $middleware);
    }

    /**
     * Registers a PATCH route.
     */
    public function patch(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $action, $middleware);
    }

    /**
     * Registers a DELETE route.
     */
    public function delete(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $action, $middleware);
    }

    /**
     * Registers routes under a shared path prefix and optional middleware.
     */
    public function group(
        string         $prefix,
        callable|array $middlewareOrCallback,
        ?callable      $callback = null,
    ): self {
        if ($callback === null) {
            if (!is_callable($middlewareOrCallback)) {
                throw new RuntimeException('A route group callback is required.');
            }
            $callback = $middlewareOrCallback;
            $middleware = [];
        } else {
            $middleware = is_array($middlewareOrCallback) ? $middlewareOrCallback : [$middlewareOrCallback];
            $this->assertMiddleware($middleware);
        }

        $this->prefixStack[] = $this->normalizePath($prefix);
        $this->middlewareStack[] = $middleware;

        try {
            $callback($this);
        } finally {
            array_pop($this->prefixStack);
            array_pop($this->middlewareStack);
        }

        return $this;
    }

    /**
     * Registers a route, rejecting invalid actions, empty middleware parameters, and duplicates.
     */
    public function add(string $method, string $path, callable|array $action, array $middleware = []): self
    {
        if (!$this->validAction($action)) {
            throw new RuntimeException('A route action must be callable or [Controller::class, method].');
        }
        $this->assertMiddleware($middleware);

        $method = strtoupper($method);
        $path = $this->joinPaths(...[...$this->prefixStack, $path]);
        $key = $method . ' ' . $path;
        if (isset($this->registered[$key])) {
            throw new RuntimeException("Route [{$key}] is already registered.");
        }
        $this->registered[$key] = true;

        $groupMiddleware = $this->middlewareStack === [] ? [] : array_merge(...$this->middlewareStack);
        $this->routes[] = new Route($method, $path, $action, [...$groupMiddleware, ...$middleware]);
        return $this;
    }

    /**
     * Finds the route for the request and runs its middleware and action, or returns 405 or 404.
     */
    public function dispatch(Request $request, Response $response): Response
    {
        // HEAD runs the GET route; the application omits the body when emitting.
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $path = $request->path();
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);
            if ($params === null) {
                continue;
            }
            if ($route->method !== $method) {
                $allowed[] = $route->method;
                continue;
            }

            $request = $request->withParams($params);
            Debugger::setRequest($request);
            foreach ($route->middleware as $definition) {
                [$middleware, $parameters] = $this->resolveMiddleware($definition);
                $result = $middleware($request, $response, ...$parameters);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            $action = $this->resolveAction($route->action);
            $result = $action($request, $response);
            return $result instanceof Response ? $result : $response;
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            return $response
                ->header('Allow', implode(', ', array_unique($allowed)))
                ->error('Method not allowed', 405);
        }

        return $response->error('Route not found', 404);
    }

    /**
     * Rejects middleware that is not an alias or callable, or that has an empty alias or parameter.
     */
    private function assertMiddleware(array $middleware): void
    {
        foreach ($middleware as $item) {
            if (is_callable($item)) {
                continue;
            }
            if (!is_string($item)) {
                throw new RuntimeException('Route middleware must be an alias or callable.');
            }

            // An empty parameter list such as "permission:" would make permission checks pass for everyone.
            [$alias, $parameterText] = array_pad(explode(':', $item, 2), 2, null);
            if ($alias === '') {
                throw new RuntimeException("Middleware [{$item}] has an empty alias.");
            }
            if ($parameterText !== null && in_array('', array_map('trim', explode(',', $parameterText)), true)) {
                throw new RuntimeException("Middleware [{$item}] has an empty parameter.");
            }
        }
    }

    /**
     * Reports whether an action is a callable or a [Controller::class, method] pair.
     */
    private function validAction(mixed $action): bool
    {
        return is_callable($action)
            || (is_array($action) && count($action) === 2 && is_string($action[0]) && is_string($action[1]));
    }

    /**
     * Creates the controller and returns the callable action.
     */
    private function resolveAction(mixed $action): callable
    {
        if (is_array($action) && is_string($action[0])) {
            $class = $action[0];
            $method = $action[1];
            if (!class_exists($class)) {
                throw new RuntimeException("Controller [{$class}] was not found.");
            }
            $controller = $this->instantiate($class);
            if (!is_callable([$controller, $method])) {
                throw new RuntimeException("Controller action [{$class}::{$method}] is not callable.");
            }
            return [$controller, $method];
        }
        if (!is_callable($action)) {
            throw new RuntimeException('Route action is not callable.');
        }
        return $action;
    }

    /**
     * Resolves an alias such as "permission:a,b" to a callable and its parameters.
     */
    private function resolveMiddleware(mixed $definition): array
    {
        $parameters = [];
        if (is_string($definition)) {
            [$alias, $parameterText] = array_pad(explode(':', $definition, 2), 2, '');
            if (!array_key_exists($alias, $this->middlewareAliases)) {
                throw new RuntimeException("Middleware alias [{$alias}] is not configured.");
            }
            $definition = $this->middlewareAliases[$alias];
            $parameters = $parameterText === '' ? [] : array_map('trim', explode(',', $parameterText));
        }

        if (is_string($definition) && class_exists($definition)) {
            $definition = $this->instantiate($definition);
        }
        if (!is_callable($definition)) {
            throw new RuntimeException('Resolved middleware is not callable.');
        }
        return [$definition, $parameters];
    }

    /**
     * Creates a controller or middleware. Constructor parameters typed as Config receive the
     * application configuration; every other parameter keeps its declared default.
     */
    private function instantiate(string $class): object
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $arguments = [];
        if ($constructor !== null && $this->config !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && $type->getName() === Config::class) {
                    $arguments[$parameter->getName()] = $this->config;
                }
            }
        }
        return new $class(...$arguments);
    }

    /**
     * Normalizes a path to a leading slash and no trailing slash.
     */
    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    /**
     * Joins group prefixes and a route path into one normalized path.
     */
    private function joinPaths(string ...$paths): string
    {
        $segments = [];
        foreach ($paths as $path) {
            $path = trim($path, '/');
            if ($path !== '') {
                $segments[] = $path;
            }
        }
        return $this->normalizePath(implode('/', $segments));
    }
}
