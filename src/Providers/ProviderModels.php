<?php

declare(strict_types=1);

namespace Vigen\Providers;

/**
 * Curated model choices shown during `vigen init`. This is a UX convenience
 * only - AIProviderInterface accepts any model string, so power users can
 * still hand-edit .env with a model that isn't listed here.
 */
final class ProviderModels
{
    /** @var array<string, list<string>> */
    private const MODELS = [
        'ollama' => [
            'qwen2.5-coder:14b',
            'qwen2.5-coder:7b',
            'deepseek-coder-v2:16b',
            'codellama:13b',
        ],
        'openai' => [
            'gpt-4o',
            'gpt-4o-mini',
            'o3-mini',
        ],
        'claude' => [
            'claude-opus-5',
            'claude-sonnet-5',
            'claude-haiku-4-5-20251001',
        ],
        'gemini' => [
            'gemini-2.0-pro',
            'gemini-2.0-flash',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function for(string $provider): array
    {
        return self::MODELS[strtolower($provider)] ?? [];
    }

    public static function requiresApiKey(string $provider): bool
    {
        return strtolower($provider) !== 'ollama';
    }

    /**
     * The .env variable name that holds the API key for a given provider.
     */
    public static function apiKeyEnvVar(string $provider): ?string
    {
        return match (strtolower($provider)) {
            'openai' => 'OPENAI_API_KEY',
            'claude' => 'ANTHROPIC_API_KEY',
            'gemini' => 'GEMINI_API_KEY',
            default => null,
        };
    }
}
