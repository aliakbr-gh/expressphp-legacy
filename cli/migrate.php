<?php

declare(strict_types=1);

use ExpressPHP\Database\Database;
use ExpressPHP\Database\Migrations\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';

$arguments = array_slice($argv, 1);
$force = in_array('--force', $arguments, true);
$positional = array_values(array_filter($arguments, static fn(string $argument): bool => !str_starts_with($argument, '--')));
$command = $positional[0] ?? 'status';
$connection = $positional[1] ?? null;

try {
    $config = require $root . '/config/app.php';
    Database::configure($config['databases'] ?? []);
    $migrator = new Migrator($root . '/migrations', $connection);

    if ($command === 'status') {
        foreach ($migrator->status() as $migration) {
            echo str_pad($migration['status'], 10) . $migration['name'] . PHP_EOL;
        }
        exit;
    }

    if ($command === 'rollback') {
        $plan = $migrator->rollbackPlan();
        if ($plan === []) {
            echo 'Nothing to do.' . PHP_EOL;
            exit;
        }

        echo 'Rollback will run down() for these migrations, which can drop tables and their data:' . PHP_EOL;
        foreach ($plan as $migration) {
            echo '  ' . $migration . PHP_EOL;
        }
        // Rolling back drops tables and their data, so it never runs without an explicit --force.
        if (!$force) {
            fwrite(STDERR, 'Nothing was changed. Run again with --force to roll back.' . PHP_EOL);
            exit(1);
        }
    }

    $completed = match ($command) {
        'migrate' => $migrator->migrate(),
        'rollback' => $migrator->rollback(),
        default => throw new RuntimeException('Use: migrate, rollback [--force], or status.'),
    };

    if ($completed === []) {
        echo 'Nothing to do.' . PHP_EOL;
        exit;
    }
    foreach ($completed as $migration) {
        echo strtoupper($command) . ': ' . $migration . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
