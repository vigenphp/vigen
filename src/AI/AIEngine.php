<?php

declare(strict_types=1);

namespace Vigen\AI;

use Vigen\Project\CodeValidator;
use Vigen\Project\Conventions;
use Vigen\Project\FileWriter;
use Vigen\Project\ProjectContext;
use Vigen\Project\SyntaxValidator;
use Vigen\Project\WriteResult;
use Vigen\Providers\AbstractProvider;
use Vigen\Providers\AIProviderInterface;

/**
 * AIEngine
 *
 * The single AI engine used by both the CLI and the GUI. It never depends
 * on a specific vendor - only on AIProviderInterface - so switching
 * providers is a .env change, not a code change.
 *
 * Workflow (see docs/prompting.md for the full picture):
 *   prompt -> context -> plan -> file changes -> apply -> validation -> (fix loop) -> done
 */
class AIEngine
{
    private readonly FileWriter $writer;
    private readonly SyntaxValidator $validator;
    private readonly CodeValidator $codeValidator;

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
        ?FileWriter $writer = null,
        ?SyntaxValidator $validator = null,
        ?CodeValidator $codeValidator = null,
        private readonly string $driver = 'sqlite',
    ) {
        $basePath = $this->project->basePath();
        $this->writer = $writer ?? new FileWriter($basePath);
        $this->validator = $validator ?? new SyntaxValidator($basePath);
        $this->codeValidator = $codeValidator ?? new CodeValidator();
    }

    /**
     * Run the full prompt-to-validated-change workflow for a single
     * developer instruction.
     *
     * @param bool $dryRun Plan and validate without touching the project.
     */
    public function handle(string $prompt, bool $dryRun = false): EngineResult
    {
        $context = $this->collectContext($prompt);
        $plan = $this->planTask($prompt, $context);
        $changes = $this->generateChanges($plan);

        $writes = $dryRun ? [] : $this->writer->apply($changes);
        $validation = $this->validate($changes, $writes, $dryRun);

        if (! $dryRun && ! $validation->passed) {
            $fixed = $this->attemptFix($changes, $validation);

            if ($fixed !== []) {
                $changes = $fixed;
                $writes = array_merge($writes, $this->writer->apply($fixed));
                $validation = $this->validate($changes, $writes, false);
            }
        }

        return new EngineResult($prompt, $plan, $changes, $validation, $writes, $dryRun);
    }

    /**
     * Step: Context Collection + Project Analysis.
     *
     * Sends the model the *contents* of the files relevant to the request,
     * not just their paths - without them it would rewrite a file like
     * routes/web.php from scratch and lose whatever was already in it.
     *
     * @return array<string, mixed>
     */
    private function collectContext(string $prompt): array
    {
        return [
            'project' => $this->project->summary(),
            'existing_files' => $this->project->relevantFileContents($prompt),
        ];
    }

    /**
     * Step: Task Understanding + Task Planning + Code Generation.
     *
     * Planning and generation are a single model call: the plan's steps carry
     * their complete file bodies, which halves round-trips against a local
     * model and removes a second place for the response to go astray.
     */
    private function planTask(string $prompt, array $context): TaskPlan
    {
        $response = $this->provider->complete([
            ['role' => 'system', 'content' => $this->plannerSystemPrompt()],
            ['role' => 'user', 'content' => $this->plannerUserPrompt($prompt, $context)],
        ], ['json' => true]);

        return TaskPlan::fromProviderText($prompt, $this->extractText($response));
    }

    /**
     * Step: File Analysis + Code Generation / Modification.
     *
     * @return list<FileChange>
     */
    private function generateChanges(TaskPlan $plan): array
    {
        return $plan->toFileChanges();
    }

    /**
     * Step: Validation (Run Tests / Checks + Detect Errors).
     *
     * Two independent checks, because they catch different failures:
     * SyntaxValidator lints what landed on disk, while CodeValidator reads the
     * proposed bodies for references to another framework - which is valid PHP
     * and so passes `php -l` without complaint.
     *
     * @param list<FileChange> $changes
     * @param list<WriteResult> $writes
     */
    private function validate(array $changes, array $writes, bool $dryRun): ValidationResult
    {
        $contents = $this->proposedContents($changes);

        $syntax = $dryRun
            // Nothing is on disk during a dry run, so lint the proposed bodies
            // from a scratch directory instead - a preview should still surface
            // broken PHP before it is written.
            ? $this->validator->validateContents($contents)
            : $this->validator->validateFiles($this->writtenPaths($writes));

        $foreign = $this->codeValidator->validateContents($contents);

        $errors = [...$syntax->errors, ...$foreign];

        return $errors === [] ? ValidationResult::ok() : ValidationResult::failed($errors);
    }

    /**
     * Only the files this run actually wrote. Re-linting untouched files would
     * report pre-existing errors as if Vigen had caused them, and trigger a
     * pointless fix attempt.
     *
     * @param list<WriteResult> $writes
     * @return list<string>
     */
    private function writtenPaths(array $writes): array
    {
        $written = [];

        foreach ($writes as $write) {
            if ($write->ok() && $write->status !== WriteResult::STATUS_UNCHANGED) {
                $written[] = $write->path;
            }
        }

        return $written;
    }

    /**
     * Step: AI Error Analysis -> Automatic Fix -> Validation Again.
     *
     * Runs at most once, as documented in docs/troubleshooting.md.
     *
     * @param list<FileChange> $changes
     * @return list<FileChange>
     */
    private function attemptFix(array $changes, ValidationResult $validation): array
    {
        $response = $this->provider->complete([
            ['role' => 'system', 'content' => $this->fixerSystemPrompt()],
            ['role' => 'user', 'content' => $this->fixerUserPrompt($changes, $validation)],
        ], ['json' => true]);

        return TaskPlan::fromProviderText('fix', $this->extractText($response))->toFileChanges();
    }

    /**
     * Unwrap the provider's response envelope.
     *
     * Vigen's built-in providers extend AbstractProvider and know their own
     * response shape. A third-party provider that does not can return an
     * already-decoded plan instead, which is passed through unchanged.
     *
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): string
    {
        if ($this->provider instanceof AbstractProvider) {
            return $this->provider->extractText($response);
        }

        return json_encode($response, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Proposed file bodies, keyed by path, for linting a dry run.
     *
     * @param list<FileChange> $changes
     * @return array<string, string>
     */
    private function proposedContents(array $changes): array
    {
        $contents = [];

        foreach ($changes as $change) {
            if ($change->action === FileChange::ACTION_DELETE || $change->contents === null) {
                continue;
            }

            $contents[$change->path] = $change->contents;
        }

        return $contents;
    }

    private function plannerSystemPrompt(): string
    {
        // Nowdocs keep the JSON schema below literal - the \n and \\ inside it
        // are there to be *read* by the model as valid JSON escapes, so they
        // must not be interpreted by PHP first.
        $schema = <<<'PROMPT'
            {
              "summary": "one line describing what you changed",
              "files": [
                {
                  "path": "app/Models/User.php",
                  "action": "create",
                  "contents": "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n",
                  "reason": "why this file is needed"
                }
              ]
            }
            PROMPT;

        $rules = <<<'PROMPT'
            Rules:
            - Reply with a single JSON object and nothing else. No prose, no
              markdown fences, no explanation outside the JSON.
            - "action" is one of: create, modify, delete.
            - "path" is always relative to the project root, never absolute.
            - "contents" is the COMPLETE final body of the file - not a diff,
              not a fragment, not a summary. Required for create and modify.
            - Never leave placeholders such as "// ...rest of the code" or
              "// existing code here". Write every line out in full.
            - When action is "modify", integrate with the file's existing
              contents shown in the request. Preserve unrelated code and keep
              the change as small as the request allows.
            - Only include files the request actually needs. Do not invent
              tests, documentation, or unrelated refactors.
            - Every file you reference must be included in this same response.
              If a controller calls View::render('auth.login'), then
              resources/views/auth/login.php must be one of the files.
            - If the request requires no file changes, return "files": [].
            PROMPT;

        return <<<'PROMPT'
            You are the code-generation stage of Vigen, an AI-first PHP framework.
            Given a developer's natural-language request and the current state of
            their project, produce the file changes needed to satisfy it.

            Write code against the Vigen API below - not Laravel, not Symfony.
            A file that uses any other framework's classes will fail to run.

            Respond with a single JSON object in exactly this shape:
            PROMPT
            . "\n\n" . $schema . "\n\n" . $rules . "\n\n"
            . "=== THE VIGEN API ===\n\n"
            . ApiReference::forPrompt($this->conventions, $this->driver);
    }

    private function plannerUserPrompt(string $prompt, array $context): string
    {
        return "Request: {$prompt}\n\nProject state:\n"
            . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<FileChange> $changes
     */
    private function fixerUserPrompt(array $changes, ValidationResult $validation): string
    {
        $files = [];
        foreach ($changes as $change) {
            if ($change->contents !== null) {
                $files[$change->path] = $change->contents;
            }
        }

        $errors = implode("\n", array_map(
            static fn (string $error): string => "- {$error}",
            $validation->errors
        ));

        return "Validation failed with these errors:\n{$errors}\n\n"
            . "The files involved currently contain:\n"
            . json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function fixerSystemPrompt(): string
    {
        $schema = <<<'PROMPT'
            {
              "summary": "one line describing the fix",
              "files": [
                {
                  "path": "app/Models/User.php",
                  "action": "modify",
                  "contents": "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n",
                  "reason": "why this fixes the error"
                }
              ]
            }
            PROMPT;

        return <<<'PROMPT'
            You are the self-correction stage of Vigen. The files you just
            generated failed validation. Return the corrected files.

            The most common failure is code written against another framework.
            Vigen is NOT Laravel or Symfony: there are no Illuminate\ classes,
            no Route/Hash/Auth/DB facades, no Eloquent, no Blade, no
            response()/redirect()/view() helpers, and controllers extend
            nothing. Rewrite any such code against the Vigen API below.

            Respond with a single JSON object in exactly this shape:
            PROMPT
            . "\n\n" . $schema . "\n\n"
            . "Return only the files that need correcting, each with its complete\n"
            . "corrected body. Reply with JSON and nothing else.\n\n"
            . "=== THE VIGEN API ===\n\n"
            . ApiReference::forPrompt($this->conventions, $this->driver);
    }
}
