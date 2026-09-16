<?php

declare(strict_types=1);

namespace Vigen\Providers;

use GuzzleHttp\Client;

class OpenAIProvider extends AbstractProvider
{
    private Client $http;

    public function __construct(string $model, array $config = [])
    {
        parent::__construct($model, $config);
        $this->http = $config['http_client'] ?? new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 120,
        ]);
    }

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null && $this->model !== '';
    }

    public function complete(array $messages, array $options = []): array
    {
        $wantsJson = $this->consumeJsonFlag($options);

        $payload = array_merge([
            'model' => $this->model,
            'messages' => $messages,
        ], $options);

        // OpenAI's native JSON mode. Requires the prompt to mention JSON,
        // which AIEngine's planner prompt does.
        if ($wantsJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->http->post('chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function apiKey(): ?string
    {
        return $this->configValue('api_key', 'OPENAI_API_KEY');
    }
}
