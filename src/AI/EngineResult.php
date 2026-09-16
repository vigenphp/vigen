<?php

declare(strict_types=1);

namespace Vigen\AI;

use Vigen\Project\WriteResult;

final class EngineResult
{
    /**
     * @param list<FileChange> $changes The changes the model asked for.
     * @param list<WriteResult> $writes What actually happened on disk, per file.
     *   Empty during a dry run.
     * @param list<string> $problems Plan entries the parser rejected.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly TaskPlan $plan,
        public readonly array $changes,
        public readonly ValidationResult $validation,
        public readonly array $writes = [],
        public readonly bool $dryRun = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->validation->passed && $this->failedWrites() === [];
    }

    /**
     * Writes that were refused (unsafe path) or could not be completed.
     *
     * @return list<WriteResult>
     */
    public function failedWrites(): array
    {
        return array_values(array_filter(
            $this->writes,
            static fn (WriteResult $write): bool => ! $write->ok()
        ));
    }

    /**
     * Writes that actually altered the project.
     *
     * @return list<WriteResult>
     */
    public function changedWrites(): array
    {
        return array_values(array_filter(
            $this->writes,
            static fn (WriteResult $write): bool => $write->changed()
        ));
    }

    /**
     * Reasons no files were produced, for display when a run does nothing.
     *
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->plan->problems;
    }
}
