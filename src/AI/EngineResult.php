<?php

declare(strict_types=1);

namespace Vigen\AI;

final class EngineResult
{
    /**
     * @param list<FileChange> $changes
     */
    public function __construct(
        public readonly string $prompt,
        public readonly TaskPlan $plan,
        public readonly array $changes,
        public readonly ValidationResult $validation,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->validation->passed;
    }
}
