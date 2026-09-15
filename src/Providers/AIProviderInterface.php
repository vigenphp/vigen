<?php

declare(strict_types=1);

namespace Vigen\Providers;

/**
 * AIProviderInterface
 *
 * Every AI backend (Ollama, OpenAI, Claude, Gemini, or any future provider)
 * implements this contract. The Vigen AI Engine only ever talks to this
 * interface, never to a concrete provider - so adding a new provider means
 * writing a new class, not touching the engine.
 */
interface AIProviderInterface
{
    /**
     * A short machine-readable identifier, e.g. "ollama", "openai".
     */
    public function name(): string;

    /**
     * Send a structured completion request to the underlying model and
     * return the raw response payload as an associative array.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options Provider-specific overrides (temperature, max_tokens, etc).
     * @return array<string, mixed>
     */
    public function complete(array $messages, array $options = []): array;

    /**
     * Whether this provider is fully configured (API key / host present)
     * and ready to accept requests.
     */
    public function isConfigured(): bool;

    /**
     * The model identifier currently selected for this provider.
     */
    public function model(): string;
}
