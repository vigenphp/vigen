<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vigen\CLI\ServerProcess;

#[AsCommand(name: 'gui', description: 'Start the built-in Vigen GUI chatbox.')]
class GuiCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', '127.0.0.1');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind', '8808');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $router = dirname(__DIR__, 2) . '/GUI/router.php';

        $output->writeln('<info>VIGEN</info>');
        $output->writeln("GUI chatbox running at http://{$host}:{$port}");
        $output->writeln('Press Ctrl+C to stop.');
        $output->writeln('');

        return ServerProcess::start(
            [PHP_BINARY, '-S', "{$host}:{$port}", $router],
            $this->basePath,
            $output
        );
    }
}
