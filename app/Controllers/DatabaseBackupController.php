<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use ExpressPHP\Core\Config;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\DatabaseBackup;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Logging\ErrorLogger;
use Throwable;

final class DatabaseBackupController
{
    public function __construct(
        private readonly Config      $config,
        private readonly ActivityLog $activities = new ActivityLog(),
    ) {
    }

    public function download(Request $request, Response $response): Response
    {
        $request->validate([]);

        try {
            $connectionName = (string)$this->config->get('databases.default', 'mysql');
            $backup = new DatabaseBackup(
                Database::connection(),
                (string)$this->config->get("databases.connections.{$connectionName}.database", ''),
                (string)$this->config->get('backups.path', dirname(__DIR__, 2) . '/storage/backups'),
                (string)$this->config->get('timezone', 'UTC'),
            );
            $archive = $backup->createZIP();
        } catch (Throwable $exception) {
            ErrorLogger::log($exception, $request);
            return $response->error('Unable to create the database backup', 500);
        }

        // Runs even when the client disconnects mid-download, which skips code after the stream.
        register_shutdown_function(static function () use ($archive): void {
            if (is_file($archive['path'])) {
                @unlink($archive['path']);
            }
        });

        $user = $request->user();
        $this->activities->record(
            $request,
            ($user['name'] ?? $user['username'] ?? 'User') . ' downloaded a database backup',
        );

        return $response->sendFile($archive['path'], $archive['filename'], 'application/zip');
    }
}
