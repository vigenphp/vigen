<?php

declare(strict_types=1);

namespace Vigen\AI;

final class ValidationResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
    ) {
    }

    public static function ok(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function failed(array $errors): self
    {
        return new self(false, $errors);
    }
}
