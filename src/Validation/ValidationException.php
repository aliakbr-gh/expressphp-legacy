<?php

declare(strict_types=1);

namespace ExpressPHP\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public const MESSAGE = 'Invalid Data';
    // Key for errors that belong to the request as a whole rather than one field.
    public const REQUEST_FIELD = '_request';

    /** @var array<string, list<string>> */
    private readonly array $errors;

    /**
     * Accepts errors keyed by field, such as ['email' => ['The email field is required.']].
     * A plain list of messages is treated as request-level errors.
     */
    public function __construct(array $errors)
    {
        parent::__construct(self::MESSAGE);
        $this->errors = self::normalize($errors);
    }

    /**
     * Returns the errors keyed by field name; request-level errors use the _request key.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Converts errors to field => list of messages. A plain list of messages becomes request-level errors.
     * Response::error() uses the same conversion, so every error response has one shape.
     *
     * @return array<string, list<string>>
     */
    public static function normalize(array $errors): array
    {
        if ($errors === []) {
            return [];
        }
        // A list of messages is request-level. A list whose values are lists is a field map whose
        // field names happen to be numeric, such as ['0' => [...]], which PHP stores with integer keys.
        if (array_is_list($errors) && array_filter($errors, 'is_array') === []) {
            return [self::REQUEST_FIELD => array_map(self::message(...), $errors)];
        }

        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[(string)$field] = array_map(self::message(...), array_values(is_array($messages) ? $messages : [$messages]));
        }
        return $normalized;
    }

    /**
     * Converts one error message to a string; non-scalar values are JSON-encoded instead of becoming "Array".
     */
    private static function message(mixed $message): string
    {
        return is_scalar($message) || $message === null
            ? (string)$message
            : (string)json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
