<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use Closure;
use ExpressPHP\View\View;
use InvalidArgumentException;
use RuntimeException;

final class Response
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    // Served types come from the extension, never from sniffing file contents.
    private const FILE_TYPES = [
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private int $statusCode = 200;
    private array $headers = [];
    /** @var list<string> */
    private array $cookies = [];
    private string $body = '';
    private ?Closure $streamCallback = null;

    public function __construct(private readonly View $views = new View())
    {
    }

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function appendHeader(string $name, string $value): self
    {
        $current = $this->headers[$name] ?? null;
        $this->headers[$name] = $current === null ? $value : $current . ', ' . $value;
        return $this;
    }

    public function json(mixed $data, int $flags = 0): self
    {
        return $this->setBody(json_encode($data, self::JSON_FLAGS | $flags), 'application/json; charset=utf-8');
    }

    public function success(
        mixed  $data = null,
        string $message = 'Request successful',
        int    $status = 200,
    ): self {
        return $this->status($status)->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function error(
        string|array $message = 'Request failed',
        int          $status = 400,
    ): self {
        if (is_array($message) && count($message) === 1) {
            $message = reset($message);
        }

        return $this->status($status)->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ]);
    }

    public function text(string $text): self
    {
        return $this->setBody($text, 'text/plain; charset=utf-8');
    }

    public function html(string $html): self
    {
        return $this->setBody($html, 'text/html; charset=utf-8');
    }

    public function view(string $name, array $data = []): self
    {
        return $this->html($this->views->render($name, $data));
    }

    public function send(string $body, ?string $contentType = null): self
    {
        return $this->setBody($body, $contentType);
    }

    public function cookie(string $name, string $value, array $options = []): self
    {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];
        if (isset($options['expires'])) {
            $expires = $options['expires'] instanceof \DateTimeInterface
                ? $options['expires']->getTimestamp()
                : (int)$options['expires'];
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        }
        if (isset($options['max_age'])) {
            $parts[] = 'Max-Age=' . (int)$options['max_age'];
        }

        $path = (string)($options['path'] ?? '/');
        if (preg_match('/\A\/[\x21-\x3a\x3c-\x7e]*\z/', $path) !== 1) {
            throw new InvalidArgumentException('Cookie paths must start with / and contain no spaces or semicolons.');
        }
        $parts[] = 'Path=' . $path;

        if (isset($options['domain'])) {
            $domain = (string)$options['domain'];
            if (preg_match('/\A\.?[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $domain) !== 1) {
                throw new InvalidArgumentException('Cookie domains must be plain host names.');
            }
            $parts[] = 'Domain=' . $domain;
        }

        $sameSite = isset($options['same_site']) ? ucfirst(strtolower((string)$options['same_site'])) : null;
        if ($sameSite !== null && !in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('Cookie SameSite must be Lax, Strict, or None.');
        }
        // Browsers reject SameSite=None cookies that are not Secure.
        if (($options['secure'] ?? false) === true || $sameSite === 'None') {
            $parts[] = 'Secure';
        }
        if (($options['http_only'] ?? true) === true) {
            $parts[] = 'HttpOnly';
        }
        if ($sameSite !== null) {
            $parts[] = 'SameSite=' . $sameSite;
        }

        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    public function clearCookie(string $name, array $options = []): self
    {
        return $this->cookie($name, '', [...$options, 'expires' => 1, 'max_age' => 0]);
    }

    public function redirect(string $url, int $status = 302): self
    {
        return $this->status($status)->header('Location', $url)->html(
            '<!doctype html><title>Redirecting</title><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Redirecting</a>'
        );
    }

    public function attachment(string $filename): self
    {
        $safe = str_replace(["\r", "\n", '"', '\\'], '', basename($filename));
        return $this->header('Content-Disposition', 'attachment; filename="' . $safe . '"');
    }

    /**
     * Streams a file as a download. The type comes from the extension (or $contentType), never from
     * sniffing contents, and the response is sandboxed so user files cannot run script on this origin.
     */
    public function sendFile(
        string  $path,
        ?string $downloadName = null,
        ?string $contentType = null,
        bool    $inline = false,
    ): self {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('File not found', 404);
        }

        $extension = strtolower(pathinfo($downloadName ?? $path, PATHINFO_EXTENSION));
        $this->stream(static function () use ($path): void {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open response file.');
            }
            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
            }
        });

        $this->header('Content-Type', $contentType ?? self::FILE_TYPES[$extension] ?? 'application/octet-stream');
        $this->header('Content-Length', (string)filesize($path));
        $this->header('Content-Security-Policy', "default-src 'none'; sandbox");
        if ($inline) {
            $this->header('Content-Disposition', 'inline');
        } else {
            $this->attachment($downloadName ?? basename($path));
        }
        return $this;
    }

    public function download(string $path, ?string $filename = null): self
    {
        return $this->sendFile($path, $filename ?? basename($path));
    }

    /**
     * Replaces the body with a stream. Set Content-Length or Content-Disposition after calling this.
     */
    public function stream(callable $callback, string $contentType = 'application/octet-stream'): self
    {
        $this->setBody('', $contentType);
        $this->streamCallback = Closure::fromCallable($callback);
        return $this;
    }

    public function noContent(): self
    {
        $this->statusCode = 204;
        $this->setBody('');
        unset($this->headers['Content-Type']);
        return $this;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return list<string> Each value is emitted as its own Set-Cookie header.
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isStream(): bool
    {
        return $this->streamCallback !== null;
    }

    public function emit(bool $includeBody = true): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if (!$includeBody) {
            return;
        }
        if ($this->streamCallback !== null) {
            ($this->streamCallback)();
            return;
        }
        echo $this->body;
    }

    /**
     * Replaces the body and drops any stream and file headers from a previous body.
     */
    private function setBody(string $body, ?string $contentType = null): self
    {
        $this->streamCallback = null;
        unset($this->headers['Content-Length'], $this->headers['Content-Disposition'], $this->headers['Content-Security-Policy']);
        if ($contentType !== null) {
            $this->header('Content-Type', $contentType);
        }
        $this->body = $body;
        return $this;
    }
}
