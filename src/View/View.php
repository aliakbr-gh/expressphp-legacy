<?php

declare(strict_types=1);

namespace ExpressPHP\View;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

final class View
{
    /**
     * Creates a renderer for templates under $path.
     */
    public function __construct(
        private readonly string $path = __DIR__ . '/../../app/Views',
    ) {
    }

    /**
     * Renders a template by relative name, keeping the resolved path inside the views directory.
     */
    public function render(string $name, array $data = []): string
    {
        $name = str_ends_with($name, '.php') ? substr($name, 0, -4) : $name;
        if (preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/', $name) !== 1) {
            throw new InvalidArgumentException('View names must be relative template names.');
        }

        $this->validateData($data);
        $root = realpath($this->path);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('The view directory is unavailable.');
        }

        $file = realpath($root . '/' . $name . '.php');
        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('The requested view is unavailable.');
        }

        return self::renderTemplate($file, $data);
    }

    /**
     * Rejects data keys that are not valid variable names or would replace reserved template variables.
     */
    private function validateData(array $data): void
    {
        $reserved = [
            'data', 'escape', 'this', 'GLOBALS',
            '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
        ];

        foreach (array_keys($data) as $key) {
            if (!is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1
                || str_starts_with($key, '__') || in_array($key, $reserved, true)) {
                throw new InvalidArgumentException('View data keys must be valid, non-reserved variable names.');
            }
        }
    }

    /**
     * Includes the template with its data and returns the captured output, restoring output buffers on failure.
     */
    private static function renderTemplate(string $__viewFile, array $__viewData): string
    {
        $data = $__viewData;
        $escape = static fn(string|int|float|bool|null|Stringable $value): string => htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        // Isolate template variables and keep them from replacing renderer state.
        extract($__viewData, EXTR_SKIP);
        $__bufferLevel = ob_get_level();
        ob_start();

        try {
            require $__viewFile;
            while (ob_get_level() > $__bufferLevel + 1) {
                ob_end_flush();
            }
            if (ob_get_level() !== $__bufferLevel + 1) {
                throw new RuntimeException('The view changed the output buffer.');
            }

            return (string)ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $__bufferLevel) {
                ob_end_clean();
            }
            throw new RuntimeException('Unable to render the requested view.', previous: $exception);
        }
    }
}
