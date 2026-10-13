<?php

declare(strict_types=1);

use ExpressPHP\Core\Application;
use ExpressPHP\Core\ErrorHandler;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Logging\ErrorLogger;

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';

ErrorHandler::register();

try {
    $config = require $root . '/config/app.php';
    $app = new Application($config);

    (require $root . '/routes/api.php')($app);
} catch (Throwable $exception) {
    ErrorLogger::log($exception);
    (new Response())->error('Internal Server Error', 500)->emit();
    exit;
}

$app->run(Request::capture(
    $config['trusted_proxies'] ?? [],
    (int)($config['request']['max_body_bytes'] ?? 0),
));
