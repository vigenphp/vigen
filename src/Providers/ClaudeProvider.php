<?php

declare(strict_types=1);

namespace Vigen\Providers;

use GuzzleHttp\Client;

class ClaudeProvider extends AbstractProvider
{
    private Client $http;

    public function __construct(string $model, array $config = [])
    {
        parent::__construct($model, $config);
        $this->http = $config['http_client'] ?? new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'timeout' => 120,
        ]);
    }

    public function name(): string
    {
        return 'claude';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null && $this->model !== '';
    }

    public function complete(array $messages, array $options = []): array
    {
        // Anthropic uses a "system" field rather than a system role message.
        $system = null;
        $filtered = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system = $message['content'];
                continue;
            }
            $filtered[] = $message;
        }

        $payload = array_merge([
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $filtered,
        ], $system !== null ? ['system' => $system] : []);

        $response = $this->http->post('messages', [
            'headers' => [
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function apiKey(): ?string
    {
        return $this->configValue('api_key', 'ANTHROPIC_API_KEY');
    }
}
