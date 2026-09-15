<?php

declare(strict_types=1);

namespace Vigen\AI;

final class TaskPlan
{
    /**
     * @param list<array{path: string, action: string, reason?: string}> $steps
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $steps,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a TaskPlan from a raw provider response. Parsing here is
     * deliberately defensive - providers return free-form text until the
     * engine's response-format contract is finalized.
     *
     * @param array<string, mixed> $response
     */
    public static function fromProviderResponse(string $prompt, array $response): self
    {
        $steps = $response['steps'] ?? [];

        return new self($prompt, is_array($steps) ? $steps : [], $response);
    }

    /**
     * @return list<FileChange>
     */
    public function toFileChanges(): array
    {
        $changes = [];
        foreach ($this->steps as $step) {
            if (! isset($step['path'], $step['action'])) {
                continue;
            }

            $changes[] = new FileChange(
                path: $step['path'],
                action: $step['action'],
                contents: $step['contents'] ?? null,
                reason: $step['reason'] ?? null,
            );
        }

        return $changes;
    }
}
