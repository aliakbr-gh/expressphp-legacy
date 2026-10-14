<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use ErrorException;
use ExpressPHP\Http\Request;
use ExpressPHP\Logging\ErrorLogger;

final class ErrorHandler
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private static ?Request $request = null;
    private static array $fallbackHeaders = [];
    private static array $loggedDeprecations = [];

    /**
     * Keeps PHP diagnostics out of HTTP responses and routes them to the private error log.
     */
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            // Respect the @ operator and the current error_reporting level.
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // Deprecations are recorded once per location and must not break production after a PHP upgrade.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                $location = $file . ':' . $line;
                if (!isset(self::$loggedDeprecations[$location])) {
                    self::$loggedDeprecations[$location] = true;
                    ErrorLogger::log(new ErrorException($message, 0, $severity, $file, $line), self::$request);
                }
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
                return;
            }

            ErrorLogger::log(
                new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']),
                self::$request,
            );
            if (headers_sent()) {
                return;
            }

            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach (self::$fallbackHeaders as $name => $value) {
                header($name . ': ' . $value);
            }
            echo '{"success":false,"message":"Internal Server Error","errors":{},"data":null}';
        });
    }

    /**
     * Records the current request so a fatal error can be logged with it and answered with its request id
     * and CORS headers; without CORS headers a browser reports a network error instead of the 500.
     */
    public static function setRequest(Request $request, array $fallbackHeaders = []): void
    {
        self::$request = $request;
        self::$fallbackHeaders = $fallbackHeaders;
    }
}
