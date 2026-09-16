<?php

declare(strict_types=1);

namespace Vigen\AI;

/**
 * The plan a provider returned for a prompt, normalised into file changes.
 *
 * Parsing is deliberately tolerant: local models wrap JSON in prose or code
 * fences, and occasionally leave a trailing comma. Anything that still cannot
 * be parsed, or that describes a change we refuse to make (an empty file
 * body, an unknown action), is recorded in $problems rather than dropped -
 * silently returning zero changes is what made "the AI did nothing" so hard
 * to diagnose.
 */
final class TaskPlan
{
    /**
     * @param list<array{path: string, action: string, contents: ?string, reason: ?string}> $steps
     * @param array<string, mixed> $raw The decoded provider JSON, for debugging.
     * @param list<string> $problems Human-readable reasons entries were rejected.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $steps,
        public readonly array $raw = [],
        public readonly array $problems = [],
    ) {
    }

    /**
     * Build a plan from the assistant text of a provider response (see
     * AbstractProvider::extractText() for the unwrapping step).
     */
    public static function fromProviderText(string $prompt, string $text): self
    {
        $decoded = self::extractJson($text);

        if ($decoded === null) {
            return new self($prompt, [], [], [
                'No JSON object could be parsed from the model response. First 200 characters: '
                    . self::preview($text),
            ]);
        }

        // "files" is the documented contract; "steps" is the older shape and
        // is still accepted so existing prompts and fixtures keep working.
        $entries = $decoded['files'] ?? $decoded['steps'] ?? null;

        if (! is_array($entries)) {
            return new self($prompt, [], $decoded, [
                'Response JSON contained no "files" array. Keys present: '
                    . (implode(', ', array_keys($decoded)) ?: '(none)'),
            ]);
        }

        [$steps, $problems] = self::normalise($entries);

        return new self($prompt, $steps, $decoded, $problems);
    }

    /**
     * @return list<FileChange>
     */
    public function toFileChanges(): array
    {
        $changes = [];
        foreach ($this->steps as $step) {
            $changes[] = new FileChange(
                path: $step['path'],
                action: $step['action'],
                contents: $step['contents'],
                reason: $step['reason'],
            );
        }

        return $changes;
    }

    public function summary(): ?string
    {
        $summary = $this->raw['summary'] ?? null;

        return is_string($summary) && trim($summary) !== '' ? $summary : null;
    }

    /**
     * Find a JSON object in text that may be wrapped in prose or a code fence.
     *
     * @return array<string, mixed>|null
     */
    private static function extractJson(string $text): ?array
    {
        $candidates = [];

        // A fenced block is the most explicit signal, so try it first.
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        // Otherwise take the outermost {...} span, which skips any prose the
        // model wrote before or after the JSON.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($text, $start, $end - $start + 1);
        }

        $candidates[] = $text;

        foreach ($candidates as $candidate) {
            // Retry once with trailing commas removed - the single most
            // common way a model's otherwise-valid JSON fails to decode.
            foreach ([$candidate, self::stripTrailingCommas($candidate)] as $attempt) {
                $decoded = json_decode(trim($attempt), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private static function stripTrailingCommas(string $json): string
    {
        return (string) preg_replace('/,(\s*[}\]])/', '$1', $json);
    }

    /**
     * @param array<mixed> $entries
     * @return array{0: list<array{path: string, action: string, contents: ?string, reason: ?string}>, 1: list<string>}
     */
    private static function normalise(array $entries): array
    {
        $steps = [];
        $problems = [];

        foreach ($entries as $index => $entry) {
            $position = $index + 1;

            if (! is_array($entry)) {
                $problems[] = "Entry #{$position} was not an object.";

                continue;
            }

            $path = $entry['path'] ?? null;
            $action = strtolower((string) ($entry['action'] ?? FileChange::ACTION_CREATE));

            if (! is_string($path) || trim($path) === '') {
                $problems[] = "Entry #{$position} had no \"path\".";

                continue;
            }

            if (! in_array($action, [
                FileChange::ACTION_CREATE,
                FileChange::ACTION_MODIFY,
                FileChange::ACTION_DELETE,
            ], true)) {
                $problems[] = "Entry \"{$path}\" used unsupported action \"{$action}\".";

                continue;
            }

            $contents = is_string($entry['contents'] ?? null) ? $entry['contents'] : null;

            // A create/modify with no body would write - or blank out - a file
            // containing nothing. That is nearly always a truncated answer,
            // so refuse it and say so rather than writing an empty file.
            if ($action !== FileChange::ACTION_DELETE && ($contents === null || trim($contents) === '')) {
                $problems[] = "Entry \"{$path}\" ({$action}) had no \"contents\" - refused to write an empty file.";

                continue;
            }

            $steps[] = [
                'path' => $path,
                'action' => $action,
                'contents' => $contents,
                'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
            ];
        }

        return [$steps, $problems];
    }

    private static function preview(string $text): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $text));

        return $collapsed === '' ? '(empty response)' : mb_substr($collapsed, 0, 200);
    }
}
