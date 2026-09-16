<?php

declare(strict_types=1);

namespace Vigen\Providers;

use RuntimeException;

abstract class AbstractProvider implements AIProviderInterface
{
    public function __construct(
        protected readonly string $model,
        protected readonly array $config = []
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Extract the assistant's text from this provider's raw response
     * envelope.
     *
     * Every backend wraps the model's answer differently - Ollama nests it
     * under "message", OpenAI under "choices[0].message", Claude under a list
     * of content blocks, Gemini under "candidates[0].content.parts". Callers
     * (see Vigen\AI\AIEngine) only ever deal with the unwrapped string, so
     * this is the one place that knows about vendor envelopes.
     *
     * @param array<string, mixed> $response
     * @throws RuntimeException when the provider reported an error or returned no text
     */
    public function extractText(array $response): string
    {
        // Several providers report failures with an HTTP 200 and an "error"
        // body (Ollama does this for an unknown model). Fail loudly rather
        // than letting an empty answer look like "nothing to change".
        if (isset($response['error'])) {
            throw new RuntimeException(sprintf(
                '%s error: %s',
                $this->name(),
                $this->describeError($response['error'])
            ));
        }

        $text = $this->firstNonEmpty([
            $response['message']['content'] ?? null,                 // Ollama
            $response['choices'][0]['message']['content'] ?? null,   // OpenAI
            $this->concatClaudeBlocks($response),                    // Claude
            $this->concatGeminiParts($response),                     // Gemini
        ]);

        if ($text === null) {
            throw new RuntimeException(sprintf(
                '%s returned no text. Raw response: %s',
                $this->name(),
                json_encode($response, JSON_UNESCAPED_SLASHES) ?: '(unencodable response)'
            ));
        }

        return $text;
    }

    /**
     * Read a value from the provider config array, falling back to an
     * environment variable, then a default.
     */
    protected function configValue(string $key, ?string $envVar = null, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->config) && $this->config[$key] !== null && $this->config[$key] !== '') {
            return $this->config[$key];
        }

        if ($envVar !== null) {
            $envValue = $_ENV[$envVar] ?? getenv($envVar) ?: null;
            if ($envValue !== null && $envValue !== '') {
                return $envValue;
            }
        }

        return $default;
    }

    /**
     * Remove the engine's internal `json` flag from $options so it is never
     * forwarded verbatim to an HTTP API, and report whether the caller asked
     * for structured output.
     *
     * Providers that support a native JSON mode call this and then set their
     * own body field; the ones that do not (Claude, Gemini) call it purely to
     * strip the flag and rely on the prompt plus the tolerant parser in
     * Vigen\AI\TaskPlan.
     *
     * @param array<string, mixed> $options
     */
    protected function consumeJsonFlag(array &$options): bool
    {
        $wantsJson = (bool) ($options['json'] ?? false);
        unset($options['json']);

        return $wantsJson;
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function concatClaudeBlocks(array $response): ?string
    {
        $blocks = $response['content'] ?? null;
        if (! is_array($blocks)) {
            return null;
        }

        $texts = [];
        foreach ($blocks as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return $texts === [] ? null : implode("\n", $texts);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function concatGeminiParts(array $response): ?string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? null;
        if (! is_array($parts)) {
            return null;
        }

        $texts = [];
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }

        return $texts === [] ? null : implode('', $texts);
    }

    private function describeError(mixed $error): string
    {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return json_encode($error, JSON_UNESCAPED_SLASHES) ?: 'unknown error';
    }
}
