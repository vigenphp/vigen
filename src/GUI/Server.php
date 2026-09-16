<?php

declare(strict_types=1);

namespace Vigen\GUI;

use Vigen\Core\Application as CoreApplication;
use Vigen\Project\WriteResult;

/**
 * Minimal GUI bootstrap. This intentionally does not implement its own AI
 * logic - every request is handed to the same AIEngine the CLI uses via
 * CoreApplication::engine(). The GUI is only a thin HTTP layer:
 *   POST /api/chat { "prompt": "...", "dry_run": false } -> AIEngine::handle() -> JSON result
 *
 * A full router/framework is out of scope for the MVP; this class defines
 * the seam a lightweight PHP built-in server (or PSR-7 adapter) hooks into.
 */
class Server
{
    public function __construct(private readonly CoreApplication $core)
    {
    }

    /**
     * Handle a single chat request from the GUI and return a JSON-ready
     * payload describing what the engine did.
     *
     * @return array<string, mixed>
     */
    public function handleChatRequest(string $prompt, bool $dryRun = false): array
    {
        $result = $this->core->engine()->handle($prompt, $dryRun);

        return [
            'prompt' => $result->prompt,
            'success' => $result->isSuccessful(),
            'dry_run' => $result->dryRun,
            'summary' => $result->plan->summary(),

            // What actually happened on disk. Empty during a dry run.
            'writes' => array_map(
                static fn (WriteResult $write): array => [
                    'path' => $write->path,
                    'action' => $write->action,
                    'status' => $write->status,
                    'message' => $write->message,
                ],
                $result->writes
            ),

            // What the model asked for - the only useful list during a dry run.
            'changes' => array_map(
                static fn ($change): array => [
                    'path' => $change->path,
                    'action' => $change->action,
                    'reason' => $change->reason,
                ],
                $result->changes
            ),

            'problems' => $result->diagnostics(),
            'errors' => $result->validation->errors,
        ];
    }

    public function entryPointPath(): string
    {
        return __DIR__ . '/../../resources/gui/index.html';
    }
}
