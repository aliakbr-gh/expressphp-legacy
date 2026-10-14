<?php

declare(strict_types=1);

use ExpressPHP\Core\Application;
use App\Controllers\ActivityLogController;
use App\Controllers\AuthController;
use App\Controllers\DatabaseBackupController;
use App\Controllers\HealthController;
use App\Controllers\EmailController;
use App\Controllers\ExampleController;
use App\Controllers\FileController;
use App\Controllers\PermissionController;
use App\Controllers\RoleController;
use App\Controllers\RateLimitController;
use App\Controllers\ServerLogController;
use App\Controllers\UserController;
use ExpressPHP\Http\Router;

return static function (Application $app): void {
    $appName = (string)$app->config('name', 'App');

    $app->group('/api/v1', static function (Router $router) use ($appName): void {
        $router->get('/example', [ExampleController::class, 'index']);

        $router->group('/health', static function (Router $router): void {
            $router->get('/server', [HealthController::class, 'server']);
            $router->get('/database', [HealthController::class, 'database']);
        });

        $router->group('/roles', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/', [RoleController::class, 'index'], ['permission:roles.view']);
            $router->post('/', [RoleController::class, 'store'], ['permission:roles.create']);
            $router->get('/{id}', [RoleController::class, 'show'], ['permission:roles.view']);
            $router->patch('/{id}', [RoleController::class, 'update'], ['permission:roles.update']);
            $router->delete('/{id}', [RoleController::class, 'destroy'], ['permission:roles.delete']);
            $router->get('/{id}/permissions', [RoleController::class, 'permissions'], ['permission:roles.view']);
            $router->put('/{id}/permissions', [RoleController::class, 'syncPermissions'], [
                'permission:roles.permissions.assign',
            ]);
            $router->post('/{id}/permissions/{permissionId}', [RoleController::class, 'attachPermission'], [
                'permission:roles.permissions.assign',
            ]);
            $router->delete('/{id}/permissions/{permissionId}', [RoleController::class, 'detachPermission'], [
                'permission:roles.permissions.assign',
            ]);
        });

        $router->group('/permissions', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/', [PermissionController::class, 'index'], ['permission:permissions.view']);
            $router->post('/', [PermissionController::class, 'store'], ['permission:permissions.create']);
            $router->get('/{id}', [PermissionController::class, 'show'], ['permission:permissions.view']);
            $router->patch('/{id}', [PermissionController::class, 'update'], ['permission:permissions.update']);
            $router->delete('/{id}', [PermissionController::class, 'destroy'], [
                'permission:permissions.delete',
            ]);
            $router->get('/{id}/roles', [PermissionController::class, 'roles'], ['permission:permissions.view']);
        });

        $router->group('/users', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/', [UserController::class, 'index'], ['permission:users.view']);
            $router->post('/', [UserController::class, 'store'], [
                'permission:users.create,users.roles.assign',
            ]);
            $router->get('/{id}', [UserController::class, 'show'], ['permission:users.view']);
            $router->patch('/{id}', [UserController::class, 'update'], ['permission:users.update']);
            $router->delete('/{id}', [UserController::class, 'destroy'], ['permission:users.delete']);
            $router->get('/{id}/role', [UserController::class, 'role'], ['permission:users.view']);
            $router->put('/{id}/role', [UserController::class, 'assignRole'], ['permission:users.roles.assign']);
            $router->get('/{id}/permissions', [UserController::class, 'permissions'], ['permission:users.view']);
        });

        $router->group('/activity-logs', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/', [ActivityLogController::class, 'index'], ['permission:activity-logs.view']);
            $router->post('/', [ActivityLogController::class, 'store'], ['permission:activity-logs.create']);
            $router->get('/{id}', [ActivityLogController::class, 'show'], ['permission:activity-logs.view']);
        });

        $router->get('/server-logs', [ServerLogController::class, 'index'], [
            'auth',
            'throttle:120,60',
            'permission:server-logs.view',
        ]);

        $router->group('/rate-limits', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/blocked', [RateLimitController::class, 'blocked'], [
                'permission:rate-limits.view',
            ]);
            $router->get('/status', [RateLimitController::class, 'status'], [
                'permission:rate-limits.view',
            ]);
            $router->post('/block', [RateLimitController::class, 'block'], [
                'permission:rate-limits.block',
            ]);
            $router->post('/clear', [RateLimitController::class, 'clear'], [
                'permission:rate-limits.clear',
            ]);
        });

        $router->group('/emails', ['auth', 'throttle:120,60'], static function (Router $router): void {
            $router->get('/', [EmailController::class, 'index'], ['permission:emails.view']);
            $router->post('/send', [EmailController::class, 'send'], ['permission:emails.send']);
            $router->get('/{id}', [EmailController::class, 'show'], ['permission:emails.view']);
        });

        $router->post('/files/upload', [FileController::class, 'upload'], [
            'auth',
            'throttle:120,60',
            'permission:files.upload',
        ]);

        $router->get('/database-backups/download', [DatabaseBackupController::class, 'download'], [
            'auth',
            'throttle:120,60',
            'permission:database-backups.download',
        ]);

        $router->group('/auth', static function (Router $router): void {
            $router->post('/register', [AuthController::class, 'register']);
            $router->post('/login', [AuthController::class, 'login']);
            $router->get('/me', [AuthController::class, 'me'], ['auth', 'throttle:120,60']);
        });
    });
};
