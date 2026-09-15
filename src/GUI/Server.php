<?php

declare(strict_types=1);

namespace Vigen\GUI;

use Vigen\Core\Application as CoreApplication;

/**
 * Minimal GUI bootstrap. This intentionally does not implement its own AI
 * logic - every request is handed to the same AIEngine the CLI uses via
 * CoreApplication::engine(). The GUI is only a thin HTTP layer:
 *   POST /api/chat { "prompt": "..." } -> AIEngine::handle() -> JSON result
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
    public function handleChatRequest(string $prompt): array
    {
        $result = $this->core->engine()->handle($prompt);

        return [
            'prompt' => $result->prompt,
            'success' => $result->isSuccessful(),
            'changes' => array_map(
                static fn ($change) => [
                    'path' => $change->path,
                    'action' => $change->action,
                    'reason' => $change->reason,
                ],
                $result->changes
            ),
            'errors' => $result->validation->errors,
        ];
    }

    public function entryPointPath(): string
    {
        return __DIR__ . '/../../resources/gui/index.html';
    }
}
