<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vigen\AI\EngineResult;
use Vigen\Core\Application as CoreApplication;
use Vigen\Project\WriteResult;

#[AsCommand(name: 'chat', description: 'Describe what you want. Vigen builds it.')]
class ChatCommand extends Command
{
    public function __construct(private readonly CoreApplication $core)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'What should Vigen build or change?')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would change without writing anything to disk.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = (string) $input->getArgument('prompt');
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln('<info>Vigen</info>');
        $output->writeln('');
        $output->writeln('> Analyzing project...');

        $result = $this->core->engine()->handle($prompt, $dryRun);

        $output->writeln('✓ Project analyzed');
        $output->writeln('');
        $output->writeln('> Planning changes...');
        $output->writeln('✓ Plan created');
        $output->writeln('');

        if ($summary = $result->plan->summary()) {
            $output->writeln("  {$summary}");
            $output->writeln('');
        }

        $output->writeln($dryRun
            ? '> Modifying files... <comment>(dry run - nothing will be written)</comment>'
            : '> Modifying files...');

        $this->reportChanges($result, $output, $dryRun);

        $output->writeln('');
        $output->writeln('> Validating...');

        if ($result->validation->passed) {
            $output->writeln('✓ Validation passed');
        } else {
            $output->writeln('<error>✗ Validation failed</error>');
            foreach ($result->validation->errors as $error) {
                $output->writeln("  - {$error}");
            }
        }

        $output->writeln('');

        if ($result->isSuccessful()) {
            $output->writeln($dryRun
                ? sprintf('<info>Dry run complete.</info> %d file(s) would change. Re-run without --dry-run to apply.', count($result->changes))
                : sprintf('<info>Done.</info> %d file(s) changed.', count($result->changedWrites())));

            return Command::SUCCESS;
        }

        // Anything refused or unwritable is spelled out here - a path Vigen
        // declined to touch is not a silent success.
        foreach ($result->failedWrites() as $write) {
            $output->writeln(sprintf('<error>✗ %s: %s</error>', $write->path, $write->message ?? $write->status));
        }

        return Command::FAILURE;
    }

    private function reportChanges(EngineResult $result, OutputInterface $output, bool $dryRun): void
    {
        // Before this reporting existed the command printed a tick for every
        // planned path regardless of what happened, so a run that wrote
        // nothing at all still looked like a success. Report what is really
        // on disk, and say so explicitly when there is nothing.
        if ($result->changes === []) {
            $output->writeln('  <comment>No file changes were produced.</comment>');

            foreach ($result->diagnostics() as $problem) {
                $output->writeln("  <error>- {$problem}</error>");
            }

            return;
        }

        if ($dryRun) {
            foreach ($result->changes as $change) {
                $output->writeln(sprintf('  would %-8s %s', $change->action, $change->path));
            }

            return;
        }

        foreach ($result->writes as $write) {
            $output->writeln('  ' . $this->describeWrite($write));
        }
    }

    private function describeWrite(WriteResult $write): string
    {
        $detail = $write->message !== null ? " <comment>({$write->message})</comment>" : '';

        return match ($write->status) {
            WriteResult::STATUS_CREATED => "<info>✓ created</info>  {$write->path}",
            WriteResult::STATUS_MODIFIED => "<info>✓ modified</info> {$write->path}",
            WriteResult::STATUS_DELETED => "<info>✓ deleted</info>  {$write->path}",
            WriteResult::STATUS_REJECTED => "<error>✗ rejected</error> {$write->path}{$detail}",
            WriteResult::STATUS_FAILED => "<error>✗ failed</error>   {$write->path}{$detail}",
            default => "• unchanged {$write->path}{$detail}",
        };
    }
}
