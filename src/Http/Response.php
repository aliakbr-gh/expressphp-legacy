<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use Closure;
use ExpressPHP\Validation\ValidationException;
use ExpressPHP\View\View;
use InvalidArgumentException;
use RuntimeException;

final class Response
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    // Default policy for rendered views: no inline scripts or styles, no framing, no foreign form targets.
    private const VIEW_CSP = "default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

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

    /**
     * Creates a response. The View renders templates for view().
     */
    public function __construct(private readonly View $views = new View())
    {
    }

    /**
     * Sets the HTTP status code.
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Sets a header, replacing any earlier value.
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Appends a value to a comma-separated header such as Vary.
     */
    public function appendHeader(string $name, string $value): self
    {
        $current = $this->headers[$name] ?? null;
        $this->headers[$name] = $current === null ? $value : $current . ', ' . $value;
        return $this;
    }

    /**
     * Sets a JSON body. Invalid UTF-8 is replaced instead of failing.
     */
    public function json(mixed $data, int $flags = 0): self
    {
        return $this->setBody(json_encode($data, self::JSON_FLAGS | $flags), 'application/json; charset=utf-8');
    }

    /**
     * Sends {"success": true, "message", "data"} with the given status.
     */
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

    /**
     * Error responses always have the same shape, so typed mobile decoders never see a field change type:
     * {"success": false, "message": string, "errors": {field: [messages]}, "data": null}.
     *
     * @param array<string, list<string>> $errors
     */
    public function error(
        string $message = 'Request failed',
        int    $status = 400,
        array  $errors = [],
        mixed  $data = null,
    ): self {
        return $this->status($status)->json([
            'success' => false,
            'message' => $message,
            // Always a JSON object of string lists, including when empty or when a field name is numeric.
            'errors' => (object)ValidationException::normalize($errors),
            'data' => $data,
        ]);
    }

    /**
     * Sets a plain-text body.
     */
    public function text(string $text): self
    {
        return $this->setBody($text, 'text/plain; charset=utf-8');
    }

    /**
     * Sets an HTML body.
     */
    public function html(string $html): self
    {
        return $this->setBody($html, 'text/html; charset=utf-8');
    }

    /**
     * Renders a template with a restrictive Content-Security-Policy. Call header() afterwards to relax it.
     */
    public function view(string $name, array $data = []): self
    {
        return $this->html($this->views->render($name, $data))->header('Content-Security-Policy', self::VIEW_CSP);
    }

    /**
     * Sets a raw body with an optional content type.
     */
    public function send(string $body, ?string $contentType = null): self
    {
        return $this->setBody($body, $contentType);
    }

    /**
     * Adds a Set-Cookie header after validating the path, domain and SameSite value. SameSite=None forces Secure.
     */
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

    /**
     * Expires a cookie in the browser.
     */
    public function clearCookie(string $name, array $options = []): self
    {
        return $this->cookie($name, '', [...$options, 'expires' => 1, 'max_age' => 0]);
    }

    /**
     * Redirects to a path on this site, such as "/dashboard". Pass $allowExternal = true for another host;
     * never pass a client-supplied value that way, or the redirect can send users to an attacker's site.
     */
    public function redirect(string $url, int $status = 302, bool $allowExternal = false): self
    {
        // "//host" and "/\\host" are treated by browsers as links to another host, so only "/path" passes.
        if (!$allowExternal && preg_match('#\A/(?![/\\\\])#', $url) !== 1) {
            throw new InvalidArgumentException('Redirects must target a path on this site, such as /dashboard.');
        }
        return $this->status($status)->header('Location', $url)->html(
            '<!doctype html><title>Redirecting</title><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Redirecting</a>'
        );
    }

    /**
     * Sends an ASCII filename for old clients plus an RFC 5987 UTF-8 filename* for names such as "رپورٹ.pdf".
     */
    public function attachment(string $filename): self
    {
        $name = str_replace(["\r", "\n", "\0"], '', basename(str_replace('\\', '/', $filename)));
        $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name) ?? 'download';
        $value = 'attachment; filename="' . ($ascii === '' ? 'download' : $ascii) . '"';
        if ($ascii !== $name) {
            $value .= "; filename*=UTF-8''" . rawurlencode($name);
        }
        return $this->header('Content-Disposition', $value);
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

    /**
     * Sends a file as a download, named after the file unless a name is given.
     */
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

    /**
     * Sends 204 with no body.
     */
    public function noContent(): self
    {
        $this->statusCode = 204;
        $this->setBody('');
        unset($this->headers['Content-Type']);
        return $this;
    }

    /**
     * Returns the status code.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the headers other than Set-Cookie.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Returns the Set-Cookie values.
     *
     * @return list<string> Each value is emitted as its own Set-Cookie header.
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Returns the buffered body, or '' for a streamed response.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Reports whether the body is streamed.
     */
    public function isStream(): bool
    {
        return $this->streamCallback !== null;
    }

    /**
     * Sends the status, headers, cookies, and body or stream. HEAD requests omit the body.
     */
    public function emit(bool $includeBody = true): void
    {
        // Hide the PHP version that expose_php adds to every response.
        header_remove('X-Powered-By');
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
