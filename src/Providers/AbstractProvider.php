<?php

declare(strict_types=1);

namespace Vigen\Providers;

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
}
