<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use ExpressPHP\Validation\ValidationException;
use ExpressPHP\Validation\Validator;
use JsonException;
use stdClass;

final class Request
{
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private array $params = [];
    private bool $jsonDecoded = false;
    private mixed $decodedJSON = null;
    private array $JSONObjectPaths = [];
    private ?array $parsedForm = null;
    // Shared by reference between clones made by withParams(), so the authenticated user and
    // request id set during routing stay visible to the code that created the request.
    private readonly stdClass $context;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array  $query = [],
        private readonly array  $form = [],
        private readonly array  $server = [],
        private readonly array  $files = [],
        private readonly array  $cookies = [],
        private readonly string $rawBody = '',
        private readonly string $basePath = '',
        private readonly array  $trustedProxies = [],
        private readonly bool   $bodyTooLarge = false,
    ) {
        $this->context = new stdClass();
        $this->context->user = null;
        $this->context->requestId = null;
    }

    public static function capture(array $trustedProxies = [], int $maxBodyBytes = 0): self
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/.');
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        // Multipart bodies are parsed by PHP and bounded by post_max_size and upload_max_filesize.
        $limited = $maxBodyBytes > 0 && !str_contains($contentType, 'multipart/form-data');
        $tooLarge = $limited && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBodyBytes;
        $rawBody = '';

        if (!$tooLarge) {
            $rawBody = $limited
                ? file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1)
                : file_get_contents('php://input');
            $rawBody = $rawBody === false ? '' : $rawBody;
            if ($limited && strlen($rawBody) > $maxBodyBytes) {
                $tooLarge = true;
                $rawBody = '';
            }
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $tooLarge ? [] : $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
            $rawBody,
            $basePath === '' ? '' : '/' . trim($basePath, '/'),
            $trustedProxies,
            $tooLarge,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH) ?: '/';

        if ($this->basePath !== '' && ($path === $this->basePath || str_starts_with($path, $this->basePath . '/'))) {
            $path = substr($path, strlen($this->basePath)) ?: '/';
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function bodyTooLarge(): bool
    {
        return $this->bodyTooLarge;
    }

    /**
     * A per-request identifier for correlating logs. A client value is accepted only when it is short and plain.
     */
    public function requestId(): string
    {
        if ($this->context->requestId === null) {
            $supplied = $this->header('X-Request-ID');
            $this->context->requestId = $supplied !== null && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $supplied) === 1
                ? $supplied
                : bin2hex(random_bytes(8));
        }
        return $this->context->requestId;
    }

    public function text(): string
    {
        return $this->rawBody;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonDecoded) {
            try {
                // Decode once as objects so JSON objects can be told apart from lists, then convert to arrays.
                $this->decodedJSON = $this->rawBody === ''
                    ? []
                    : $this->JSONToArray(json_decode($this->rawBody, false, 512, JSON_THROW_ON_ERROR), '');
            } catch (JsonException) {
                throw new HttpException('Malformed JSON request body', 400);
            }
            $this->jsonDecoded = true;
        }

        if ($key === null) {
            return $this->decodedJSON;
        }

        return is_array($this->decodedJSON) ? ($this->decodedJSON[$key] ?? $default) : $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->isJSON() ? $this->json() : $this->formData();

        if ($key === null) {
            return $data;
        }

        return is_array($data) ? ($data[$key] ?? $default) : $default;
    }

    /**
     * Validates the query string for GET-style requests and the body for POST, PUT, and PATCH.
     * Values are never read from both, so secrets cannot be accepted from the URL on write requests.
     */
    public function validate(array $rules, array $messages = []): array
    {
        if (!in_array($this->method, self::BODY_METHODS, true)) {
            if ($this->hasBody()) {
                throw new ValidationException(["A request body is not allowed for {$this->method} requests."]);
            }
            return Validator::validate($this->query, $rules, $messages);
        }

        if ($this->query !== []) {
            throw new ValidationException(array_map(
                fn(string|int $key): string => "Query parameter {$key} is not allowed for {$this->method} requests.",
                array_keys($this->query),
            ));
        }

        $input = $this->input();
        return Validator::validate(
            is_array($input) ? $input : [],
            $rules,
            $messages,
            $this->isJSON() ? array_keys($this->JSONObjectPaths) : [],
        );
    }

    public function validateParams(array $rules, array $messages = []): array
    {
        return Validator::validate(array_replace_recursive($this->query, $this->params), $rules, $messages);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function param(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->params : ($this->params[$key] ?? $default);
    }

    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (strtolower($name) === 'content-type') {
            $key = 'CONTENT_TYPE';
        }

        if (strtolower($name) === 'authorization') {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $authorizationKey) {
                if (isset($this->server[$authorizationKey]) && $this->server[$authorizationKey] !== '') {
                    return (string)$this->server[$authorizationKey];
                }
            }
        }

        return isset($this->server[$key]) ? (string)$this->server[$key] : $default;
    }

    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string)$value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($this->server[$key])) {
                $headers[$name] = (string)$this->server[$key];
            }
        }
        return $headers;
    }

    public function isJSON(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '') ?? ''), 'application/json');
    }

    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Returns the client address. Behind trusted proxies, X-Forwarded-For is read from the right and the
     * first address that is not a trusted proxy is used, because entries further left are client-controlled.
     */
    public function ip(): string
    {
        $remote = IPAddress::normalize((string)($this->server['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
        if (!$this->fromTrustedProxy()) {
            return $remote;
        }

        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded === null) {
            return $remote;
        }

        $client = $remote;
        foreach (array_reverse(explode(',', $forwarded)) as $hop) {
            $address = IPAddress::normalize($hop);
            if ($address === null) {
                break;
            }
            $client = $address;
            if (!IPAddress::inAny($address, $this->trustedProxies)) {
                return $address;
            }
        }
        return $client;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');
        return $authorization !== null && preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1
            ? trim($match[1])
            : null;
    }

    public function basicCredentials(): ?array
    {
        if (isset($this->server['PHP_AUTH_USER'], $this->server['PHP_AUTH_PW'])) {
            return [
                'username' => (string)$this->server['PHP_AUTH_USER'],
                'password' => (string)$this->server['PHP_AUTH_PW'],
            ];
        }

        $authorization = $this->header('Authorization');
        if ($authorization === null || preg_match('/^Basic\s+(.+)$/i', $authorization, $match) !== 1) {
            return null;
        }

        $decoded = base64_decode(trim($match[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);
        return ['username' => $username, 'password' => $password];
    }

    public function setUser(array $user): self
    {
        $this->context->user = $user;
        return $this;
    }

    public function user(): ?array
    {
        return $this->context->user;
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    public function protocol(): string
    {
        if ($this->fromTrustedProxy()) {
            $forwarded = $this->header('X-Forwarded-Proto');
            if ($forwarded !== null) {
                // The right-most value was set by the nearest proxy, which is the one we trust.
                $values = explode(',', $forwarded);
                $protocol = strtolower(trim((string)end($values)));
                if ($protocol === 'https' || $protocol === 'http') {
                    return $protocol;
                }
            }
        }
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    public function secure(): bool
    {
        return $this->protocol() === 'https';
    }

    public function host(): string
    {
        return $this->header('Host', (string)($this->server['SERVER_NAME'] ?? 'localhost')) ?? 'localhost';
    }

    public function hostname(): string
    {
        return explode(':', $this->host())[0];
    }

    public function url(): string
    {
        return $this->protocol() . '://' . $this->host() . $this->uri;
    }

    public function originalURL(): string
    {
        return $this->uri;
    }

    public function accepts(string ...$types): string|false
    {
        $accept = strtolower($this->header('Accept', '*/*') ?? '*/*');
        foreach ($types as $type) {
            $candidate = strtolower($type);
            $mime = str_contains($candidate, '/') ? $candidate : match ($candidate) {
                'json' => 'application/json',
                'html' => 'text/html',
                'text' => 'text/plain',
                default => $candidate,
            };
            if ($accept === '*/*' || str_contains($accept, $mime) || str_contains($accept, strtok($mime, '/') . '/*')) {
                return $type;
            }
        }
        return false;
    }

    private function fromTrustedProxy(): bool
    {
        return $this->trustedProxies !== []
            && IPAddress::inAny((string)($this->server['REMOTE_ADDR'] ?? ''), $this->trustedProxies);
    }

    private function hasBody(): bool
    {
        return $this->form !== [] || !in_array(trim($this->rawBody), ['', '{}', '[]'], true);
    }

    private function JSONToArray(mixed $value, string $path): mixed
    {
        if ($value instanceof stdClass) {
            if ($path !== '') {
                $this->JSONObjectPaths[$path] = true;
            }
            $value = get_object_vars($value);
        } elseif (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->JSONToArray($child, $path === '' ? (string)$key : $path . '.' . $key);
        }
        return $value;
    }

    private function formData(): array
    {
        if ($this->form !== []) {
            return $this->form;
        }
        if ($this->parsedForm !== null) {
            return $this->parsedForm;
        }
        $this->parsedForm = [];
        $contentType = strtolower($this->header('Content-Type', '') ?? '');
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody, $this->parsedForm);
        }
        return $this->parsedForm;
    }
}
