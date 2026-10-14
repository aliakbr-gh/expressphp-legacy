<?php

declare(strict_types=1);

// Framework regression tests. Run with: php tests/run.php
// No database server is needed: model and transaction tests use a temporary SQLite file.

use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\JWTException;
use ExpressPHP\Auth\Password;
use ExpressPHP\Core\Application;
use ExpressPHP\Core\Config;
use ExpressPHP\Core\MakeCommand;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\Model;
use ExpressPHP\Http\IPAddress;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Logging\LogFiles;
use ExpressPHP\Logging\Redactor;
use ExpressPHP\Logging\RequestLogger;
use ExpressPHP\Mail\SMTPMailer;
use ExpressPHP\RateLimit\RateLimiter;
use ExpressPHP\Storage\FileUploader;
use ExpressPHP\Support\UTF8;
use ExpressPHP\Validation\ValidationException;
use ExpressPHP\Validation\Validator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$workspace = sys_get_temp_dir() . '/expressphp-tests-' . bin2hex(random_bytes(4));
mkdir($workspace . '/logs', 0700, true);
register_shutdown_function(static function () use ($workspace): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($workspace);
});

$tests = [];

/**
 * Registers a named test.
 */
function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

/**
 * Fails the current test when the condition is false.
 */
function expect(bool $condition, string $detail = ''): void
{
    if (!$condition) {
        throw new RuntimeException($detail === '' ? 'expectation failed' : $detail);
    }
}

/**
 * Runs the body and returns the exception, failing unless it is an instance of $class.
 */
function expectThrows(string $class, callable $body): Throwable
{
    try {
        $body();
    } catch (Throwable $exception) {
        expect($exception instanceof $class, 'expected ' . $class . ', got ' . $exception::class . ': ' . $exception->getMessage());
        return $exception;
    }
    throw new RuntimeException('expected ' . $class . ' to be thrown');
}

/**
 * Calls a private or protected method, for white-box tests.
 */
function invokePrivate(object|string $target, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($target, $method))->invoke(is_object($target) ? $target : null, ...$arguments);
}

final class ConfigProbeController
{
    /**
     * Receives Config from the router, which the test checks.
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Returns the configured JWT key id.
     */
    public function show(Request $request, Response $response): Response
    {
        return $response->success($this->config->get('jwt.kid'));
    }
}

$config = require $root . '/config/app.php';
$config['rate_limiter']['enabled'] = false;
$config['logging']['path'] = $workspace . '/logs';
$config['middleware']['test.user'] = static function (Request $request, Response $response): ?Response {
    $request->setUser(['id' => 7]);
    return null;
};

$app = new Application($config);
$app->get('/echo/{value}', static fn(Request $r, Response $s) => $s->success($r->param('value')), ['test.user']);
$app->post('/form', static fn(Request $r, Response $s) => $s->success($r->validate(['a' => 'required|string'])));
$app->get('/search', static fn(Request $r, Response $s) => $s->success($r->validate(['q' => 'optional|string'])));
$app->get('/fail', static function (): never {
    throw new LogicException('boom');
});
$app->get('/stream-then-error', static fn(Request $r, Response $s) => $s->stream(static fn() => print('LEAK'))->error('no', 403));
$app->get('/config', [ConfigProbeController::class, 'show']);

$server = static fn(array $extra = []): array => ['REMOTE_ADDR' => '10.0.0.1', ...$extra];
$json = ['CONTENT_TYPE' => 'application/json'];

// Request input and identity
test('POST rejects query-string fields', function () use ($app, $server, $json) {
    $response = $app->dispatch(new Request('POST', '/form?a=x', query: ['a' => 'x'], server: $server($json), rawBody: '{"a":"b"}'));
    expect($response->statusCode() === 422, $response->body());
});
test('POST accepts body fields', function () use ($app, $server, $json) {
    expect($app->dispatch(new Request('POST', '/form', server: $server($json), rawBody: '{"a":"b"}'))->statusCode() === 200);
});
test('GET rejects a body', function () use ($app, $server, $json) {
    expect($app->dispatch(new Request('GET', '/search', server: $server($json), rawBody: '{"q":"x"}'))->statusCode() === 422);
});
test('oversized body returns 413', function () use ($app, $server, $json) {
    expect($app->dispatch(new Request('POST', '/form', server: $server($json), bodyTooLarge: true))->statusCode() === 413);
});
test('X-Forwarded-For is read from the right through trusted CIDR proxies', function () {
    $request = new Request('GET', '/', server: [
        'REMOTE_ADDR' => '10.0.0.9',
        'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.5',
    ], trustedProxies: ['10.0.0.0/8']);
    expect($request->ip() === '203.0.113.5', $request->ip());
    expect(IPAddress::normalize('::ffff:1.2.3.4') === '1.2.3.4');
    expect(IPAddress::inRange('2001:db8::5', '2001:db8::/32'));
});
test('request id is server-generated; client id kept separately', function () {
    $request = new Request('GET', '/', server: ['HTTP_X_REQUEST_ID' => 'client-chosen-id-1']);
    expect($request->requestId() !== 'client-chosen-id-1');
    expect(preg_match('/\A[0-9a-f]{16}\z/', $request->requestId()) === 1);
    expect($request->clientRequestId() === 'client-chosen-id-1');
});

// Routing
test('route parameters keep a literal plus', function () use ($app, $server) {
    $response = $app->dispatch(new Request('GET', '/echo/a+b%2Bc', server: $server()));
    expect(json_decode($response->body(), true)['data'] === 'a+b+c', $response->body());
});
test('wrong method returns 405 with Allow', function () use ($app, $server) {
    $response = $app->dispatch(new Request('DELETE', '/form', server: $server()));
    expect($response->statusCode() === 405 && $response->headers()['Allow'] === 'POST, OPTIONS');
});
test('HEAD runs the GET route', function () use ($app, $server) {
    expect($app->dispatch(new Request('HEAD', '/echo/x', server: $server()))->statusCode() === 200);
});
test('empty middleware parameter and duplicate routes are rejected', function () use ($app) {
    expectThrows(RuntimeException::class, fn() => $app->get('/x', fn() => null, ['permission:']));
    expectThrows(RuntimeException::class, fn() => $app->post('/form', fn() => null));
});
test('controllers receive Config by constructor type', function () use ($app, $server) {
    $response = $app->dispatch(new Request('GET', '/config', server: $server()));
    expect(json_decode($response->body(), true)['data'] === 'primary', $response->body());
});
test('HTTPS is enforced when configured', function () use ($config, $server) {
    $secure = new Application([...$config, 'force_https' => true]);
    $secure->get('/h', fn(Request $r, Response $s) => $s->success());
    expect($secure->dispatch(new Request('GET', '/h', server: $server()))->statusCode() === 403);
    expect($secure->dispatch(new Request('GET', '/h', server: $server(['HTTPS' => 'on'])))->statusCode() === 200);
});

// Responses
test('error() replaces an earlier stream', function () use ($app, $server) {
    $response = $app->dispatch(new Request('GET', '/stream-then-error', server: $server()));
    expect(!$response->isStream() && $response->statusCode() === 403);
});
test('sendFile uses the extension type and a sandboxed attachment', function () use ($workspace) {
    file_put_contents($workspace . '/x.txt', '<script>alert(1)</script>');
    $headers = (new Response())->sendFile($workspace . '/x.txt')->headers();
    expect(str_starts_with($headers['Content-Type'], 'text/plain'));
    expect(str_starts_with($headers['Content-Disposition'], 'attachment'));
    expect(str_contains($headers['Content-Security-Policy'], 'sandbox'));
});
test('attachment adds an RFC 5987 UTF-8 filename', function () {
    $header = (new Response())->attachment('رپورٹ.pdf')->headers()['Content-Disposition'];
    expect(str_contains($header, "filename*=UTF-8''%D8%B1"), $header);
});
test('invalid UTF-8 encodes and cookies stay separate', function () {
    expect(str_contains((new Response())->json(['v' => "a\xff"])->body(), '\\ufffd'));
    $cookies = (new Response())->cookie('a', '1')->cookie('b', '2', ['same_site' => 'none'])->cookies();
    expect(count($cookies) === 2 && str_contains($cookies[1], 'Secure'));
});
test('views send a Content-Security-Policy', function () {
    expect(isset((new Response())->view('example', ['title' => 'x'])->headers()['Content-Security-Policy']));
});

// Error response shape (FW-07)
test('validation errors use a string message and a field map', function () use ($app, $server, $json) {
    $body = json_decode($app->dispatch(new Request('POST', '/form', server: $server($json), rawBody: '{"x":1}'))->body(), true);
    expect($body['message'] === 'Invalid Data', json_encode($body));
    expect($body['errors']['a'] === ['The a field is required.'], json_encode($body));
    expect($body['errors']['x'] === ['Invalid key x in request.'], json_encode($body));
    expect($body['data'] === null);
});
test('error() normalizes a plain list into _request string lists', function () {
    $body = json_decode((new Response())->error('x', 400, ['Name is required.'])->body(), true);
    expect($body['errors'] === ['_request' => ['Name is required.']], json_encode($body));
    $body = json_decode((new Response())->error('x', 422, ['name' => 'Required.'])->body(), true);
    expect($body['errors'] === ['name' => ['Required.']], json_encode($body));
});
test('the _request field name is reserved in rules', function () {
    expectThrows(RuntimeException::class, fn() => Validator::validate([], ['_request' => 'optional|string']));
});
test('every error response has an errors object', function () use ($app, $server) {
    $notFound = $app->dispatch(new Request('GET', '/missing', server: $server()))->body();
    expect(str_contains($notFound, '"errors":{}') && str_contains($notFound, '"message":"Route not found"'), $notFound);
    expect(str_contains((new Response())->error('x', 422, ['0' => ['y']])->body(), '"errors":{"0":["y"]}'));
});

// Rate limiting (FW-03)
test('limiter counts per key, reports Retry-After, and resets', function () use ($workspace) {
    $limiter = new RateLimiter(['enabled' => true, 'path' => $workspace . '/limits']);
    expect($limiter->hit('k', 2, 60)->allowed && $limiter->hit('k', 2, 60)->allowed);
    $denied = $limiter->hit('k', 2, 60);
    expect(!$denied->allowed && $denied->retryAfter > 0);
    $response = $denied->toResponse(new Response());
    expect($response->statusCode() === 429 && $response->headers()['Retry-After'] === (string)$denied->retryAfter);
    expect(json_decode($response->body(), true)['data']['retry_after'] === $denied->retryAfter);
    $limiter->clearKey('k');
    expect($limiter->hit('k', 2, 60)->allowed);
});
test('attempts() checks without counting', function () use ($workspace) {
    $limiter = new RateLimiter(['enabled' => true, 'path' => $workspace . '/limits']);
    for ($i = 0; $i < 5; $i++) {
        expect($limiter->attempts('login:alice', 2, 60)->allowed);
    }
    $limiter->hit('login:alice', 2, 60);
    $limiter->hit('login:alice', 2, 60);
    expect(!$limiter->attempts('login:alice', 2, 60)->allowed);
});
test('IPv6 clients are grouped by /64; IPv4 stays per address', function () use ($workspace) {
    $limiter = new RateLimiter(['enabled' => true, 'path' => $workspace . '/limits']);
    expect($limiter->clientKey('2001:db8:1:2:aaaa::1') === '2001:db8:1:2::/64');
    expect($limiter->clientKey('2001:db8:1:2:bbbb::9') === $limiter->clientKey('2001:db8:1:2:aaaa::1'));
    expect($limiter->clientKey('203.0.113.5') === '203.0.113.5');
});
test('blocking an IPv6 address blocks its /64; old single-address blocks still apply', function () use ($workspace) {
    $limiter = new RateLimiter(['enabled' => true, 'path' => $workspace . '/v6-limits']);
    $limiter->block('2001:db8:5:6::1');
    $other = new Request('GET', '/', server: ['REMOTE_ADDR' => '2001:db8:5:6:ffff::9']);
    expect($limiter->check($other)->blocked);
    expect($limiter->status('2001:db8:5:6:ffff::9')['blocked']);
    $limiter->clear('2001:db8:5:6:ffff::9');
    expect($limiter->check($other)->allowed);
    // A block file written by the earlier single-address layout.
    file_put_contents($workspace . '/v6-limits/blocked/' . hash('sha256', '2001:db8:7::1') . '.json', '{"ip":"2001:db8:7::1","blocked_at":1}');
    expect($limiter->check(new Request('GET', '/', server: ['REMOTE_ADDR' => '2001:db8:7::1']))->blocked);
});
test('manual blocks return 403; clear removes them', function () use ($workspace) {
    $limiter = new RateLimiter(['enabled' => true, 'path' => $workspace . '/limits']);
    $request = new Request('GET', '/', server: ['REMOTE_ADDR' => '198.51.100.7']);
    $limiter->block('198.51.100.7');
    $decision = $limiter->check($request);
    expect($decision->blocked && $decision->toResponse(new Response())->statusCode() === 403);
    expect(count($limiter->blocked()) === 1);
    $limiter->clear('198.51.100.7');
    expect($limiter->check($request)->allowed);
});
test('prune deletes stale counters and keeps blocks; old blocks migrate', function () use ($workspace) {
    $path = $workspace . '/legacy-limits';
    mkdir($path);
    file_put_contents($path . '/old1.json', json_encode(['ip' => '192.0.2.1', 'blocked' => true, 'blocked_until' => null, 'blocked_at' => 1]));
    file_put_contents($path . '/old2.json', json_encode(['ip' => '192.0.2.2', 'requests' => 3, 'blocked' => false]));
    $limiter = new RateLimiter(['enabled' => true, 'path' => $path, 'record_ttl_seconds' => 60]);
    $limiter->hit('stale', 5, 60);
    $limiter->hit('fresh', 5, 60);
    touch($path . '/counters/' . hash('sha256', 'stale') . '.json', time() - 3600);
    expect($limiter->prune() === 1);
    expect(array_column($limiter->blocked(), 'ip') === ['192.0.2.1'], json_encode($limiter->blocked()));
    expect(glob($path . '/*.json') === []);
});
test('throttle middleware limits each signed-in user', function () use ($config, $server, $workspace) {
    $limited = new Application([...$config, 'rate_limiter' => [
        ...$config['rate_limiter'], 'enabled' => true, 'path' => $workspace . '/throttle', 'except' => [],
    ]]);
    $limited->get('/me', fn(Request $r, Response $s) => $s->success(), ['test.user', 'throttle:2,60']);
    $statuses = array_map(fn() => $limited->dispatch(new Request('GET', '/me', server: $server()))->statusCode(), range(1, 3));
    expect($statuses === [200, 200, 429], json_encode($statuses));
    $limited->get('/bad', fn(Request $r, Response $s) => $s->success(), ['throttle:many']);
    expect($limited->dispatch(new Request('GET', '/bad', server: $server()))->statusCode() === 500);
});

// UTF-8 storage helpers
test('UTF8 repairs invalid bytes and truncates on character boundaries', function () {
    expect(UTF8::scrub("ok\xC3") === "ok\u{FFFD}");
    expect(UTF8::scrub('سلام') === 'سلام');
    $cut = UTF8::truncate(str_repeat('a', 499) . 'é', 500);
    expect(strlen($cut) === 499 && preg_match('//u', $cut) === 1, (string)strlen($cut));
    expect(UTF8::truncate('😀😀', 5) === '😀');
    expect(UTF8::truncate('short', 500) === 'short');
});

// Errors and logging
test('unexpected exceptions are logged without argument values', function () use ($app, $server, $workspace) {
    $response = $app->dispatch(new Request('GET', '/fail', server: $server()));
    expect($response->statusCode() === 500 && isset($response->headers()['X-Request-ID']));
    $log = (string)file_get_contents((string)(glob($workspace . '/logs/errors-*.log')[0] ?? ''));
    expect(str_contains($log, 'boom'));
    expect(ini_get('zend.exception_ignore_args') === '1');
    $secret = 'hunter2-secret';
    $trace = (static fn(string $value) => new RuntimeException('x'))($secret)->getTraceAsString();
    expect(!str_contains($trace, $secret), $trace);
});
test('request log records the user, redacts, and never stores the URL', function () use ($app, $server, $json, $workspace) {
    $request = new Request('GET', '/echo/z', server: $server(['HTTP_USER_AGENT' => "ua\xff"]));
    RequestLogger::write($request, $app->dispatch($request));
    $post = new Request('POST', '/form?password=hunter2', query: ['password' => 'hunter2'], server: $server($json), rawBody: '{"a":"b"}');
    RequestLogger::write($post, $app->dispatch($post));
    $file = $workspace . '/logs/' . RequestLogger::currentDate() . '.log';
    $entries = array_map(fn(string $line) => json_decode($line, true), LogFiles::tail($file, 2));
    expect(($entries[1]['user_id'] ?? null) === 7);
    expect(!str_contains((string)file_get_contents($file), 'hunter2'));
    expect((fileperms($file) & 0777) === 0640, decoct(fileperms($file) & 0777));
    expect(Redactor::redact(['new_password' => 'x', 'otp' => '1', 'shipping' => 'ok'])
        === ['new_password' => '[redacted]', 'otp' => '[redacted]', 'shipping' => 'ok']);
});
test('log tail pages newest first; prune keeps foreign files', function () use ($workspace) {
    $file = $workspace . '/lines.log';
    file_put_contents($file, implode("\n", array_map(fn(int $i) => "line{$i}", range(1, 50000))) . "\n");
    expect(LogFiles::tail($file, 3, 2) === ['line49998', 'line49997', 'line49996']);
    expect(LogFiles::countLines($file) === 50000);
    touch($workspace . '/logs/2000-01-01.log');
    touch($workspace . '/logs/other-app-2000-01-01.log');
    LogFiles::prune($workspace . '/logs', 30, new DateTimeImmutable());
    expect(!is_file($workspace . '/logs/2000-01-01.log'));
    expect(is_file($workspace . '/logs/other-app-2000-01-01.log'));
});

// Validation
test('unknown-key check stays fast and bounded', function () {
    $rules = ['x' => 'optional|list|max:100', 'x.*' => 'object', 'x.*.z' => 'optional|string'];
    $started = microtime(true);
    expectThrows(ValidationException::class, fn() => Validator::validate(['x' => array_fill(0, 20000, ['q' => 1])], $rules));
    expect(microtime(true) - $started < 1.0, 'took ' . round(microtime(true) - $started, 2) . 's');
    $error = expectThrows(ValidationException::class, fn() => Validator::validate(
        array_fill_keys(array_map(fn(int $i) => "k{$i}", range(1, 900)), 1),
        ['name' => 'optional|string'],
    ));
    $errors = $error->errors();
    expect(count($errors) === 51 && end($errors) === ['Additional errors were omitted.'], json_encode($errors));
});
test('failed parents skip children; wildcard cap; strict distinct', function () {
    $error = expectThrows(ValidationException::class, fn() => Validator::validate(
        ['items' => array_fill(0, 50, 1)],
        ['items' => 'required|list|max:2', 'items.*' => 'required|integer'],
    ));
    expect(count($error->errors()) === 1, json_encode($error->errors()));
    expectThrows(ValidationException::class, fn() => Validator::validate(['items' => range(1, 1001)], ['items' => 'list', 'items.*' => 'integer']));
    $error = expectThrows(ValidationException::class, fn() => Validator::validate(['x' => [1, '1', 1]], ['x' => 'list', 'x.*' => 'distinct']));
    expect(count($error->errors()) === 1);
});
test('required_if compares the normalized referenced value', function () {
    expectThrows(ValidationException::class, fn() => Validator::validate(
        ['kind' => '01'],
        ['kind' => 'required|integer', 'approval' => 'required_if:kind,1|string'],
    ));
});

// Authentication
test('JWT carries kid, survives rotation, rejects tampering and bad claim types', function () use ($config) {
    JWT::configure($config['jwt']);
    $token = JWT::encode(['sub' => '1', 'sv' => 1]);
    expect(JWT::decode($token)['sub'] === '1');
    JWT::configure([...$config['jwt'], 'kid' => 'next', 'secret' => str_repeat('b', 64), 'previous_keys' => ['primary' => $config['jwt']['secret']]]);
    expect(JWT::decode($token)['sub'] === '1');
    $parts = explode('.', $token);
    $parts[1] = rtrim(strtr(base64_encode('{"x":1}'), '+/', '-_'), '=');
    expect(expectThrows(JWTException::class, fn() => JWT::decode(implode('.', $parts)))->getMessage() === 'Invalid JWT signature.');
    expectThrows(JWTException::class, fn() => JWT::decode(JWT::encode(['sub' => ['1'], 'sv' => 1])));
    JWT::configure($config['jwt']);
});
test('NUL bytes in passwords are rejected as invalid input', function () {
    expectThrows(ValidationException::class, fn() => Password::hash("abcdefgh\0"));
    expect(!Password::verify("abc\0", Password::hash('abcdefgh')));
});

// Database (SQLite)
test('Model binds booleans, hides columns, returns ISO dates; transactions roll back', function () use ($workspace, $config) {
    Database::configure(['default' => 'lite', 'connections' => ['lite' => ['driver' => 'sqlite', 'database' => $workspace . '/t.sqlite']]]);
    Database::connection()->exec('CREATE TABLE things (id INTEGER PRIMARY KEY, name TEXT, secret TEXT, active INTEGER NOT NULL, created_at TEXT)');
    $things = new class extends Model {
        protected string $table = 'things';
        protected array $fillable = ['name', 'secret', 'active', 'created_at'];
        protected array $hidden = ['secret'];
    };
    $row = $things->create(['name' => 'a', 'secret' => 's', 'active' => false, 'created_at' => '2026-10-13 08:15:00']);
    expect($row['active'] === 0 && !array_key_exists('secret', $row) && $row['created_at'] === '2026-10-13T08:15:00Z', json_encode($row));
    try {
        Database::transaction(function () use ($things): void {
            $things->create(['name' => 'b', 'active' => true]);
            throw new DomainException('rollback');
        });
    } catch (DomainException) {
    }
    expect($things->where('name', 'b') === []);
    Database::configure($config['databases']);
});

// Uploads, mail, generator
test('uploader fails closed and checks Office structure', function () use ($workspace) {
    $uploader = new FileUploader();
    expectThrows(RuntimeException::class, fn() => invokePrivate($uploader, 'validateExtension', 'jpg', []));
    expectThrows(RuntimeException::class, fn() => invokePrivate($uploader, 'validateExtension', 'php', ['php']));
    expectThrows(RuntimeException::class, fn() => invokePrivate($uploader, 'validateMIMEType', 'jpg', 'image/jpeg', []));
    $zip = new ZipArchive();
    $zip->open($workspace . '/fake.docx', ZipArchive::CREATE);
    $zip->addFromString('a.txt', 'x');
    $zip->close();
    expectThrows(RuntimeException::class, fn() => invokePrivate($uploader, 'validateOfficeArchive', 'docx', 'application/zip', $workspace . '/fake.docx'));
});
test('SMTP folds headers, requires TLS for AUTH, avoids TLS 1.0/1.1', function () {
    $mailer = new SMTPMailer();
    $lines = explode("\r\n", invokePrivate($mailer, 'encodeHeader', str_repeat('سلام دنیا ', 20)));
    expect(count($lines) > 1 && max(array_map('strlen', $lines)) <= 76);
    SMTPMailer::configure(['enabled' => true, 'from_address' => 'a@example.com', 'mailers' => ['x' => ['host' => 'h', 'encryption' => 'none', 'username' => 'u']]]);
    expectThrows(RuntimeException::class, fn() => invokePrivate($mailer, 'configuration', 'x'));
    $methods = (new ReflectionClassConstant(SMTPMailer::class, 'TLS_METHODS'))->getValue();
    expect(($methods & STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT) !== STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT);
});
test('generator rejects reserved class names', function () use ($root) {
    $command = new MakeCommand($root);
    expectThrows(RuntimeException::class, fn() => invokePrivate($command, 'className', 'list'));
    expect(invokePrivate($command, 'className', 'Product', 'Controller') === 'ProductController');
});

$failed = 0;
foreach ($tests as $name => $body) {
    try {
        $body();
        echo "PASS  {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL  {$name}\n      " . $exception->getMessage() . "\n";
    }
}

$total = count($tests);
echo "\n" . ($total - $failed) . " of {$total} passed\n";
exit($failed === 0 ? 0 : 1);
