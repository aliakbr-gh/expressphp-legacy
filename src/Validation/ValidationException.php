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
     */
    private static function normalize(array $errors): array
    {
        if (array_is_list($errors)) {
            return $errors === [] ? [] : [self::REQUEST_FIELD => array_map('strval', $errors)];
        }

        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[(string)$field] = array_values(array_map('strval', is_array($messages) ? $messages : [$messages]));
        }
        return $normalized;
    }
}
