<?php

declare(strict_types=1);

namespace ExpressPHP\Routing;

final class Route
{
    private readonly string $pattern;
    private readonly array $parameterNames;

    /**
     * Stores the route and compiles its path pattern. Each {name} placeholder matches one path segment.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed  $action,
        public readonly array  $middleware = [],
    ) {
        $parameterNames = [];
        $pattern = preg_replace_callback(
            '/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/',
            static function (array $match) use (&$parameterNames): string {
                $parameterNames[] = $match[1];
                return '([^/]+)';
            },
            preg_quote($path, '#'),
        );

        $this->pattern = '#^' . $pattern . '$#';
        $this->parameterNames = $parameterNames;
    }

    public function match(string $method, string $path): ?array
    {
        return $this->method === $method ? $this->matchPath($path) : null;
    }

    /**
     * Matches the path regardless of method and returns the decoded route parameters.
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);
        // rawurldecode keeps a literal "+" in a path segment; "%2F" decodes to "/" inside one parameter.
        return array_combine($this->parameterNames, array_map('rawurldecode', $matches)) ?: [];
    }
}
