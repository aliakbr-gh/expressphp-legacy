<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Logging\RequestLogger;

final class ServerLog
{
    public function paginate(string $date, int $limit, int $offset): array
    {
        return RequestLogger::page($date, $limit, $offset);
    }

    public function today(): string
    {
        return RequestLogger::currentDate();
    }
}
