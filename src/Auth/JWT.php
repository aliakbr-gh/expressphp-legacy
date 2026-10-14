<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use JsonException;

/**
 * Minimal HS256 JSON Web Tokens: encode() signs claims with an issue time and expiry, and decode() returns the
 * claims only when the signature is valid and the token has not expired.
 */
final class JWT
{
    private static array $config = [];

    /**
     * Stores the signing secret and the token lifetime in seconds.
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Signs the claims as an HS256 token that expires after $ttl seconds (default: the configured lifetime).
     */
    public static function encode(array $claims, ?int $ttl = null): string
    {
        $now = time();
        $payload = [...$claims, 'iat' => $now, 'exp' => $now + max(1, $ttl ?? self::ttl())];

        try {
            $header = self::base64URLEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $body = self::base64URLEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new JWTException('JWT payload could not be encoded.', previous: $exception);
        }

        return $header . '.' . $body . '.' . self::base64URLEncode(self::sign($header . '.' . $body));
    }

    /**
     * Returns the token's claims, or throws JWTException when it is malformed, wrongly signed, or expired.
     */
    public static function decode(string $token): array
    {
        $segments = explode('.', trim($token));
        if (count($segments) !== 3) {
            throw new JWTException('Malformed JWT.');
        }

        [$header, $body, $signature] = $segments;
        // The signature is checked before anything in the token is trusted; only HS256 is accepted.
        if (!hash_equals(self::sign($header . '.' . $body), self::base64URLDecode($signature))
            || (self::decodeJSON($header)['alg'] ?? null) !== 'HS256') {
            throw new JWTException('Invalid JWT signature.');
        }

        $claims = self::decodeJSON($body);
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            throw new JWTException('JWT has expired.');
        }
        return $claims;
    }

    /**
     * Returns the token lifetime in seconds.
     */
    public static function ttl(): int
    {
        return (int)(self::$config['ttl'] ?? 604800);
    }

    /**
     * Returns the HMAC-SHA256 signature of the header and payload.
     */
    private static function sign(string $input): string
    {
        $secret = (string)(self::$config['secret'] ?? '');
        if (strlen($secret) < 32) {
            throw new JWTException('JWT secret must contain at least 32 bytes.');
        }
        return hash_hmac('sha256', $input, $secret, true);
    }

    /**
     * Decodes one Base64URL segment as a JSON object.
     */
    private static function decodeJSON(string $encoded): array
    {
        try {
            $decoded = json_decode(self::base64URLDecode($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new JWTException('Malformed JWT.');
        }
        if (!is_array($decoded)) {
            throw new JWTException('Malformed JWT.');
        }
        return $decoded;
    }

    /**
     * Encodes bytes as unpadded Base64URL.
     */
    private static function base64URLEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes unpadded Base64URL, rejecting any other characters.
     */
    private static function base64URLDecode(string $value): string
    {
        $decoded = preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1 ? base64_decode(strtr($value, '-_', '+/'), true) : false;
        if ($decoded === false) {
            throw new JWTException('Malformed JWT.');
        }
        return $decoded;
    }
}
