<?php

declare(strict_types=1);

namespace ExpressPHP\Logging;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class RequestLogger
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function write(Request $request, Response $response): void
    {
        if (!(bool)(self::$config['enabled'] ?? true)) {
            return;
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone((string)(self::$config['timezone'] ?? 'UTC')));
            $directory = self::directory();

            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                return;
            }

            $startedAt = defined('APP_STARTED_AT')
                ? (float)APP_STARTED_AT
                : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

            // The raw URL is never logged: its query string can carry secrets that are not redacted.
            $entry = [
                'timestamp' => $now->format(DATE_ATOM),
                'request_id' => $request->requestId(),
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => Redactor::redact($request->query()),
                'status' => $response->statusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'ip' => $request->ip(),
                'user_id' => $request->user()['id'] ?? null,
                'user_agent' => Redactor::redact($request->userAgent(), 500),
                'input' => self::input($request),
                'request_bytes' => (int)($request->header('Content-Length') ?? strlen($request->text())),
                'response_bytes' => $response->isStream()
                    ? (int)($response->headers()['Content-Length'] ?? 0)
                    : strlen($response->body()),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ];

            $file = $directory . '/' . $now->format('Y-m-d') . '.log';
            if (!is_file($file)) {
                LogFiles::prune($directory, (int)(self::$config['retention_days'] ?? 0), $now);
            }
            file_put_contents($file, json_encode($entry, self::JSON_FLAGS) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Request logging is best-effort and must never break the application.
        }
    }

    public static function directory(): string
    {
        return rtrim(
            (string)(self::$config['path'] ?? dirname(__DIR__, 2) . '/storage/logs'),
            '/',
        );
    }

    public static function currentDate(): string
    {
        $timezone = new DateTimeZone((string)(self::$config['timezone'] ?? 'UTC'));
        return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    }

    /**
     * Returns one page of a day's request log, newest first, without loading the whole file.
     *
     * @return array{items: list<array>, total: int}
     */
    public static function page(string $date, int $limit, int $offset = 0): array
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return ['items' => [], 'total' => 0];
        }

        $file = self::directory() . '/' . $date . '.log';
        if (!is_file($file) || !is_readable($file)) {
            return ['items' => [], 'total' => 0];
        }

        $items = [];
        foreach (LogFiles::tail($file, $limit, $offset) as $line) {
            $entry = json_decode($line, true);
            $items[] = is_array($entry) ? $entry : ['raw' => $line];
        }

        return ['items' => $items, 'total' => LogFiles::countLines($file)];
    }

    private static function input(Request $request): mixed
    {
        $limit = max(0, (int)(self::$config['max_input_bytes'] ?? 4096));

        try {
            $input = $request->input();
        } catch (Throwable) {
            return '[omitted: unreadable payload]';
        }
        if ($input === [] || $input === null) {
            return null;
        }

        // Multipart and form bodies are not in the raw body, so measure what will actually be logged.
        $size = $request->text() !== '' ? strlen($request->text()) : strlen((string)json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE));
        if ($limit === 0 || $size > $limit) {
            return '[omitted: payload too large]';
        }

        return Redactor::redact($input);
    }
}
