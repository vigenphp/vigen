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
        $wantsJson = $this->consumeJsonFlag($options);

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
        ];

        // "format" is a top-level body field, NOT one of the sampling options
        // below - putting it inside "options" is silently ignored by Ollama.
        // It constrains the model to emit syntactically valid JSON, which is
        // what makes small local models (e.g. qwen2.5-coder:14b) reliable
        // enough for Vigen's plan contract.
        if ($wantsJson) {
            $payload['format'] = 'json';
        }

        // Ollama's "options" field must be a JSON object. An empty PHP array
        // encodes as "[]", which Ollama rejects ("cannot unmarshal array into
        // ... options of type map"), so omit it when empty and cast to an
        // object otherwise to guarantee "{}" rather than "[]".
        if ($options !== []) {
            $payload['options'] = (object) $options;
        }

        $response = $this->http->post('/api/chat', [
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function host(): ?string
    {
        return $this->configValue('host', 'OLLAMA_HOST', 'http://localhost:11434');
    }
}
