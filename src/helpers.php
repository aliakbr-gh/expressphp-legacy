<?php

declare(strict_types=1);

use ExpressPHP\Core\Debugger;

if (!function_exists('dd')) {
    /**
     * Dumps the values and stops the request. When debugging is off, it returns a generic 500 instead.
     */
    function dd(mixed ...$values): never
    {
        Debugger::dump(...$values);
    }
}
