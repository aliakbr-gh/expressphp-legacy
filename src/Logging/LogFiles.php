<?php

declare(strict_types=1);

namespace ExpressPHP\Logging;

use DateTimeImmutable;

final class LogFiles
{
    private const CHUNK_BYTES = 65536;

    /**
     * Deletes dated log files (name ending in Y-m-d.log) older than the retention window.
     */
    public static function prune(string $directory, int $retentionDays, DateTimeImmutable $today): void
    {
        if ($retentionDays < 1) {
            return;
        }

        $cutoff = $today->modify('-' . $retentionDays . ' days')->format('Y-m-d');
        foreach (glob(rtrim($directory, '/') . '/*.log') ?: [] as $file) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\.log\z/', basename($file), $match) === 1 && $match[1] < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Counts non-empty lines while holding only one line in memory.
     */
    public static function countLines(string $file): int
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $count++;
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * Returns non-empty lines newest first, reading the file backwards in fixed-size chunks.
     *
     * @return list<string>
     */
    public static function tail(string $file, int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $lines = [];
        $skipped = 0;
        $carry = '';

        try {
            $position = (int)(fstat($handle)['size'] ?? 0);
            while ($position > 0 && count($lines) < $limit) {
                $length = min(self::CHUNK_BYTES, $position);
                $position -= $length;
                fseek($handle, $position);
                $parts = explode("\n", (string)fread($handle, $length) . $carry);
                // The first part may continue in the previous chunk, unless this is the file start.
                $carry = $position > 0 ? (string)array_shift($parts) : '';

                for ($index = count($parts) - 1; $index >= 0 && count($lines) < $limit; $index--) {
                    if (trim($parts[$index]) === '') {
                        continue;
                    }
                    if ($skipped < $offset) {
                        $skipped++;
                        continue;
                    }
                    $lines[] = $parts[$index];
                }
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}
