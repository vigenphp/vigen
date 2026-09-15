<?php

declare(strict_types=1);

namespace Vigen\AI;

use Vigen\Project\Conventions;
use Vigen\Project\ProjectContext;
use Vigen\Providers\AIProviderInterface;

/**
 * AIEngine
 *
 * The single AI engine used by both the CLI and the GUI. It never depends
 * on a specific vendor - only on AIProviderInterface - so switching
 * providers is a .env change, not a code change.
 *
 * Workflow (see docs/prompting.md for the full picture):
 *   prompt -> context -> plan -> file changes -> validation -> (fix loop) -> done
 *
 * This class currently implements the pipeline's structure and hand-off
 * points. The step methods are intentionally thin - each is the seam where
 * real prompt construction, response parsing, and file-writing logic will
 * grow as Vigen matures past its MVP.
 */
class AIEngine
{
    /**
     * @param array<string, string> $conventions File-location conventions
     *   (models, controllers, routes, migrations, ...) resolved from
     *   config/app.php via Conventions::resolve(). The planner is told
     *   about these so generated files land in the right place.
     */
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly ProjectContext $project,
        private readonly array $conventions = Conventions::DEFAULTS,
    ) {
    }

    /**
     * Run the full prompt-to-validated-change workflow for a single
     * developer instruction.
     */
    public function handle(string $prompt): EngineResult
    {
        $context = $this->collectContext($prompt);
        $plan = $this->planTask($prompt, $context);
        $changes = $this->generateChanges($plan);

        $validation = $this->validate($changes);

        if (! $validation->passed) {
            $changes = $this->attemptFix($changes, $validation);
            $validation = $this->validate($changes);
        }

        return new EngineResult($prompt, $plan, $changes, $validation);
    }

    /**
     * Step: Context Collection + Project Analysis.
     *
     * @return array<string, mixed>
     */
    private function collectContext(string $prompt): array
    {
        return [
            'project' => $this->project->summary(),
            'relevant_files' => $this->project->relevantFiles($prompt),
        ];
    }

    /**
     * Step: Task Understanding + Task Planning.
     */
    private function planTask(string $prompt, array $context): TaskPlan
    {
        $response = $this->provider->complete([
            ['role' => 'system', 'content' => $this->plannerSystemPrompt()],
            ['role' => 'user', 'content' => $this->plannerUserPrompt($prompt, $context)],
        ]);

        return TaskPlan::fromProviderResponse($prompt, $response);
    }

    /**
     * Step: File Analysis + Code Generation / Modification.
     *
     * @return list<FileChange>
     */
    private function generateChanges(TaskPlan $plan): array
    {
        // Each planned step becomes a file change once code generation is
        // wired to real prompt/response handling for that provider.
        return $plan->toFileChanges();
    }

    /**
     * Step: Validation (Run Tests / Checks + Detect Errors).
     *
     * @param list<FileChange> $changes
     */
    private function validate(array $changes): ValidationResult
    {
        // MVP placeholder: real validation runs `php -l`, project tests,
        // and route/config checks against the applied changes.
        return ValidationResult::ok();
    }

    /**
     * Step: AI Error Analysis -> Automatic Fix -> Validation Again.
     *
     * @param list<FileChange> $changes
     * @return list<FileChange>
     */
    private function attemptFix(array $changes, ValidationResult $validation): array
    {
        $response = $this->provider->complete([
            ['role' => 'system', 'content' => $this->fixerSystemPrompt()],
            ['role' => 'user', 'content' => implode("\n", $validation->errors)],
        ]);

        return TaskPlan::fromProviderResponse('fix', $response)->toFileChanges();
    }

    private function plannerSystemPrompt(): string
    {
        $base = <<<'PROMPT'
            You are the planning stage of Vigen, an AI-first PHP framework.
            Given a developer's natural-language request and the current project
            context, produce a minimal, safe plan of file changes required to
            satisfy the request without duplicating existing functionality.

            Follow these file-location conventions unless the project's existing
            structure clearly uses different ones:
            PROMPT;

        return $base . "\n" . $this->conventionsList();
    }

    private function conventionsList(): string
    {
        $lines = [];
        foreach ($this->conventions as $type => $path) {
            $lines[] = '- ' . ucfirst($type) . ": {$path}";
        }

        return implode("\n", $lines);
    }

    private function plannerUserPrompt(string $prompt, array $context): string
    {
        return "Request: {$prompt}\n\nProject context:\n" . json_encode($context, JSON_PRETTY_PRINT);
    }

    private function fixerSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are the self-correction stage of Vigen. Given validation errors,
            determine the smallest fix required and return updated file changes.
            PROMPT;
    }
}
