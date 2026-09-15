<?php

declare(strict_types=1);

namespace Vigen\Providers;

use RuntimeException;

/**
 * Resolves VIGEN_AI_PROVIDER / VIGEN_AI_MODEL (and provider-specific keys)
 * into a concrete AIProviderInterface instance. This is the only place in
 * Vigen that knows about all four provider classes - the AI Engine never
 * references them directly.
 */
class ProviderFactory
{
    /** @var array<string, class-string<AIProviderInterface>> */
    private static array $providers = [
        'ollama' => OllamaProvider::class,
        'openai' => OpenAIProvider::class,
        'claude' => ClaudeProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    public static function make(?string $providerName = null, ?string $model = null): AIProviderInterface
    {
        $providerName = strtolower($providerName ?? (string) ($_ENV['VIGEN_AI_PROVIDER'] ?? 'ollama'));
        $model = $model ?? (string) ($_ENV['VIGEN_AI_MODEL'] ?? '');

        if (! isset(self::$providers[$providerName])) {
            throw new RuntimeException(sprintf(
                'Unknown AI provider "%s". Available providers: %s',
                $providerName,
                implode(', ', array_keys(self::$providers))
            ));
        }

        $class = self::$providers[$providerName];

        return new $class($model);
    }

    /**
     * Register an additional provider without modifying the framework -
     * this is how a future provider plugs in without rewriting the engine.
     */
    public static function register(string $name, string $providerClass): void
    {
        self::$providers[strtolower($name)] = $providerClass;
    }
}
