<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Start the built-in Vigen GUI chatbox.')]
class ServeCommand extends Command
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
        $router = __DIR__ . '/../../GUI/router.php';

        $output->writeln('<info>VIGEN</info>');
        $output->writeln("GUI chatbox running at http://{$host}:{$port}");
        $output->writeln('Press Ctrl+C to stop.');
        $output->writeln('');

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", $router],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $this->basePath,
            ['VIGEN_BASE_PATH' => $this->basePath]
        );

        if (! is_resource($process)) {
            $output->writeln('<error>Failed to start the built-in server.</error>');

            return Command::FAILURE;
        }

        return proc_close($process);
    }
}
