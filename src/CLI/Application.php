<?php

declare(strict_types=1);

namespace Vigen\CLI;

use Symfony\Component\Console\Application as ConsoleApplication;
use Vigen\CLI\Commands\ChatCommand;
use Vigen\CLI\Commands\GuiCommand;
use Vigen\CLI\Commands\InitCommand;
use Vigen\CLI\Commands\MigrateCommand;
use Vigen\CLI\Commands\ServeCommand;
use Vigen\Core\Application as CoreApplication;

class Application
{
    private readonly ConsoleApplication $console;

    public function __construct(?string $basePath = null)
    {
        $basePath ??= getcwd() ?: '.';
        $core = new CoreApplication($basePath);

        $this->console = new ConsoleApplication('Vigen', $this->version());
        $this->console->add(new InitCommand($basePath));
        $this->console->add(new ChatCommand($core));
        $this->console->add(new ServeCommand($basePath));
        $this->console->add(new GuiCommand($basePath));
        $this->console->add(new MigrateCommand($core));
    }

    public function run(array $argv): int
    {
        return $this->console->run();
    }

    private function version(): string
    {
        return '1.0.0';
    }
}
