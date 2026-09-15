<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vigen\Core\Application as CoreApplication;

#[AsCommand(name: 'chat', description: 'Describe what you want. Vigen builds it.')]
class ChatCommand extends Command
{
    public function __construct(private readonly CoreApplication $core)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('prompt', InputArgument::REQUIRED, 'What should Vigen build or change?');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) $input->getArgument('prompt');

        $output->writeln('<info>Vigen</info>');
        $output->writeln('');
        $output->writeln('> Analyzing project...');

        $result = $this->core->engine()->handle($prompt);

        $output->writeln('✓ Project analyzed');
        $output->writeln('');
        $output->writeln('> Planning changes...');
        $output->writeln('✓ Plan created');
        $output->writeln('');
        $output->writeln('> Modifying files...');

        foreach ($result->changes as $change) {
            $output->writeln("✓ {$change->path}");
        }

        $output->writeln('');
        $output->writeln('> Validating...');

        if ($result->isSuccessful()) {
            $output->writeln('✓ Validation passed');
            $output->writeln('');
            $output->writeln('<info>Done.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<error>✗ Validation failed</error>');
        foreach ($result->validation->errors as $error) {
            $output->writeln("  - {$error}");
        }

        return Command::FAILURE;
    }
}
