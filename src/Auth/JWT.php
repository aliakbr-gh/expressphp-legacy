<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use JsonException;

final class JWT
{
    private const ALGORITHM = 'HS256';
    private const MAX_HEADER_BYTES = 512;
    private static array $config = [];

    /**
     * Stores the signing secret, key id, previous keys, issuer, audience, lifetime, and leeway.
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Signs the claims as an HS256 token, adding the issuer, audience, issue time, expiry, a random id, and the key
     * id.
     */
    public static function encode(array $claims, ?int $ttl = null): string
    {
        $secret = self::secret();
        $now = time();
        $ttl ??= self::ttl();

        if ($ttl <= 0) {
            throw new JWTException('JWT lifetime must be greater than zero.');
        }

        $payload = [
            ...$claims,
            'iss' => self::issuer(),
            'aud' => self::audience(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $header = ['typ' => 'JWT', 'alg' => self::ALGORITHM, 'kid' => self::keyId()];

        try {
            $encodedHeader = self::base64URLEncode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $encodedPayload = self::base64URLEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new JWTException('JWT payload could not be encoded.', previous: $exception);
        }

        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        return $signingInput . '.' . self::base64URLEncode($signature);
    }

    /**
     * Only tokens issued by this application are supported: HS256, typ JWT, and a known key id.
     * The payload is parsed only after the signature has been verified.
     */
    public static function decode(string $token): array
    {
        $segments = explode('.', trim($token));
        if (count($segments) !== 3 || in_array('', $segments, true) || strlen($segments[0]) > self::MAX_HEADER_BYTES) {
            throw new JWTException('Malformed JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $header = self::decodeJSON($encodedHeader, 'header');
        if (($header['typ'] ?? null) !== 'JWT' || ($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new JWTException('Unsupported JWT header.');
        }

        $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, self::secretFor($header['kid'] ?? null), true);
        if (!hash_equals($expected, self::base64URLDecode($encodedSignature))) {
            throw new JWTException('Invalid JWT signature.');
        }

        $payload = self::decodeJSON($encodedPayload, 'payload');
        self::validateClaims($payload);
        return $payload;
    }

    /**
     * Returns the token lifetime in seconds.
     */
    public static function ttl(): int
    {
        return (int)(self::$config['ttl'] ?? 3600);
    }

    /**
     * Checks that the required claims exist with the right types, the time window (with leeway), the issuer, and the
     * audience.
     */
    private static function validateClaims(array $payload): void
    {
        $now = time();
        $leeway = max(0, (int)(self::$config['leeway'] ?? 0));

        foreach (['iss', 'aud', 'sub', 'iat', 'nbf', 'exp', 'jti'] as $claim) {
            if (!array_key_exists($claim, $payload)) {
                throw new JWTException("Missing JWT claim [{$claim}].");
            }
        }
        foreach (['iat', 'nbf', 'exp'] as $claim) {
            if (!is_int($payload[$claim])) {
                throw new JWTException("JWT claim [{$claim}] must be an integer.");
            }
        }
        // Wrong types become JWTException (401), never a PHP conversion error (500).
        foreach (['iss', 'sub', 'jti'] as $claim) {
            if (!is_string($payload[$claim]) || $payload[$claim] === '') {
                throw new JWTException("JWT claim [{$claim}] must be a non-empty string.");
            }
        }
        $audiences = $payload['aud'];
        if (!is_string($audiences) && !(is_array($audiences) && array_is_list($audiences)
            && array_filter($audiences, 'is_string') === $audiences)) {
            throw new JWTException('JWT claim [aud] must be a string or a list of strings.');
        }
        if ($payload['exp'] <= $now - $leeway) {
            throw new JWTException('JWT has expired.');
        }
        if ($payload['nbf'] > $now + $leeway) {
            throw new JWTException('JWT is not active yet.');
        }
        if ($payload['iat'] > $now + $leeway) {
            throw new JWTException('JWT was issued in the future.');
        }
        if (!hash_equals(self::issuer(), $payload['iss'])) {
            throw new JWTException('Invalid JWT issuer.');
        }

        $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array(self::audience(), $audiences, true)) {
            throw new JWTException('Invalid JWT audience.');
        }
    }

    /**
     * Decodes one Base64URL segment as a JSON object.
     */
    private static function decodeJSON(string $encoded, string $part): array
    {
        try {
            $decoded = json_decode(self::base64URLDecode($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JWTException("Invalid JWT {$part} JSON.", previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new JWTException("JWT {$part} must be an object.");
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
     * Strictly decodes unpadded Base64URL, rejecting any other characters.
     */
    private static function base64URLDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new JWTException('Invalid Base64URL value in JWT.');
        }
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new JWTException('Invalid Base64URL encoding in JWT.');
        }
        return $decoded;
    }

    /**
     * Returns the current signing secret.
     */
    private static function secret(): string
    {
        return self::checkedSecret((string)(self::$config['secret'] ?? ''));
    }

    /**
     * Returns the key id written into new tokens.
     */
    private static function keyId(): string
    {
        return (string)(self::$config['kid'] ?? 'primary');
    }

    /**
     * Resolves the signing secret for a token's key id. Tokens without a kid predate key ids
     * and use the current secret; retired secrets stay valid while listed in previous_keys.
     */
    private static function secretFor(mixed $keyId): string
    {
        if ($keyId === null || $keyId === self::keyId()) {
            return self::secret();
        }

        $previous = self::$config['previous_keys'] ?? [];
        if (!is_string($keyId) || !is_array($previous) || !isset($previous[$keyId])) {
            throw new JWTException('Unknown JWT key.');
        }
        return self::checkedSecret((string)$previous[$keyId]);
    }

    /**
     * Rejects secrets shorter than 32 bytes.
     */
    private static function checkedSecret(#[\SensitiveParameter] string $secret): string
    {
        if (strlen($secret) < 32) {
            throw new JWTException('JWT secret must contain at least 32 bytes.');
        }
        return $secret;
    }

    /**
     * Returns the expected issuer.
     */
    private static function issuer(): string
    {
        return (string)(self::$config['issuer'] ?? 'expressphp');
    }

    /**
     * Returns the expected audience.
     */
    private static function audience(): string
    {
        return (string)(self::$config['audience'] ?? 'expressphp-api');
    }
}
