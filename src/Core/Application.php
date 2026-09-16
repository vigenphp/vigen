<?php

declare(strict_types=1);

namespace Vigen\Core;

use Dotenv\Dotenv;
use Vigen\AI\AIEngine;
use Vigen\Database\Connection;
use Vigen\Http\Kernel;
use Vigen\Project\Conventions;
use Vigen\Project\ProjectContext;
use Vigen\Providers\ProviderFactory;

/**
 * Boots the shared runtime: loads .env, builds the ProjectContext and Config,
 * and hands out the two entry points - the AI engine used by the CLI and GUI,
 * and the HTTP kernel used by a project's public/index.php.
 *
 * Both are built lazily. Serving a web request must not construct an AI
 * provider, and running `vigen chat` must not build a router.
 */
class Application
{
    private static ?Application $instance = null;

    private ?AIEngine $engine = null;

    private ?Kernel $kernel = null;

    private ?Container $container = null;

    private readonly ProjectContext $project;

    private readonly Config $config;

    /** @var array<string, string> */
    private readonly array $conventions;

    public function __construct(private readonly string $basePath)
    {
        self::loadHelpers();

        $this->loadEnvironment();

        $this->project = new ProjectContext($this->basePath);
        $this->config = new Config($this->basePath);
        $this->conventions = Conventions::resolve($this->config->get('app.paths'));

        self::$instance = $this;
    }

    /**
     * Load the global helpers if Composer has not already done it.
     *
     * The helpers are also registered through composer.json's "autoload.files",
     * but that entry only takes effect when Composer regenerates the autoloader
     * from *this package's* metadata. A project that requires Vigen reads that
     * metadata from the released version it installed, so a newer checkout -
     * or a hand-copied vendor directory - leaves the globals undefined even
     * after `composer dump-autoload`.
     *
     * The failure is not a warning: config/*.php calls env() while Config is
     * being constructed, which is the first thing boot does, so every entry
     * point (CLI, GUI and public/index.php) dies with "Call to undefined
     * function env()". Loading them here makes boot independent of whatever
     * the generated autoloader happens to contain.
     */
    private static function loadHelpers(): void
    {
        if (function_exists('env')) {
            return;
        }

        $helpers = __DIR__ . '/../Support/helpers.php';

        if (is_file($helpers)) {
            require_once $helpers;
        }
    }

    /**
     * The application booted for this process, used by the config() and
     * base_path() helpers. Null before anything has booted.
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function forget(): void
    {
        self::$instance = null;
    }

    /**
     * The AI engine used by `vigen chat` and the GUI chatbox.
     */
    public function engine(): AIEngine
    {
        return $this->engine ??= new AIEngine(
            ProviderFactory::make(),
            $this->project,
            $this->conventions,
            driver: $this->databaseDriver()
        );
    }

    /**
     * The configured database driver, so generated migrations are written in
     * SQL that database accepts. Reading the driver does not open a connection
     * - Connection builds its PDO lazily, on the first query.
     */
    private function databaseDriver(): string
    {
        try {
            return Connection::fromConfig(null, $this->basePath)->driver();
        } catch (\Throwable) {
            // A missing or unreadable config/database.php must not stop the
            // AI engine from being built; the prompt just assumes the default.
            return 'sqlite';
        }
    }

    /**
     * The HTTP kernel used to serve the application itself.
     */
    public function http(): Kernel
    {
        return $this->kernel ??= new Kernel($this);
    }

    /**
     * Shared service container. Bindings made here are visible to
     * controllers, middleware and the AI engine alike.
     */
    public function container(): Container
    {
        return $this->container ??= new Container();
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
