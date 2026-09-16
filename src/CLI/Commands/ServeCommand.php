<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vigen\CLI\ServerProcess;

#[AsCommand(name: 'serve', description: 'Serve your Vigen application.')]
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
        $this->addOption('gui', null, InputOption::VALUE_NONE, 'Deprecated: the chatbox moved to `vigen gui`.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // `serve` used to start the chatbox. Anyone with that habit gets told
        // where it went rather than wondering why their app looks like a chat UI.
        if ($input->getOption('gui')) {
            $output->writeln('<comment>`vigen serve --gui` is gone - the chatbox is now its own command:</comment>');
            $output->writeln('');
            $output->writeln('  php vigen gui');

            return Command::SUCCESS;
        }

        $public = $this->basePath . '/public';
        $frontController = $public . '/index.php';

        if (! is_file($frontController)) {
            $output->writeln("<error>No public/index.php found in {$this->basePath}.</error>");
            $output->writeln('');
            $output->writeln('Run `php vigen init` to scaffold the application, or create public/index.php yourself.');

            return Command::FAILURE;
        }

        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');

        $output->writeln('<info>VIGEN</info>');
        $output->writeln("Application running at http://{$host}:{$port}");
        $output->writeln('Press Ctrl+C to stop.');
        $output->writeln('');

        return ServerProcess::start(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $public, $frontController],
            $this->basePath,
            $output
        );
    }
}
