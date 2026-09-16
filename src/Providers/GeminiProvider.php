<?php

declare(strict_types=1);

namespace Vigen\Providers;

use GuzzleHttp\Client;

class GeminiProvider extends AbstractProvider
{
    private Client $http;

    public function __construct(string $model, array $config = [])
    {
        parent::__construct($model, $config);
        $this->http = $config['http_client'] ?? new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 120,
        ]);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null && $this->model !== '';
    }

    public function complete(array $messages, array $options = []): array
    {
        // Gemini has no JSON response mode here, so structured output is
        // enforced by the prompt and recovered by TaskPlan's tolerant parser.
        // Consume the flag regardless, so it never reaches the request body.
        $this->consumeJsonFlag($options);

        $contents = array_map(
            static fn (array $m) => [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ],
            array_filter($messages, static fn (array $m) => $m['role'] !== 'system')
        );

        $response = $this->http->post(
            "models/{$this->model}:generateContent?key={$this->apiKey()}",
            ['json' => ['contents' => array_values($contents)]]
        );

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function apiKey(): ?string
    {
        return $this->configValue('api_key', 'GEMINI_API_KEY');
    }
}
