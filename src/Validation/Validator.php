<?php

declare(strict_types=1);

namespace ExpressPHP\Validation;

use DateTimeImmutable;
use ExpressPHP\Database\Database;
use RuntimeException;
use ValueError;

final class Validator
{
    // Upper bounds on work for one request: items under a single wildcard and fields expanded in total.
    private const MAX_WILDCARD_ITEMS = 1000;
    private const MAX_EXPANDED_FIELDS = 10000;
    // Error messages returned for one request; more are summarized so a large body cannot amplify the response.
    private const MAX_ERRORS = 50;

    /**
     * Validates input against rules and returns the normalized values. Throws ValidationException with errors keyed
     * by field.
     */
    public static function validate(
        array $input,
        array $rules,
        array $messages = [],
        array $JSONObjectPaths = [],
    ): array {
        $definitions = self::definitions($rules);
        // Parents are validated before their children so an invalid container skips its descendants.
        uksort($definitions, static fn(string|int $left, string|int $right): int
            => substr_count((string)$left, '.') <=> substr_count((string)$right, '.'));
        $objectPaths = array_fill_keys($JSONObjectPaths, true);
        $errors = [];
        $visited = 0;
        self::unknownKeyErrors($input, array_keys($definitions), '', $errors, $visited);
        $fields = [];
        $failed = [];
        $distinctValues = [];
        $databaseCache = [];
        $expanded = 0;

        foreach ($definitions as $pattern => $fieldRules) {
            $pattern = (string)$pattern;
            $paths = [];
            self::expandField($input, explode('.', $pattern), '', $paths);
            $expanded += count($paths);
            if ($expanded > self::MAX_EXPANDED_FIELDS) {
                throw new ValidationException(['The request contains too many fields.']);
            }

            foreach ($paths as $field) {
                if (self::hasFailedAncestor($failed, $field)) {
                    continue;
                }
                [$exists, $value] = self::get($input, $field);
                $required = self::requiredRule($input, $definitions, $fieldRules, $pattern, $field);
                if ($required !== null && (!$exists || self::empty($value))) {
                    self::addError($errors, $messages, $field, $pattern, $required[0], $required[1]);
                    $failed[$field] = true;
                    continue;
                }
                if (!$exists) {
                    continue;
                }
                if ($value === null && self::hasRule($fieldRules, 'nullable')) {
                    $fields[$field] = null;
                    continue;
                }

                $validField = true;
                foreach ($fieldRules as [$rule, $parameters]) {
                    if (self::isPresenceRule($rule) || $rule === 'nullable') {
                        continue;
                    }
                    $parameters = self::resolveReferences($rule, $parameters, $pattern, $field);
                    if ($rule === 'distinct') {
                        // serialize() keeps strict semantics: 1, "1", and 1.0 stay different.
                        $key = serialize($value);
                        $valid = !isset($distinctValues[$pattern][$key]);
                        $distinctValues[$pattern][$key] = true;
                        $normalized = $value;
                    } else {
                        [$valid, $normalized] = self::check($rule, $value, $parameters, $input, $field, $objectPaths, $databaseCache);
                    }
                    if (!$valid) {
                        self::addError($errors, $messages, $field, $pattern, $rule, $parameters);
                        $failed[$field] = true;
                        $validField = false;
                        break;
                    }
                    $value = $normalized;
                }
                if ($validField) {
                    $fields[$field] = $value;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException(self::limitErrors($errors));
        }

        // Write parents first so child normalization is independent of rule order.
        uksort($fields, static fn(string|int $left, string|int $right): int => substr_count((string)$left, '.') <=> substr_count((string)$right, '.'));
        $validated = [];
        foreach ($fields as $field => $value) {
            self::set($validated, (string)$field, $value);
        }
        return $validated;
    }

    /**
     * Reports whether a parent path of the field has already failed.
     */
    private static function hasFailedAncestor(array $failed, string $field): bool
    {
        $position = strrpos($field, '.');
        while ($position !== false) {
            $field = substr($field, 0, $position);
            if (isset($failed[$field])) {
                return true;
            }
            $position = strrpos($field, '.');
        }
        return false;
    }

    /**
     * Applies one rule to a value and returns [valid, normalized value].
     */
    private static function check(
        string $rule,
        mixed $value,
        array $parameters,
        array $input,
        string $field,
        array $objectPaths,
        array &$databaseCache,
    ): array {
        return match ($rule) {
            'string' => [is_string($value), $value],
            'integer' => self::integer($value),
            'numeric' => self::numeric($value),
            'boolean' => self::boolean($value),
            'array' => [is_array($value), $value],
            'object' => [is_array($value) && (!array_is_list($value) || isset($objectPaths[$field])), $value],
            'list' => [is_array($value) && array_is_list($value) && !isset($objectPaths[$field]), $value],
            'email' => [is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, $value],
            'ip' => [is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false, $value],
            'url' => [is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false, $value],
            'uuid' => [self::validUUID($value), $value],
            'min' => [self::size($value) >= self::numeric($parameters[0])[1], $value],
            'max' => [self::size($value) <= self::numeric($parameters[0])[1], $value],
            'between' => [self::size($value) >= self::numeric($parameters[0])[1]
                && self::size($value) <= self::numeric($parameters[1])[1], $value],
            'in' => [(is_scalar($value) || $value === null) && in_array((string)$value, $parameters, true), $value],
            'not_in' => [(is_scalar($value) || $value === null) && !in_array((string)$value, $parameters, true), $value],
            'same' => [self::comparison($input, $parameters[0]) === self::comparison($input, $field), $value],
            'different' => [self::comparison($input, $parameters[0]) !== self::comparison($input, $field), $value],
            'confirmed' => [self::comparison($input, $field . '_confirmation') === self::comparison($input, $field), $value],
            'regex' => [is_string($value) && @preg_match($parameters[0], $value) === 1, $value],
            'date' => [self::dateValue($value) !== null, $value],
            'date_format' => [self::validDateFormat($value, $parameters[0]), $value],
            'before', 'before_or_equal', 'after', 'after_or_equal' => [self::compareDates($rule, $value, $input, $parameters[0]), $value],
            'unique' => [self::databaseValueAllowed($value)
                && !self::cachedDatabaseValueExists($databaseCache, $value, $parameters, true), $value],
            'exists' => [self::databaseValueAllowed($value)
                && self::cachedDatabaseValueExists($databaseCache, $value, $parameters), $value],
            default => throw new RuntimeException("Unknown validation rule [{$rule}]."),
        };
    }

    /**
     * Accepts integers and integer strings within PHP's range, returning an int.
     */
    private static function integer(mixed $value): array
    {
        if (is_int($value)) {
            return [true, $value];
        }
        if (!is_string($value) || preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            return [false, $value];
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return [false, $value];
        }
        return [true, (int)$value];
    }

    /**
     * Accepts finite numbers and numeric strings, returning an int or float.
     */
    private static function numeric(mixed $value): array
    {
        if (is_int($value)) {
            return [true, $value];
        }
        if (is_float($value)) {
            return [is_finite($value), $value];
        }
        if (!is_string($value) || !is_numeric($value)) {
            return [false, $value];
        }
        $text = trim($value);
        [$integer, $normalized] = self::integer(str_starts_with($text, '+') ? substr($text, 1) : $text);
        if ($integer) {
            return [true, $normalized];
        }
        $normalized = (float)$text;
        return [is_finite($normalized), $normalized];
    }

    /**
     * Accepts boolean values and their usual string forms, returning a bool.
     */
    private static function boolean(mixed $value): array
    {
        if (!is_scalar($value) || (is_string($value) && trim($value) === '')) {
            return [false, $value];
        }
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return [$normalized !== null, $normalized ?? $value];
    }

    /**
     * Returns the measure used by min, max and between: the number itself, string length in bytes, or array count.
     */
    private static function size(mixed $value): int|float
    {
        return match (true) {
            is_int($value), is_float($value) => $value,
            is_string($value) => strlen($value),
            is_array($value) => count($value),
            default => -INF,
        };
    }

    /**
     * Reports whether a value is a UUID string.
     */
    private static function validUUID(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)\z/i', $value) === 1;
    }

    /**
     * Parses a real calendar date, rejecting relative expressions such as "tomorrow".
     */
    private static function dateValue(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            return null;
        }
        $parts = date_parse($value);
        if ($parts['error_count'] !== 0 || $parts['warning_count'] !== 0 || isset($parts['relative'])
            || $parts['year'] === false || $parts['month'] === false || $parts['day'] === false
            || !checkdate($parts['month'], $parts['day'], $parts['year'])) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Reports whether a value matches a date format exactly.
     */
    private static function validDateFormat(mixed $value, string $format): bool
    {
        if (!is_string($value) || str_contains($value, "\0")) {
            return false;
        }
        try {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        } catch (ValueError) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['error_count'] === 0 && $errors['warning_count'] === 0))
            && $date->format($format) === $value;
    }

    /**
     * Compares a date with another field or a fixed date, for the before and after rules.
     */
    private static function compareDates(string $rule, mixed $value, array $input, string $reference): bool
    {
        [$exists, $other] = self::get($input, $reference);
        $left = self::dateValue($value);
        $right = self::dateValue($exists ? $other : $reference);
        if ($left === null || $right === null) {
            return false;
        }
        return match ($rule) {
            'before' => $left < $right,
            'before_or_equal' => $left <= $right,
            'after' => $left > $right,
            'after_or_equal' => $left >= $right,
        };
    }

    /**
     * Returns the first presence rule that makes the field required, or null.
     */
    private static function requiredRule(array $input, array $definitions, array $rules, string $pattern, string $field): ?array
    {
        foreach ($rules as [$rule, $parameters]) {
            $parameters = self::resolveReferences($rule, $parameters, $pattern, $field);
            if ($rule === 'required') {
                return [$rule, $parameters];
            }
            if ($rule === 'required_if' || $rule === 'required_unless') {
                [$exists, $other] = self::conditionValue($input, $definitions, $parameters[0]);
                $matches = $exists && self::matchesCondition($other, array_slice($parameters, 1));
                if (($rule === 'required_if' && $matches) || ($rule === 'required_unless' && !$matches)) {
                    return [$rule, $parameters];
                }
            }
            if ($rule === 'required_with' || $rule === 'required_without') {
                foreach ($parameters as $reference) {
                    [$exists, $other] = self::get($input, $reference);
                    $present = $exists && !self::empty($other);
                    if (($rule === 'required_with' && $present) || ($rule === 'required_without' && !$present)) {
                        return [$rule, $parameters];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Reads a referenced field normalized by its own type rule, so "01" and 1 match a condition on 1.
     */
    private static function conditionValue(array $input, array $definitions, string $path): array
    {
        [$exists, $value] = self::get($input, $path);
        if (!$exists) {
            return [false, null];
        }

        foreach (self::definitionFor($definitions, $path) as [$rule]) {
            if ($rule === 'integer' || $rule === 'numeric' || $rule === 'boolean') {
                [$valid, $normalized] = self::$rule($value);
                return [true, $valid ? $normalized : $value];
            }
        }
        return [true, $value];
    }

    /**
     * Returns the rules declared for a field path, matching * wildcards.
     */
    private static function definitionFor(array $definitions, string $path): array
    {
        $segments = explode('.', $path);
        foreach ($definitions as $pattern => $rules) {
            $patternSegments = explode('.', (string)$pattern);
            if (count($patternSegments) !== count($segments)) {
                continue;
            }
            foreach ($patternSegments as $index => $segment) {
                if ($segment !== '*' && $segment !== $segments[$index]) {
                    continue 2;
                }
            }
            return $rules;
        }
        return [];
    }

    /**
     * Reports whether a value matches any of the required_if or required_unless values.
     */
    private static function matchesCondition(mixed $value, array $expected): bool
    {
        foreach ($expected as $candidate) {
            if ($value === null && $candidate === 'null') {
                return true;
            }
            if ($candidate === 'true' || $candidate === 'false') {
                [$valid, $boolean] = self::boolean($value);
                if ($valid && $boolean === ($candidate === 'true')) {
                    return true;
                }
            }
            if (is_bool($value) && in_array($candidate, $value ? ['true', '1'] : ['false', '0'], true)) {
                return true;
            }
            if ((is_string($value) || is_int($value) || is_float($value)) && (string)$value === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reports whether a rule controls presence rather than the value.
     */
    private static function isPresenceRule(string $rule): bool
    {
        return in_array($rule, ['required', 'optional', 'sometimes', 'required_if', 'required_unless', 'required_with', 'required_without'], true);
    }

    /**
     * Reports whether a field's rules include the named rule.
     */
    private static function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as [$rule]) {
            if ($rule === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces * in referenced field names with the current item's indexes.
     */
    private static function resolveReferences(string $rule, array $parameters, string $pattern, string $field): array
    {
        $indexes = [];
        $segments = explode('.', $field);
        foreach (explode('.', $pattern) as $index => $segment) {
            if ($segment === '*') {
                $indexes[] = $segments[$index];
            }
        }
        $references = match ($rule) {
            'required_with', 'required_without' => array_keys($parameters),
            'required_if', 'required_unless', 'same', 'different', 'before', 'before_or_equal', 'after', 'after_or_equal' => [0],
            default => [],
        };
        foreach ($references as $parameter) {
            $next = 0;
            $path = explode('.', $parameters[$parameter]);
            foreach ($path as &$segment) {
                if ($segment === '*' && isset($indexes[$next])) {
                    $segment = $indexes[$next++];
                }
            }
            unset($segment);
            $parameters[$parameter] = implode('.', $path);
        }
        return $parameters;
    }

    /**
     * Parses rule strings and arrays into [rule, parameters] pairs, rejecting invalid definitions.
     */
    private static function definitions(array $rules): array
    {
        $definitions = [];
        foreach ($rules as $field => $fieldRules) {
            $field = (string)$field;
            self::assertFieldPath($field);
            if (!is_string($fieldRules) && !is_array($fieldRules)) {
                throw new RuntimeException("Validation rules for [{$field}] must be a string or an array of strings.");
            }
            $parsed = [];
            foreach (is_array($fieldRules) ? $fieldRules : self::splitRules($fieldRules) as $definition) {
                if (!is_string($definition) || $definition === '') {
                    throw new RuntimeException('Validation rules must be non-empty strings.');
                }
                [$name, $text] = array_pad(explode(':', $definition, 2), 2, '');
                $name = strtolower($name);
                $parameters = $text === '' ? [] : ($name === 'regex' || $name === 'date_format' ? [$text] : explode(',', $text));
                self::assertRule($name, $parameters, $field);
                $parsed[] = [$name, $parameters];
            }
            if ($parsed === []) {
                throw new RuntimeException("Validation rules for [{$field}] cannot be empty.");
            }
            $definitions[$field] = $parsed;
        }
        return $definitions;
    }

    /**
     * Splits a rule string on |, keeping any pipes inside regex patterns.
     */
    private static function splitRules(string $rules): array
    {
        $definitions = [];
        $length = strlen($rules);
        $start = 0;
        while ($start < $length) {
            if (strtolower(substr($rules, $start, 6)) === 'regex:') {
                $end = self::regexEnd($rules, $start + 6);
                while ($end < $length && $rules[$end] !== '|') {
                    $end++;
                }
            } else {
                $separator = strpos($rules, '|', $start);
                $end = $separator === false ? $length : $separator;
            }
            $definitions[] = substr($rules, $start, $end - $start);
            $start = $end + 1;
        }
        if ($rules === '' || str_ends_with($rules, '|')) {
            $definitions[] = '';
        }
        return $definitions;
    }

    /**
     * Returns the position just after a regex pattern's closing delimiter.
     */
    private static function regexEnd(string $rules, int $start): int
    {
        $delimiter = $rules[$start] ?? '';
        if ($delimiter === '' || ctype_alnum($delimiter) || ctype_space($delimiter) || $delimiter === '\\') {
            throw new RuntimeException('The regex validation rule requires a delimited PCRE pattern.');
        }
        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$delimiter] ?? $delimiter;
        $depth = 1;
        $inClass = false;
        for ($index = $start + 1, $length = strlen($rules); $index < $length; $index++) {
            $character = $rules[$index];
            if ($character === '\\') {
                $index++;
                continue;
            }
            if ($delimiter !== '[') {
                if ($character === '[') {
                    $inClass = true;
                } elseif ($character === ']') {
                    $inClass = false;
                }
            }
            if ($inClass) {
                continue;
            }
            if ($delimiter !== $closing && $character === $delimiter) {
                $depth++;
            } elseif ($character === $closing && --$depth === 0) {
                return $index + 1;
            }
        }
        throw new RuntimeException('The regex validation rule has an unclosed pattern delimiter.');
    }

    /**
     * Checks a rule's name, parameter count and parameters when the rules are defined.
     */
    private static function assertRule(string $rule, array $parameters, string $field): void
    {
        [$minimum, $maximum] = match ($rule) {
            'required', 'nullable', 'sometimes', 'optional', 'string', 'integer', 'numeric', 'boolean', 'array', 'object', 'list',
            'email', 'ip', 'url', 'confirmed', 'date', 'uuid', 'distinct' => [0, 0],
            'min', 'max', 'same', 'different', 'regex', 'date_format', 'before', 'before_or_equal', 'after', 'after_or_equal' => [1, 1],
            'between' => [2, 2],
            'in', 'not_in', 'required_with', 'required_without' => [1, PHP_INT_MAX],
            'required_if', 'required_unless' => [2, PHP_INT_MAX],
            'unique' => [2, 5],
            'exists' => [2, 3],
            default => throw new RuntimeException("Unknown validation rule [{$rule}]."),
        };
        if (count($parameters) < $minimum || count($parameters) > $maximum) {
            throw new RuntimeException("The [{$rule}] validation rule has an invalid number of parameters.");
        }
        if (in_array($rule, ['min', 'max', 'between'], true)) {
            foreach ($parameters as $parameter) {
                if (!self::numeric($parameter)[0]) {
                    throw new RuntimeException("The [{$rule}] validation rule requires finite numeric bounds.");
                }
            }
            if ($rule === 'between' && self::numeric($parameters[0])[1] > self::numeric($parameters[1])[1]) {
                throw new RuntimeException('The between validation rule requires its minimum to be no greater than its maximum.');
            }
        }
        if ($rule === 'regex' && @preg_match($parameters[0], '') === false) {
            throw new RuntimeException('The regex validation rule contains an invalid PCRE pattern.');
        }
        if ($rule === 'date_format' && str_contains($parameters[0], "\0")) {
            throw new RuntimeException('The date_format validation rule contains an invalid format.');
        }
        if (in_array($rule, ['same', 'different', 'required_if', 'required_unless'], true)) {
            self::assertFieldPath($parameters[0]);
        }
        if ($rule === 'required_with' || $rule === 'required_without') {
            foreach ($parameters as $parameter) {
                self::assertFieldPath($parameter);
            }
        }
        if ($rule === 'distinct' && !in_array('*', explode('.', $field), true)) {
            throw new RuntimeException('The distinct validation rule requires a wildcard field.');
        }
        if ($rule === 'unique' || $rule === 'exists') {
            if (!self::safeIdentifier($parameters[0]) || !self::safeIdentifier($parameters[1])
                || (isset($parameters[4]) && !self::safeIdentifier($parameters[4]))) {
                throw new RuntimeException('The unique and exists rules require safe table and column names.');
            }
        }
    }

    /**
     * Rejects empty segments, null bytes, partial wildcards and the reserved _request name in a field path.
     */
    private static function assertFieldPath(string $field): void
    {
        if ($field === '' || str_contains($field, "\0")) {
            throw new RuntimeException('Validation field paths must be non-empty strings without null bytes.');
        }
        foreach (explode('.', $field) as $segment) {
            if ($segment === '' || ($segment !== '*' && str_contains($segment, '*'))) {
                throw new RuntimeException("Invalid validation field path [{$field}].");
            }
        }
        // Request-level errors are reported under this key, so no field may use it.
        if (explode('.', $field)[0] === ValidationException::REQUEST_FIELD) {
            throw new RuntimeException('The field name [' . ValidationException::REQUEST_FIELD . '] is reserved.');
        }
    }

    /**
     * Expands a field pattern to every concrete path present in the input, enforcing the item limit.
     */
    private static function expandField(mixed $input, array $segments, string $prefix, array &$paths): void
    {
        if ($segments === []) {
            $paths[] = $prefix;
            return;
        }
        $segment = array_shift($segments);
        if ($segment === '*') {
            if (!is_array($input)) {
                return;
            }
            if (count($input) > self::MAX_WILDCARD_ITEMS) {
                throw new ValidationException([$prefix => [
                    'The ' . str_replace(['.', '_'], ' ', $prefix) . ' field may not contain more than '
                    . self::MAX_WILDCARD_ITEMS . ' items.',
                ]]);
            }
            foreach ($input as $key => $value) {
                self::expandField($value, $segments, $prefix === '' ? (string)$key : $prefix . '.' . $key, $paths);
            }
            return;
        }
        $value = is_array($input) && array_key_exists($segment, $input) ? $input[$segment] : null;
        self::expandField($value, $segments, $prefix === '' ? $segment : $prefix . '.' . $segment, $paths);
    }

    /**
     * Records the message for a failed rule, using custom messages and placeholders.
     */
    private static function addError(array &$errors, array $custom, string $field, string $pattern, string $rule, array $parameters): void
    {
        $template = $custom[$field . '.' . $rule] ?? $custom[$pattern . '.' . $rule]
            ?? $custom[$field] ?? $custom[$pattern] ?? self::defaultMessage($rule);
        $index = '';
        $segments = explode('.', $field);
        foreach (explode('.', $pattern) as $offset => $segment) {
            if ($segment === '*') {
                $index = $segments[$offset];
            }
        }
        $message = str_replace(
            [':attribute', ':min', ':max', ':values', ':other', ':index', ':position'],
            [str_replace(['.', '_'], ' ', $field), $parameters[0] ?? '', $parameters[1] ?? '', implode(', ', $parameters),
                $parameters[0] ?? '', $index, ctype_digit($index) ? (string)((int)$index + 1) : $index],
            $template,
        );
        $errors[$field][] = $message;
    }

    /**
     * Returns the default message template for a rule.
     */
    private static function defaultMessage(string $rule): string
    {
        return match ($rule) {
            'required', 'required_if', 'required_unless', 'required_with', 'required_without' => 'The :attribute field is required.',
            'string' => 'The :attribute field must be a string.',
            'integer' => 'The :attribute field must be an integer.',
            'numeric' => 'The :attribute field must be a finite number.',
            'boolean' => 'The :attribute field must be true or false.',
            'array' => 'The :attribute field must be an array.',
            'object' => 'The :attribute field must be an object.',
            'list' => 'The :attribute field must be a list.',
            'email' => 'The :attribute field must contain a valid email address.',
            'ip' => 'The :attribute field must contain a valid IP address.',
            'url' => 'The :attribute field must contain a valid URL.',
            'uuid' => 'The :attribute field must contain a valid UUID.',
            'min' => 'The :attribute field must be at least :min.',
            'max' => 'The :attribute field may not be greater than :min.',
            'between' => 'The :attribute field must be between :min and :max.',
            'in' => 'The :attribute field must be one of: :values.',
            'not_in' => 'The selected :attribute is invalid.',
            'same' => 'The :attribute field must match :other.',
            'different' => 'The :attribute field must differ from :other.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'distinct' => 'The :attribute field must not contain a duplicate value.',
            'regex' => 'The :attribute field format is invalid.',
            'date' => 'The :attribute field must contain a valid calendar date.',
            'date_format' => 'The :attribute field does not match the required format.',
            'before' => 'The :attribute field must be before :other.',
            'before_or_equal' => 'The :attribute field must be before or equal to :other.',
            'after' => 'The :attribute field must be after :other.',
            'after_or_equal' => 'The :attribute field must be after or equal to :other.',
            'unique' => 'The :attribute has already been taken.',
            'exists' => 'The selected :attribute does not exist.',
            default => 'The :attribute field is invalid.',
        };
    }

    private static function empty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    /**
     * Returns the raw input value at a path.
     */
    private static function comparison(array $input, string $field): mixed
    {
        [, $value] = self::get($input, $field);
        return $value;
    }

    /**
     * Returns [exists, value] for a dotted path.
     */
    private static function get(array $input, string $path): array
    {
        $value = $input;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }
        return [true, $value];
    }

    /**
     * Writes a value at a dotted path, creating parent arrays.
     */
    private static function set(array &$output, string $path, mixed $value): void
    {
        $target = &$output;
        foreach (explode('.', $path) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    /**
     * Keeps errors for at most MAX_ERRORS fields so a large invalid body cannot amplify the response.
     */
    private static function limitErrors(array $errors): array
    {
        if (count($errors) <= self::MAX_ERRORS) {
            return $errors;
        }
        $limited = array_slice($errors, 0, self::MAX_ERRORS, true);
        $limited[ValidationException::REQUEST_FIELD][] = 'Additional errors were omitted.';
        return $limited;
    }

    /**
     * Reports keys that no rule declares. Errors are appended by reference and the walk stops early,
     * so the cost stays linear in the input and bounded even when every key is unknown.
     */
    private static function unknownKeyErrors(array $input, array $rules, string $prefix, array &$errors, int &$visited): void
    {
        if (count($input) > self::MAX_WILDCARD_ITEMS) {
            $errors[$prefix === '' ? ValidationException::REQUEST_FIELD : $prefix][] =
                ($prefix === '' ? 'The request' : 'The ' . str_replace(['.', '_'], ' ', $prefix) . ' field')
                . ' may not contain more than ' . self::MAX_WILDCARD_ITEMS . ' items.';
            return;
        }

        foreach ($input as $key => $value) {
            // One error past the limit is kept so limitErrors() can report that more were omitted.
            if (count($errors) > self::MAX_ERRORS) {
                return;
            }
            if (++$visited > self::MAX_EXPANDED_FIELDS) {
                throw new ValidationException(['The request contains too many fields.']);
            }

            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (str_contains((string)$key, '.')) {
                $errors[$path][] = "Invalid key {$path} in request.";
                continue;
            }
            $segments = explode('.', $path);
            $exact = false;
            $hasChildren = false;
            foreach ($rules as $ruleField) {
                $ruleSegments = explode('.', (string)$ruleField);
                if (count($segments) > count($ruleSegments)) {
                    continue;
                }
                $matches = true;
                foreach ($segments as $index => $segment) {
                    if ($ruleSegments[$index] !== '*' && $ruleSegments[$index] !== $segment) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $exact = $exact || count($segments) === count($ruleSegments);
                    $hasChildren = $hasChildren || count($segments) < count($ruleSegments);
                }
            }
            if (!$exact && !$hasChildren) {
                $errors[$path][] = "Invalid key {$path} in request.";
            } elseif ($hasChildren) {
                if (is_array($value)) {
                    self::unknownKeyErrors($value, $rules, $path, $errors, $visited);
                } elseif ($value !== null || !$exact) {
                    $errors[$path][] = "The {$path} field must be an array or object.";
                }
            }
        }
    }

    /**
     * Reports whether a value can be compared in a database rule.
     */
    private static function databaseValueAllowed(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value));
    }

    /**
     * Runs a unique or exists check once per value within one validation.
     */
    private static function cachedDatabaseValueExists(array &$cache, mixed $value, array $parameters, bool $unique = false): bool
    {
        $key = serialize([$unique, $parameters, $value]);
        return $cache[$key] ??= self::databaseValueExists($value, $parameters, $unique);
    }

    /**
     * Runs a unique or exists query with bound values and validated identifiers.
     */
    private static function databaseValueExists(mixed $value, array $parameters, bool $unique = false): bool
    {
        [$table, $column] = $parameters;
        $connection = $parameters[2] ?? '';
        $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = :value";
        $bindings = ['value' => $value];
        // The exclusion is a literal rule parameter supplied by trusted controller code.
        if ($unique && isset($parameters[3]) && $parameters[3] !== '') {
            $idColumn = $parameters[4] ?? 'id';
            $sql .= " AND `{$idColumn}` <> :ignore_id";
            $bindings['ignore_id'] = $parameters[3];
        }
        $statement = Database::connection($connection === '' ? null : $connection)->prepare($sql . ' LIMIT 1');
        $statement->execute($bindings);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Reports whether a table or column name is a plain identifier.
     */
    private static function safeIdentifier(string $identifier): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) === 1;
    }
}
