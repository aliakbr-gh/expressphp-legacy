<?php

declare(strict_types=1);

namespace ExpressPHP\RateLimit;

use ExpressPHP\Http\Request;
use JsonException;
use RuntimeException;
use Throwable;

final class RateLimiter
{
    private readonly bool $enabled;
    private readonly int $maxRequests;
    private readonly int $windowSeconds;
    private readonly int $pauseSeconds;
    private readonly int $maxViolations;
    private readonly int $blockSeconds;
    private readonly int $violationDecaySeconds;
    private readonly string $path;
    private readonly array $except;
    private readonly array $failClosed;

    public function __construct(array $config = [])
    {
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->maxRequests = max(1, (int)($config['max_requests'] ?? 10));
        $this->windowSeconds = max(1, (int)($config['window_seconds'] ?? 1));
        $this->pauseSeconds = max(1, (int)($config['pause_minutes'] ?? 5)) * 60;
        $this->maxViolations = max(1, (int)($config['max_violations'] ?? 3));
        $this->blockSeconds = max(60, (int)($config['block_minutes'] ?? 30) * 60);
        $this->violationDecaySeconds = max(60, (int)($config['violation_decay_minutes'] ?? 60) * 60);
        $this->path = rtrim(
            (string)($config['path'] ?? dirname(__DIR__, 2) . '/storage/rate-limiter'),
            '/',
        );
        $this->except = is_array($config['except'] ?? null) ? $config['except'] : [];
        $this->failClosed = is_array($config['fail_closed'] ?? null) ? $config['fail_closed'] : [];
    }

    public function check(Request $request): RateLimitDecision
    {
        $now = time();

        if (!$this->enabled || $this->excluded($request->path())) {
            return new RateLimitDecision(true, false, $this->maxRequests, $this->maxRequests, $now);
        }

        try {
            return $this->update($request->ip(), function (array $record) use ($now): array {
                $blockedUntil = $record['blocked_until'] ?? null;
                if ((bool)($record['blocked'] ?? false) && ($blockedUntil === null || (int)$blockedUntil > $now)) {
                    return [$record, new RateLimitDecision(
                        false,
                        true,
                        $this->maxRequests,
                        0,
                        $blockedUntil === null ? 0 : (int)$blockedUntil,
                        $blockedUntil === null ? 0 : max(0, (int)$blockedUntil - $now),
                        (int)($record['violations'] ?? $this->maxViolations),
                        $this->maxViolations,
                    )];
                }
                if ((bool)($record['blocked'] ?? false)) {
                    $record = $this->freshRecord((string)$record['ip']);
                }

                $lastViolationAt = (int)($record['last_violation_at'] ?? 0);
                if ($lastViolationAt > 0 && $now >= $lastViolationAt + $this->violationDecaySeconds) {
                    $record['violations'] = 0;
                    $record['last_violation_at'] = null;
                }

                $pausedUntil = (int)($record['paused_until'] ?? 0);
                if ($pausedUntil > $now) {
                    return [$record, new RateLimitDecision(
                        false,
                        false,
                        $this->maxRequests,
                        0,
                        $pausedUntil,
                        $pausedUntil - $now,
                        (int)($record['violations'] ?? 0),
                        $this->maxViolations,
                    )];
                }

                $windowStartedAt = (int)($record['window_started_at'] ?? 0);
                if ($windowStartedAt === 0 || $now >= $windowStartedAt + $this->windowSeconds) {
                    $windowStartedAt = $now;
                    $record['window_started_at'] = $now;
                    $record['requests'] = 0;
                    $record['paused_until'] = 0;
                }

                $record['requests'] = (int)($record['requests'] ?? 0) + 1;
                $resetAt = $windowStartedAt + $this->windowSeconds;

                if ($record['requests'] <= $this->maxRequests) {
                    return [$record, new RateLimitDecision(
                        true,
                        false,
                        $this->maxRequests,
                        $this->maxRequests - $record['requests'],
                        $resetAt,
                        0,
                        (int)($record['violations'] ?? 0),
                        $this->maxViolations,
                    )];
                }

                $record['violations'] = (int)($record['violations'] ?? 0) + 1;
                $record['requests'] = 0;
                $record['window_started_at'] = $now;
                $record['last_violation_at'] = $now;

                if ($record['violations'] >= $this->maxViolations) {
                    $record['blocked'] = true;
                    $record['blocked_at'] = $now;
                    $record['blocked_until'] = $now + $this->blockSeconds;
                    $record['paused_until'] = 0;

                    return [$record, new RateLimitDecision(
                        false,
                        true,
                        $this->maxRequests,
                        0,
                        $record['blocked_until'],
                        $this->blockSeconds,
                        $record['violations'],
                        $this->maxViolations,
                    )];
                }

                $record['paused_until'] = $now + $this->pauseSeconds;

                return [$record, new RateLimitDecision(
                    false,
                    false,
                    $this->maxRequests,
                    0,
                    $record['paused_until'],
                    $this->pauseSeconds,
                    $record['violations'],
                    $this->maxViolations,
                )];
            });
        } catch (Throwable) {
            if ($this->matches($request->path(), $this->failClosed)) {
                return new RateLimitDecision(
                    false,
                    false,
                    $this->maxRequests,
                    0,
                    $now + 60,
                    60,
                    0,
                    $this->maxViolations,
                );
            }
            return new RateLimitDecision(true, false, $this->maxRequests, $this->maxRequests, $now);
        }
    }

    public function status(string $ip): array
    {
        $this->validateIP($ip);
        return $this->read($ip);
    }

    public function block(string $ip): array
    {
        $this->validateIP($ip);

        return $this->update($ip, function (array $record): array {
            $record['blocked'] = true;
            $record['blocked_at'] = time();
            $record['blocked_until'] = null;
            $record['paused_until'] = 0;
            $record['violations'] = max($this->maxViolations, (int)($record['violations'] ?? 0));
            return [$record, $record];
        });
    }

    public function clear(string $ip): array
    {
        $this->validateIP($ip);

        return $this->update($ip, function (array $record): array {
            $cleared = $this->freshRecord((string)$record['ip']);
            return [$cleared, $cleared];
        });
    }

    public function blocked(): array
    {
        $this->ensureDirectory();
        $blocked = [];

        foreach (glob($this->path . '/*.json') ?: [] as $file) {
            $record = $this->readFile($file);
            $blockedUntil = $record['blocked_until'] ?? null;
            if ((bool)($record['blocked'] ?? false) && ($blockedUntil === null || (int)$blockedUntil > time())) {
                $blocked[] = $record;
            }
        }

        usort($blocked, static fn(array $a, array $b): int => ((int)($b['blocked_at'] ?? 0)) <=> ((int)($a['blocked_at'] ?? 0))
        );

        return $blocked;
    }

    private function excluded(string $path): bool
    {
        return $this->matches($path, $this->except);
    }

    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    private function update(string $ip, callable $callback): mixed
    {
        $this->ensureDirectory();
        $file = $this->file($ip);
        $handle = fopen($file, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the rate-limit record.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the rate-limit record.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $record = $this->decode($contents === false ? '' : $contents, $ip);
            [$record, $result] = $callback($record);
            $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function read(string $ip): array
    {
        $file = $this->file($ip);
        return is_file($file) ? $this->readFile($file) : $this->freshRecord($ip);
    }

    private function readFile(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            return $this->decode($contents === false ? '' : $contents, 'unknown');
        } finally {
            fclose($handle);
        }
    }

    private function decode(string $contents, string $ip): array
    {
        if ($contents === '') {
            return $this->freshRecord($ip);
        }

        try {
            $record = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return is_array($record) ? $record : $this->freshRecord($ip);
        } catch (JsonException) {
            return $this->freshRecord($ip);
        }
    }

    private function freshRecord(string $ip): array
    {
        return [
            'ip' => $ip,
            'requests' => 0,
            'window_started_at' => 0,
            'violations' => 0,
            'last_violation_at' => null,
            'paused_until' => 0,
            'blocked' => false,
            'blocked_at' => null,
            'blocked_until' => null,
        ];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException('Unable to create the rate-limit storage directory.');
        }
    }

    private function file(string $ip): string
    {
        return $this->path . '/' . hash('sha256', $ip) . '.json';
    }

    private function validateIP(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid IP address [{$ip}].");
        }
    }
}
