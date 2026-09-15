<?php

declare(strict_types=1);

namespace Vigen\Providers;

use GuzzleHttp\Client;

class OllamaProvider extends AbstractProvider
{
    private Client $http;

    public function __construct(string $model, array $config = [])
    {
        parent::__construct($model, $config);
        $this->http = $config['http_client'] ?? new Client([
            'base_uri' => $this->host(),
            'timeout' => 120,
        ]);
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function isConfigured(): bool
    {
        return $this->host() !== null && $this->model !== '';
    }

    public function complete(array $messages, array $options = []): array
    {
        $response = $this->http->post('/api/chat', [
            'json' => [
                'model' => $this->model,
                'messages' => $messages,
                'stream' => false,
                'options' => $options,
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function host(): ?string
    {
        return $this->configValue('host', 'OLLAMA_HOST', 'http://localhost:11434');
    }
}
