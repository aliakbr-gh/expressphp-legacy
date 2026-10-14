<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Fixed-window request counters stored as private JSON files.
 *
 * - check() applies the global per-client limit before routing. A client is one IPv4 address or one
 *   IPv6 network (/64 by default), because one device can rotate through a whole IPv6 prefix.
 * - hit(), attempts(), and clearKey() count any key, such as an authenticated user or a login username.
 * - block() and clear() manage permanent manual blocks of single IP addresses.
 *
 * Counters are local to one server; with several web servers behind a load balancer, each keeps its own.
 */
final class RateLimiter
{
    private readonly bool $enabled;
    private readonly int $maxRequests;
    private readonly int $windowSeconds;
    private readonly int $IPv6Prefix;
    private readonly int $recordTTLSeconds;
    private readonly int $pruneChance;
    private readonly int $pruneBatch;
    private readonly string $path;
    private readonly array $except;
    private readonly array $failClosed;

    /**
     * Reads the limiter settings. Limiting is off unless "enabled" is true.
     */
    public function __construct(array $config = [])
    {
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->maxRequests = max(1, (int)($config['max_requests'] ?? 600));
        $this->windowSeconds = max(1, (int)($config['window_seconds'] ?? 60));
        $this->IPv6Prefix = max(1, min(128, (int)($config['ipv6_prefix'] ?? 64)));
        $this->recordTTLSeconds = max(60, (int)($config['record_ttl_seconds'] ?? 86400));
        $this->pruneChance = max(0, (int)($config['prune_chance'] ?? 100));
        $this->pruneBatch = max(1, (int)($config['prune_batch'] ?? 2000));
        $this->path = rtrim((string)($config['path'] ?? dirname(__DIR__, 2) . '/storage/rate-limiter'), '/');
        $this->except = is_array($config['except'] ?? null) ? $config['except'] : [];
        $this->failClosed = is_array($config['fail_closed'] ?? null) ? $config['fail_closed'] : [];
    }

    /**
     * Reports whether limiting is enabled.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The global limit applied to every request before routing.
     */
    public function check(Request $request): RateLimitDecision
    {
        $now = time();
        if (!$this->enabled || $this->matches($request->path(), $this->except)) {
            return new RateLimitDecision(true, false, $this->maxRequests, $this->maxRequests, $now);
        }

        try {
            $this->ensureDirectories();
            // Pruning during a request is bounded, so the unlucky request never walks a huge directory.
            if ($this->pruneChance > 0 && random_int(1, $this->pruneChance) === 1) {
                $this->prune($this->pruneBatch);
            }
            if ($this->isBlocked($request->ip())) {
                return new RateLimitDecision(false, true, $this->maxRequests, 0, 0);
            }
            return $this->hit('client:' . $this->clientKey($request->ip()), $this->maxRequests, $this->windowSeconds);
        } catch (Throwable) {
            // Storage failures keep the API available, except on routes listed as fail_closed (such as login).
            if ($this->matches($request->path(), $this->failClosed)) {
                return new RateLimitDecision(false, false, $this->maxRequests, 0, $now + 60, 60);
            }
            return new RateLimitDecision(true, false, $this->maxRequests, $this->maxRequests, $now);
        }
    }

    /**
     * The client identity used by check(): an IPv4 address, or the IPv6 network it belongs to.
     */
    public function clientKey(string $ip): string
    {
        $normalized = IPAddress::normalize($ip) ?? '0.0.0.0';
        return str_contains($normalized, ':')
            ? (string)IPAddress::network($normalized, $this->IPv6Prefix)
            : $normalized;
    }

    /**
     * Counts one attempt for the key and reports whether it is still within the limit.
     */
    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitDecision
    {
        $maxAttempts = max(1, $maxAttempts);
        $windowSeconds = max(1, $windowSeconds);
        if (!$this->enabled) {
            return new RateLimitDecision(true, false, $maxAttempts, $maxAttempts, time());
        }

        return $this->update($this->counterFile($key), function (array $record) use ($maxAttempts, $windowSeconds): array {
            $now = time();
            $record = $this->currentWindow($record, $now, $windowSeconds);
            $record['count']++;
            $allowed = $record['count'] <= $maxAttempts;

            return [$record, new RateLimitDecision(
                $allowed,
                false,
                $maxAttempts,
                max(0, $maxAttempts - $record['count']),
                $record['reset_at'],
                $allowed ? 0 : max(1, $record['reset_at'] - $now),
            )];
        });
    }

    /**
     * Reports whether another attempt is allowed without counting one, for example before checking a password.
     */
    public function attempts(string $key, int $maxAttempts, int $windowSeconds): RateLimitDecision
    {
        $maxAttempts = max(1, $maxAttempts);
        $now = time();
        if (!$this->enabled) {
            return new RateLimitDecision(true, false, $maxAttempts, $maxAttempts, $now);
        }

        $record = $this->currentWindow($this->readFile($this->counterFile($key)), $now, max(1, $windowSeconds));
        $allowed = $record['count'] < $maxAttempts;

        return new RateLimitDecision(
            $allowed,
            false,
            $maxAttempts,
            max(0, $maxAttempts - $record['count']),
            $record['reset_at'],
            $allowed ? 0 : max(1, $record['reset_at'] - $now),
        );
    }

    /**
     * Deletes the counter for a key, for example after a successful login.
     */
    public function clearKey(string $key): void
    {
        $file = $this->counterFile($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Returns an IP address's client key, block state, and current request count.
     */
    public function status(string $ip): array
    {
        $ip = $this->validIP($ip);
        $client = $this->clientKey($ip);
        $block = $this->readFile($this->blockFile($client)) ?: $this->readFile($this->blockFile($ip));
        $counter = $this->currentWindow($this->readFile($this->counterFile('client:' . $client)), time(), $this->windowSeconds);

        return [
            'ip' => $ip,
            'client' => $client,
            'blocked' => $block !== [],
            'blocked_at' => $block['blocked_at'] ?? null,
            'requests' => $counter['count'],
            'limit' => $this->maxRequests,
            'reset_at' => $counter['reset_at'],
        ];
    }

    /**
     * Blocks an IP address until it is cleared. An IPv6 address blocks its whole client network (/64 by
     * default), because one device can rotate through every address in it.
     */
    public function block(string $ip): array
    {
        $ip = $this->validIP($ip);
        $record = ['ip' => $ip, 'client' => $this->clientKey($ip), 'blocked_at' => time()];
        $this->write($this->blockFile($record['client']), $record);
        return $record;
    }

    /**
     * Removes a manual block and resets the address's request counter.
     */
    public function clear(string $ip): array
    {
        $ip = $this->validIP($ip);
        $client = $this->clientKey($ip);
        // The single-address file covers blocks written before blocks applied to whole networks.
        foreach ([$this->blockFile($client), $this->blockFile($ip), $this->counterFile('client:' . $client)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return $this->status($ip);
    }

    /**
     * Returns the manually blocked IP addresses, most recently blocked first.
     *
     * @return list<array{ip: string, client?: string, blocked_at: int}>
     */
    public function blocked(): array
    {
        $this->ensureDirectories();
        $blocked = [];
        foreach (glob($this->path . '/blocked/*.json') ?: [] as $file) {
            $record = $this->readFile($file);
            if (isset($record['ip'])) {
                $blocked[] = $record;
            }
        }

        usort($blocked, static fn(array $a, array $b): int => (int)($b['blocked_at'] ?? 0) <=> (int)($a['blocked_at'] ?? 0));
        return $blocked;
    }

    /**
     * Deletes counters not used within record_ttl_seconds and returns how many were removed. Manual blocks are
     * kept. With $limit, at most that many files are examined, so the work done inside a request stays small;
     * `php cli/rate-limit prune` passes no limit and cleans everything.
     */
    public function prune(int $limit = 0): int
    {
        $this->ensureDirectories();
        $lockFile = $this->path . '/prune.lock';
        $newLock = !is_file($lockFile);
        $lock = @fopen($lockFile, 'c');
        if ($lock !== false && $newLock) {
            @chmod($lockFile, 0660);
        }
        if ($lock === false) {
            return 0;
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // Another request is already pruning.
            fclose($lock);
            return 0;
        }

        $directory = @opendir($this->path . '/counters');
        try {
            if ($directory === false) {
                return 0;
            }
            $cutoff = time() - $this->recordTTLSeconds;
            $removed = 0;
            $examined = 0;
            // readdir() streams entries one at a time, unlike glob(), which builds the whole list in memory.
            while (($entry = readdir($directory)) !== false && ($limit === 0 || $examined < $limit)) {
                if (!str_ends_with($entry, '.json')) {
                    continue;
                }
                $examined++;
                $file = $this->path . '/counters/' . $entry;
                $modified = @filemtime($file);
                if ($modified !== false && $modified < $cutoff && @unlink($file)) {
                    $removed++;
                }
            }
            return $removed;
        } finally {
            if ($directory !== false) {
                closedir($directory);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Returns the record's count and reset time, starting a new window when the old one has ended.
     */
    private function currentWindow(array $record, int $now, int $windowSeconds): array
    {
        $resetAt = (int)($record['reset_at'] ?? 0);
        if ($resetAt <= $now) {
            return ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }
        return ['count' => max(0, (int)($record['count'] ?? 0)), 'reset_at' => $resetAt];
    }

    /**
     * Reports whether a path matches any of the glob patterns.
     */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads, changes, and writes one record under an exclusive lock.
     */
    private function update(string $file, callable $callback): mixed
    {
        $this->ensureDirectories();
        $new = !is_file($file);
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the rate-limit record.');
        }
        if ($new) {
            // Group-writable so the CLI and the web server can share records; never world-readable.
            @chmod($file, 0660);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the rate-limit record.');
            }

            $contents = stream_get_contents($handle);
            [$record, $result] = $callback($this->decode($contents === false ? '' : $contents));
            $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            // A failed write must surface, so a full disk cannot silently reset every counter.
            if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Unable to write the rate-limit record.');
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Writes a record atomically through a temporary file and a rename.
     */
    private function write(string $file, array $record): void
    {
        $this->ensureDirectories();
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $encoded = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write the rate-limit record.');
        }
        @chmod($temporary, 0660);
        if (!rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write the rate-limit record.');
        }
    }

    /**
     * Reads and decodes a record under a shared lock. Returns [] when it is missing or unreadable.
     */
    private function readFile(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
            return $this->decode($contents === false ? '' : $contents);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Decodes a JSON record, treating invalid content as empty.
     */
    private function decode(string $contents): array
    {
        if ($contents === '') {
            return [];
        }
        try {
            $record = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            return is_array($record) ? $record : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * Creates the counter and block directories. The first run after upgrading moves manual blocks
     * from the previous one-file-per-IP layout and removes the old counters.
     */
    private function ensureDirectories(): void
    {
        if (is_dir($this->path . '/counters') && is_dir($this->path . '/blocked')) {
            return;
        }

        foreach (['', '/counters', '/blocked'] as $directory) {
            $target = $this->path . $directory;
            if (!is_dir($target)) {
                if (!@mkdir($target, 0770, true) && !is_dir($target)) {
                    throw new RuntimeException('Unable to create the rate-limit storage directory.');
                }
                // The umask usually removes group write, which the CLI and web server need to share records.
                @chmod($target, 0770);
            }
        }

        foreach (glob($this->path . '/*.json') ?: [] as $legacy) {
            $record = $this->readFile($legacy);
            $ip = is_string($record['ip'] ?? null) ? IPAddress::normalize($record['ip']) : null;
            if ($ip !== null && (bool)($record['blocked'] ?? false) && ($record['blocked_until'] ?? null) === null) {
                $client = $this->clientKey($ip);
                $this->write($this->blockFile($client), ['ip' => $ip, 'client' => $client, 'blocked_at' => (int)($record['blocked_at'] ?? time())]);
            }
            @unlink($legacy);
        }
    }

    /**
     * Returns the counter file for a key. The file name is the key's SHA-256 hash.
     */
    private function counterFile(string $key): string
    {
        return $this->path . '/counters/' . hash('sha256', $key) . '.json';
    }

    /**
     * Reports whether the address's client network, or the single address, is blocked.
     */
    private function isBlocked(string $ip): bool
    {
        $client = $this->clientKey($ip);
        return is_file($this->blockFile($client))
            || ($client !== $ip && is_file($this->blockFile(IPAddress::normalize($ip) ?? $ip)));
    }

    /**
     * Returns the block file for a client key or IP address.
     */
    private function blockFile(string $key): string
    {
        return $this->path . '/blocked/' . hash('sha256', $key) . '.json';
    }

    /**
     * Normalizes an IP address, or throws when it is invalid.
     */
    private function validIP(string $ip): string
    {
        $normalized = IPAddress::normalize($ip);
        if ($normalized === null) {
            throw new RuntimeException("Invalid IP address [{$ip}].");
        }
        return $normalized;
    }
}
