<?php

declare(strict_types=1);

namespace Vigen\Core;

use Dotenv\Dotenv;
use Vigen\AI\AIEngine;
use Vigen\Project\Conventions;
use Vigen\Project\ProjectContext;
use Vigen\Providers\ProviderFactory;

/**
 * Boots the shared runtime that both the CLI and GUI use: loads .env,
 * builds the ProjectContext, resolves the configured AI provider, and
 * constructs the single AIEngine instance. Neither interface builds its
 * own engine - they both ask this class for one.
 */
class Application
{
    private readonly AIEngine $engine;
    private readonly ProjectContext $project;
    private readonly Config $config;

    /** @var array<string, string> */
    private readonly array $conventions;

    public function __construct(private readonly string $basePath)
    {
        $this->loadEnvironment();

        $this->project = new ProjectContext($this->basePath);
        $this->config = new Config($this->basePath);
        $this->conventions = Conventions::resolve($this->config->get('app.paths'));

        $provider = ProviderFactory::make();
        $this->engine = new AIEngine($provider, $this->project, $this->conventions);
    }

    public function engine(): AIEngine
    {
        return $this->engine;
    }

    public function project(): ProjectContext
    {
        return $this->project;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * @return array<string, string>
     */
    public function conventions(): array
    {
        return $this->conventions;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    private function loadEnvironment(): void
    {
        if (! is_file($this->basePath . '/.env')) {
            return;
        }

        if (class_exists(Dotenv::class)) {
            Dotenv::createImmutable($this->basePath)->safeLoad();
        }
    }
}
