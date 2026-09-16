<?php

declare(strict_types=1);

namespace Vigen\Http;

use RuntimeException;
use Vigen\Database\Connection;

/**
 * The validation engine behind Request::validate().
 *
 * Rules are written as a pipe-separated string - 'required|email|max:255' -
 * which is the form a language model reproduces most reliably, and the form
 * every PHP developer already knows.
 *
 * Two structural rules change how the rest are applied:
 *   - `sometimes` skips the field entirely when it was not submitted.
 *   - `nullable` accepts a blank value and skips the remaining rules.
 * A blank value on a field that is neither `required` nor `nullable` is also
 * skipped, so an optional field can be omitted.
 */
final class Validator
{
    /**
     * @param array<string, mixed>               $data
     * @param array<string, string|list<string>> $rules
     * @return array<string, mixed> the validated subset of $data
     *
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $list = self::ruleList($fieldRules);

            $present = array_key_exists($field, $data);
            $required = in_array('required', $list, true);
            $value = $present ? $data[$field] : null;

            if (in_array('sometimes', $list, true) && ! $present) {
                continue;
            }

            if (self::isBlank($value)) {
                if ($required) {
                    $errors[$field] = [self::message($field, 'required')];
                }

                continue;
            }

            $messages = [];

            foreach ($list as $rule) {
                $message = self::check($field, $value, $rule, $data);

                if ($message !== null) {
                    $messages[] = $message;
                }
            }

            if ($messages !== []) {
                $errors[$field] = $messages;

                continue;
            }

            $validated[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * @param string|list<string> $rules
     * @return list<string>
     */
    private static function ruleList(string|array $rules): array
    {
        $list = is_array($rules) ? $rules : explode('|', $rules);

        return array_values(array_filter(
            array_map(static fn (mixed $rule): string => trim((string) $rule), $list),
            static fn (string $rule): bool => $rule !== ''
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function check(string $field, mixed $value, string $rule, array $data): ?string
    {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            // Structural rules are applied by validate() itself.
            'required', 'sometimes', 'nullable' => null,

            'string' => is_scalar($value) ? null : self::message($field, 'string'),
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : self::message($field, 'email'),
            'url' => filter_var((string) $value, FILTER_VALIDATE_URL) !== false
                ? null
                : self::message($field, 'url'),
            'numeric' => is_numeric($value) ? null : self::message($field, 'numeric'),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null
                : self::message($field, 'integer'),
            'boolean' => in_array($value, [true, false, 1, 0, '1', '0'], true)
                ? null
                : self::message($field, 'boolean'),
            'date' => strtotime((string) $value) !== false ? null : self::message($field, 'date'),
            'alpha_dash' => preg_match('/^[A-Za-z0-9_-]+$/', (string) $value) === 1
                ? null
                : self::message($field, 'alpha_dash'),

            'min' => self::checkSize($field, $value, (float) $parameter, 'min'),
            'max' => self::checkSize($field, $value, (float) $parameter, 'max'),
            'size' => self::checkSize($field, $value, (float) $parameter, 'size'),

            'confirmed' => self::checkConfirmed($field, $value, $data),
            'in' => in_array((string) $value, self::options($parameter), true)
                ? null
                : self::message($field, 'in'),
            'not_in' => in_array((string) $value, self::options($parameter), true)
                ? self::message($field, 'not_in')
                : null,

            'unique' => self::checkUnique($field, $value, (string) $parameter),
            'exists' => self::checkExists($field, $value, (string) $parameter),

            default => throw new RuntimeException(sprintf(
                'Unknown validation rule [%s] on field [%s]. Supported rules: %s.',
                $name,
                $field,
                implode(', ', [
                    'required', 'sometimes', 'nullable', 'string', 'email', 'url', 'numeric',
                    'integer', 'boolean', 'date', 'alpha_dash', 'min', 'max', 'size',
                    'confirmed', 'in', 'not_in', 'unique', 'exists',
                ])
            )),
        };
    }

    /**
     * `min`, `max` and `size` measure length for text and magnitude for
     * numbers, which is what a developer writing 'min:8' on a password
     * expects and also what 'min:18' on an age expects.
     */
    private static function checkSize(string $field, mixed $value, float $limit, string $rule): ?string
    {
        $isNumeric = is_numeric($value);
        $size = $isNumeric ? (float) $value : (float) mb_strlen((string) $value);

        $passes = match ($rule) {
            'min' => $size >= $limit,
            'max' => $size <= $limit,
            default => $size === $limit,
        };

        if ($passes) {
            return null;
        }

        $formatted = $limit === floor($limit) ? (string) (int) $limit : (string) $limit;
        $unit = $isNumeric ? '' : ' characters';

        return self::message($field, $rule, $unit === '' ? $formatted : $formatted . $unit);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function checkConfirmed(string $field, mixed $value, array $data): ?string
    {
        $confirmation = $data[$field . '_confirmation'] ?? null;

        return (string) $confirmation === (string) $value ? null : self::message($field, 'confirmed');
    }

    private static function checkUnique(string $field, mixed $value, string $parameter): ?string
    {
        [$table, $column, $except] = self::parseTableRule($field, $parameter, 'unique');

        // The row being updated is not a duplicate of itself.
        $exceptId = $except !== null && $except !== '' ? $except : null;

        return self::countRows($table, $column, $value, $exceptId) > 0
            ? self::message($field, 'unique')
            : null;
    }

    private static function checkExists(string $field, mixed $value, string $parameter): ?string
    {
        [$table, $column] = self::parseTableRule($field, $parameter, 'exists');

        return self::countRows($table, $column, $value, null) > 0
            ? null
            : self::message($field, 'exists');
    }

    /**
     * Parse "table", "table,column" or "table,column,exceptId". The column
     * defaults to the field name, so 'unique:users' on an $email field
     * checks users.email.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function parseTableRule(string $field, string $parameter, string $rule): array
    {
        $parts = array_map('trim', explode(',', $parameter));

        if ($parts[0] === '') {
            throw new RuntimeException(sprintf(
                'The [%s] rule on field [%s] needs a table name, e.g. "%s:users,%s".',
                $rule,
                $field,
                $rule,
                $field
            ));
        }

        return [$parts[0], $parts[1] ?? $field, $parts[2] ?? null];
    }

    private static function countRows(string $table, string $column, mixed $value, ?string $exceptId): int
    {
        $connection = Connection::current();

        $sql = sprintf(
            'select count(*) as aggregate from %s where %s = ?',
            $connection->quoteIdentifier($table),
            $connection->quoteIdentifier($column)
        );

        $bindings = [$value];

        if ($exceptId !== null) {
            $sql .= ' and ' . $connection->quoteIdentifier('id') . ' <> ?';
            $bindings[] = $exceptId;
        }

        $rows = $connection->select($sql, $bindings);

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    /**
     * @return list<string>
     */
    private static function options(?string $parameter): array
    {
        return array_map('trim', explode(',', (string) $parameter));
    }

    private static function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return is_array($value) && $value === [];
    }

    private static function message(string $field, string $rule, ?string $parameter = null): string
    {
        $label = str_replace(['_', '.'], ' ', $field);

        return match ($rule) {
            'required' => "The {$label} field is required.",
            'string' => "The {$label} field must be a string.",
            'email' => "The {$label} field must be a valid email address.",
            'url' => "The {$label} field must be a valid URL.",
            'numeric' => "The {$label} field must be a number.",
            'integer' => "The {$label} field must be a whole number.",
            'boolean' => "The {$label} field must be true or false.",
            'date' => "The {$label} field must be a valid date.",
            'alpha_dash' => "The {$label} field may only contain letters, numbers, dashes and underscores.",
            'confirmed' => "The {$label} confirmation does not match.",
            'in' => "The selected {$label} is invalid.",
            'not_in' => "The selected {$label} is invalid.",
            'unique' => "The {$label} has already been taken.",
            'exists' => "The selected {$label} is invalid.",
            'min' => "The {$label} field must be at least {$parameter}.",
            'max' => "The {$label} field must not be greater than {$parameter}.",
            'size' => "The {$label} field must be exactly {$parameter}.",
            default => "The {$label} field is invalid.",
        };
    }
}
