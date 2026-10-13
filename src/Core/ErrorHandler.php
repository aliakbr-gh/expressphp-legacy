<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use ErrorException;
use ExpressPHP\Logging\ErrorLogger;

final class ErrorHandler
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

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

            $exception = new ErrorException($message, 0, $severity, $file, $line);
            // Deprecations are recorded but must not break production after a PHP upgrade.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                ErrorLogger::log($exception);
                return true;
            }
            throw $exception;
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
                return;
            }

            ErrorLogger::log(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo '{"success":false,"message":"Internal Server Error","data":null}';
            }
        });
    }
}
