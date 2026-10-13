<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use ExpressPHP\Core\Config;
use ExpressPHP\Http\Pagination;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\RateLimit\RateLimiter;

final class RateLimitController
{
    private readonly RateLimiter $limiter;

    public function __construct(
        Config                       $config,
        private readonly ActivityLog $activities = new ActivityLog(),
    ) {
        $this->limiter = new RateLimiter($config->get('rate_limiter', []));
    }

    public function blocked(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;
        $blocked = $this->limiter->blocked();

        return $response->success(
            Pagination::payload(
                array_slice($blocked, $offset, $limit),
                count($blocked),
                $limit,
                $offset,
            ),
            'Blocked IP addresses loaded',
        );
    }

    public function status(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'ip' => 'required|string|ip',
        ]);

        return $response->success($this->limiter->status($data['ip']), 'Rate-limit status loaded');
    }

    public function block(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'ip' => 'required|string|ip',
        ]);
        $record = $this->limiter->block($data['ip']);
        $user = $request->user();
        $this->activities->record(
            $request,
            ($user['name'] ?? $user['username'] ?? 'User') . ' blocked IP ' . $data['ip'],
        );

        return $response->success($record, 'IP address blocked');
    }

    public function clear(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'ip' => 'required|string|ip',
        ]);
        $record = $this->limiter->clear($data['ip']);
        $user = $request->user();
        $this->activities->record(
            $request,
            ($user['name'] ?? $user['username'] ?? 'User') . ' cleared rate-limit record for IP ' . $data['ip'],
        );

        return $response->success($record, 'Rate-limit record cleared');
    }
}
