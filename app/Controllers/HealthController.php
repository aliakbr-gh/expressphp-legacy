<?php

declare(strict_types=1);

namespace App\Controllers;

use ExpressPHP\Database\Database;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use DateTimeImmutable;
use Throwable;

final class HealthController
{
    public function server(Request $request, Response $response): Response
    {
        return $response->success([
            'service' => 'server',
            'status' => 'healthy',
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        ], 'Server is healthy');
    }

    public function database(Request $request, Response $response): Response
    {
        $startedAt = microtime(true);

        try {
            Database::connection()->query('SELECT 1')->fetchColumn();

            return $response->success([
                'service' => 'database',
                'status' => 'healthy',
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            ], 'Database is healthy');
        } catch (Throwable) {
            return $response->error('Database is unavailable', 503, data: [
                'service' => 'database',
                'status' => 'unhealthy',
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            ]);
        }
    }
}
