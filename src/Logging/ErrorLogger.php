<?php

declare(strict_types=1);

namespace ExpressPHP\Logging;

use DateTimeImmutable;
use DateTimeZone;
use ExpressPHP\Http\Request;
use Throwable;

final class ErrorLogger
{
    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Records an unexpected failure in the private daily error log. Never throws.
     */
    public static function log(Throwable $exception, ?Request $request = null): void
    {
        try {
            $timezone = new DateTimeZone((string)(self::$config['timezone'] ?? 'UTC'));
            $now = new DateTimeImmutable('now', $timezone);
            $directory = rtrim((string)(self::$config['path'] ?? dirname(__DIR__, 2) . '/storage/logs'), '/');
            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                return;
            }

            $entry = [
                'timestamp' => $now->format(DATE_ATOM),
                'request_id' => $request?->requestId(),
                'client_request_id' => $request?->clientRequestId(),
                'method' => $request?->method(),
                'path' => $request?->path(),
                'user_id' => $request?->user()['id'] ?? null,
                'exception' => self::describe($exception),
                'previous' => $exception->getPrevious() === null ? null : self::describe($exception->getPrevious()),
                'trace' => substr($exception->getTraceAsString(), 0, 8000),
            ];

            $file = $directory . '/errors-' . $now->format('Y-m-d') . '.log';
            $new = !is_file($file);
            if ($new) {
                LogFiles::prune($directory, (int)(self::$config['retention_days'] ?? 0), $now);
            }
            file_put_contents(
                $file,
                json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
            if ($new) {
                @chmod($file, 0640);
            }
        } catch (Throwable) {
            // Error logging is best-effort; the original failure is already being handled.
        }
    }

    private static function describe(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => substr($exception->getMessage(), 0, 2000),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }
}
