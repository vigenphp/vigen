<?php

declare(strict_types=1);

namespace Vigen\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Launches PHP's built-in server, shared by `vigen serve` (the application)
 * and `vigen gui` (the chatbox).
 */
final class ServerProcess
{
    /**
     * @param list<string> $command
     */
    public static function start(array $command, string $basePath, OutputInterface $output): int
    {
        // Merge into the inherited environment instead of replacing it.
        // On Windows, passing an $env array to proc_open() replaces the child's
        // entire environment; dropping SystemRoot breaks Winsock, so the child
        // `php -S` fails with "Failed to listen ... (reason: ?)".
        $env = array_merge(getenv(), ['VIGEN_BASE_PATH' => $basePath]);

        $process = proc_open(
            $command,
            [STDIN, STDOUT, STDERR],
            $pipes,
            $basePath,
            $env
        );

        if (! is_resource($process)) {
            $output->writeln('<error>Failed to start the built-in server.</error>');

            return Command::FAILURE;
        }

        return proc_close($process);
    }
}
