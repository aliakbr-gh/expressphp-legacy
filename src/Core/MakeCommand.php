<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class MakeCommand
{
    // PHP keywords and reserved class names that cannot name a class.
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include',
        'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace',
        'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly',
        'require', 'resource', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    public function __construct(
        private readonly string $root,
        private readonly string $timezone = 'UTC',
    ) {
    }

    public function run(array $arguments): int
    {
        $type = strtolower((string)($arguments[0] ?? 'help'));
        $name = (string)($arguments[1] ?? '');

        if (in_array($type, ['help', '--help', '-h'], true)) {
            $this->help();
            return 0;
        }
        if ($name === '') {
            throw new RuntimeException("A name is required for [{$type}].");
        }

        $path = match ($type) {
            'migration' => $this->migration($name),
            'controller' => $this->controller($name),
            'model' => $this->model($name),
            'middleware' => $this->middleware($name),
            default => throw new RuntimeException("Unknown make type [{$type}]."),
        };

        echo 'CREATED: ' . $this->relative($path) . PHP_EOL;
        return 0;
    }

    private function migration(string $name): string
    {
        $description = $this->snake($name);
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y_m_d_His');
        $path = $this->root . '/migrations/' . $timestamp . '_' . $description . '.php';
        $template = <<<'PHP'
<?php

declare(strict_types=1);

use ExpressPHP\Database\Migrations\Migration;

return new class extends Migration {
    public function up(PDO $database): void
    {
        // Apply the database change.
    }

    public function down(PDO $database): void
    {
        // Reverse the database change.
    }
};

PHP;
        return $this->write($path, $template);
    }

    private function controller(string $name): string
    {
        $class = $this->className($name, 'Controller');
        $path = $this->root . '/app/Controllers/' . $class . '.php';
        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class {$class}
{
    public function index(Request \$request, Response \$response): Response
    {
        return \$response->success([], 'Request successful');
    }
}

PHP;
        return $this->write($path, $template);
    }

    private function model(string $name): string
    {
        $class = $this->className($name);
        $table = $this->snake($class) . 's';
        $path = $this->root . '/app/Models/' . $class . '.php';
        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Database\Model;

final class {$class} extends Model
{
    protected string \$table = '{$table}';
    protected array \$fillable = [];
}

PHP;
        return $this->write($path, $template);
    }

    private function middleware(string $name): string
    {
        $class = $this->className($name, 'Middleware');
        $path = $this->root . '/app/Middlewares/' . $class . '.php';
        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Middlewares;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class {$class}
{
    public function __invoke(Request \$request, Response \$response, string ...\$parameters): ?Response
    {
        // Return a Response to stop the request, or null to continue.
        return null;
    }
}

PHP;
        return $this->write($path, $template);
    }

    private function write(string $path, string $contents): string
    {
        if (is_file($path)) {
            throw new RuntimeException('File already exists: ' . $this->relative($path));
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create file: ' . $path);
        }
        return $path;
    }

    private function className(string $name, string $suffix = ''): string
    {
        $name = preg_replace('/' . preg_quote($suffix, '/') . '$/i', '', trim($name)) ?? $name;
        $words = explode('_', $this->snake($name));
        $class = implode('', array_map(static fn(string $word): string => ucfirst($word), $words));
        if ($class === '' || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $class) !== 1) {
            throw new RuntimeException('The class name is invalid.');
        }
        if (in_array(strtolower($class . $suffix), self::RESERVED, true)) {
            throw new RuntimeException("[{$class}{$suffix}] is a reserved word in PHP and cannot be a class name.");
        }
        return $class . $suffix;
    }

    private function snake(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($name)) ?? $name;
        $name = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?? '', '_'));
        if ($name === '') {
            throw new RuntimeException('The name is invalid.');
        }
        return $name;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }

    private function help(): void
    {
        echo "ExpressPHP generator\n\n";
        echo "  php cli/make migration action_description\n";
        echo "  php cli/make controller User\n";
        echo "  php cli/make model User\n";
        echo "  php cli/make middleware IsAuthenticated\n";
    }
}
