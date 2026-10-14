<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use ExpressPHP\Http\Request;
use ExpressPHP\Logging\Redactor;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class Debugger
{
    private static array $config = [];
    private static ?Request $request = null;

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function setRequest(Request $request): void
    {
        self::$request = $request;
    }

    public static function dump(mixed ...$values): never
    {
        $enabled = (bool)(self::$config['enabled'] ?? false);
        $status = (int)(self::$config['status'] ?? 500);

        if (!$enabled) {
            self::emit($status, [
                'success' => false,
                'message' => 'Internal Server Error',
                'errors' => (object)[],
                'data' => null,
            ]);
        }

        $caller = self::caller();
        $request = self::$request;
        $timezone = (string)(self::$config['timezone'] ?? 'UTC');
        $startedAt = defined('APP_STARTED_AT') ? APP_STARTED_AT : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        $data = [
            'location' => [
                'file' => $caller['file'] ?? null,
                'line' => $caller['line'] ?? null,
                'function' => $caller['function'] ?? null,
                'class' => $caller['class'] ?? null,
            ],
            'stack' => self::stack(),
            'values' => array_map(
                static fn(mixed $value): array => [
                    'type' => get_debug_type($value),
                    'value' => self::normalize($value),
                ],
                $values,
            ),
            'request' => $request === null ? null : [
                'request_id' => $request->requestId(),
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'query' => self::redact($request->query()),
                'params' => self::redact($request->param()),
                'input' => self::requestInput($request),
                'headers' => (bool)(self::$config['show_headers'] ?? true)
                    ? self::redact($request->headers())
                    : '[hidden by configuration]',
            ],
            'runtime' => [
                'timestamp' => (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format(DATE_ATOM),
                'timezone' => $timezone,
                'execution_ms' => round((microtime(true) - (float)$startedAt) * 1000, 3),
                'memory_usage' => self::bytes(memory_get_usage(true)),
                'memory_peak' => self::bytes(memory_get_peak_usage(true)),
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
        ];

        self::emitHTML($status, $data);
    }

    private static function emit(int $status, array $payload): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            if ((bool)(self::$config['enabled'] ?? false)) {
                header('X-Debug-Dump: true');
            }
        }

        try {
            echo json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            echo '{"success":false,"message":"Debug dump could not be encoded","errors":{},"data":null}';
        }
        exit;
    }

    private static function emitHTML(int $status, array $data): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Debug-Dump: true');
        }

        $location = $data['location'];
        $file = self::escape((string)($location['file'] ?? 'unknown file'));
        $line = self::escape((string)($location['line'] ?? '?'));
        $values = '';

        foreach ($data['values'] as $index => $value) {
            $type = self::escape((string)$value['type']);
            $values .= '<div class="value"><span class="value-type">' . $type . '</span>'
                . self::renderValue($value['value']) . '</div>';
        }

        $request = $data['request'] === null
            ? '<span class="muted">No HTTP request context</span>'
            : self::renderValue($data['request']);
        $stack = self::renderValue($data['stack']);
        $runtime = self::renderValue($data['runtime']);

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>ExpressPHP Debug Dump</title><style>'
            . '*{box-sizing:border-box}body{margin:0;background:#f7f7f8;color:#282a30;font:13px/1.55 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}'
            . '.wrap{max-width:1100px;margin:auto;padding:20px}.location{color:#666;margin-bottom:8px}.location strong{color:#c7254e}.dump{background:#18171b;color:#eee;border-radius:5px;padding:14px 16px;box-shadow:0 1px 3px #0002}'
            . '.value{padding:8px 0;border-bottom:1px solid #343239}.value:last-child{border:0}.value-type{display:block;color:#999;font-size:11px;margin-bottom:3px;text-transform:uppercase}'
            . '.type,.label{color:#67d8ef}.key{color:#f3cc6f}.string{color:#9be06c}.number{color:#bca7ff}.bool{color:#ff9d6e}.null,.muted{color:#888}.resource{color:#ff6b8b}'
            . 'details{margin:2px 0 2px 12px}summary{cursor:pointer;color:#67d8ef}.entry{display:grid;grid-template-columns:minmax(80px,auto) 18px 1fr;gap:4px;padding:1px 0}.nested{padding:4px 0 6px}.arrow{color:#777}'
            . '.meta{margin:10px 0 0;background:#fff;border:1px solid #ddd;border-radius:4px;padding:8px 10px}.meta>summary{color:#555;font-family:system-ui,sans-serif;font-weight:600}.meta-body{margin-top:8px;background:#18171b;color:#eee;padding:10px;border-radius:3px}.footer{color:#888;margin-top:10px;font:11px system-ui,sans-serif}'
            . '</style></head><body><main class="wrap"><div class="location">' . $file . ':<strong>' . $line . '</strong></div><div class="dump">'
            . $values
            . '</div><details class="meta"><summary>Request</summary><div class="meta-body">' . $request . '</div></details>'
            . '<details class="meta"><summary>Stack trace</summary><div class="meta-body">' . $stack . '</div></details>'
            . '<details class="meta"><summary>Runtime</summary><div class="meta-body">' . $runtime . '</div></details>'
            . '</main></body></html>';
        exit;
    }

    private static function renderValue(mixed $value, int $depth = 0): string
    {
        if ($depth >= (int)(self::$config['max_depth'] ?? 6)) {
            return '<span class="muted">[maximum depth reached]</span>';
        }
        if (is_array($value)) {
            if ($value === []) {
                return '<span class="type">array:0 []</span>';
            }
            $entries = '';
            foreach ($value as $key => $item) {
                $entries .= '<div class="entry"><span class="key">' . self::escape((string)$key)
                    . '</span><span class="arrow">=&gt;</span><span>'
                    . self::renderValue($item, $depth + 1) . '</span></div>';
            }
            $open = $depth < 2 ? ' open' : '';
            return '<details' . $open . '><summary>array:' . count($value) . ' [</summary><div class="nested">'
                . $entries . '</div></details>';
        }
        if (is_string($value)) {
            $class = str_starts_with($value, '[REDACTED]') ? 'resource' : 'string';
            return '<span class="' . $class . '">"' . self::escape($value) . '"</span> <span class="muted">(' . strlen($value) . ')</span>';
        }
        if (is_int($value) || is_float($value)) {
            return '<span class="number">' . self::escape((string)$value) . '</span>';
        }
        if (is_bool($value)) {
            return '<span class="bool">' . ($value ? 'true' : 'false') . '</span>';
        }
        if ($value === null) {
            return '<span class="null">null</span>';
        }
        return '<span>' . self::escape((string)$value) . '</span>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function caller(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = (string)($frame['class'] ?? '');
            if ($class === self::class) {
                continue;
            }
            return $frame;
        }
        return [];
    }

    private static function requestInput(Request $request): mixed
    {
        try {
            return self::redact($request->input());
        } catch (Throwable $exception) {
            return '[unavailable: ' . $exception->getMessage() . ']';
        }
    }

    private static function stack(): array
    {
        $stack = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = (string)($frame['class'] ?? '');
            if ($class === self::class) {
                continue;
            }
            $stack[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }
        return $stack;
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        $maxDepth = (int)(self::$config['max_depth'] ?? 6);
        if ($depth >= $maxDepth) {
            return '[maximum depth reached]';
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::sensitive((string)$key)
                    ? '[REDACTED]'
                    : self::normalize($item, $depth + 1);
            }
            return $normalized;
        }
        if (is_object($value)) {
            if ($value instanceof Throwable) {
                return [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                    'code' => $value->getCode(),
                    'trace' => $value->getTraceAsString(),
                ];
            }
            return [
                'class' => $value::class,
                'properties' => self::normalize(get_object_vars($value), $depth + 1),
            ];
        }
        if (is_resource($value)) {
            return '[resource: ' . get_resource_type($value) . ']';
        }
        return $value;
    }

    private static function redact(mixed $value): mixed
    {
        return self::normalize($value);
    }

    private static function sensitive(string $key): bool
    {
        return Redactor::sensitive($key);
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        return round($size, 2) . ' ' . $units[$index];
    }
}
