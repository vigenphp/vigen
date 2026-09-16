<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vigen\Core\Application;
use Vigen\Database\Connection;
use Vigen\Database\Migrator;
use Vigen\Project\Scaffolder;

#[AsCommand(name: 'migrate', description: 'Run the application\'s database migrations')]
class MigrateCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'run (default) or rollback', 'run')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'How many migrations to roll back', '1')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Drop every table and re-run all migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->app->basePath();
        $migrationsPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        if (! is_dir($migrationsPath)) {
            $output->writeln('<error>No database/migrations directory found.</error>');
            $output->writeln('Run <info>vigen init</info> to scaffold the project structure.');

            return Command::FAILURE;
        }

        // A project created before config/database.php existed still needs to
        // migrate, so publish the stub rather than failing - and reload the
        // config repository, which was read before the file existed.
        $scaffolder = new Scaffolder($basePath);

        if ($scaffolder->publishConfig() !== []) {
            $this->app->config()->reload();
        }

        try {
            $connection = Connection::fromConfig(null, $basePath);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $migrator = new Migrator($connection, $migrationsPath);

        if ($input->getOption('fresh')) {
            return $this->fresh($migrator, $connection, $output);
        }

        $action = (string) $input->getArgument('action');

        return match ($action) {
            'rollback' => $this->rollback($migrator, $input, $output),
            'status' => $this->status($migrator, $connection, $output),
            'run' => $this->runPending($migrator, $connection, $output),
            default => $this->unknownAction($action, $output),
        };
    }

    /**
     * Not named run(): Symfony\Console\Command::run() is public and is the
     * command's own entry point, so a private run() here is a fatal error at
     * class-declaration time - and making it public would override the method
     * Console uses to execute the command at all.
     */
    private function runPending(Migrator $migrator, Connection $connection, OutputInterface $output): int
    {
        $pending = $migrator->pending();

        if ($pending === []) {
            $output->writeln('<comment>Nothing to migrate.</comment>');

            return Command::SUCCESS;
        }

        $this->describeDatabase($connection, $output);
        $output->writeln(sprintf('Running %d migration(s)...', count($pending)));
        $output->writeln('');

        foreach ($pending as $file) {
            $output->write('  <info>migrating</info> ' . $file . ' ... ');

            try {
                $migrator->runFile($file);
            } catch (\Throwable $e) {
                $output->writeln('<error>FAILED</error>');
                $output->writeln('');
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }

            $output->writeln('<info>done</info>');
        }

        $output->writeln('');
        $output->writeln('<info>Migrations complete.</info>');

        return Command::SUCCESS;
    }

    private function rollback(Migrator $migrator, InputInterface $input, OutputInterface $output): int
    {
        $steps = max(1, (int) $input->getOption('step'));
        $applied = $migrator->applied($steps);

        if ($applied === []) {
            $output->writeln('<comment>Nothing to roll back.</comment>');

            return Command::SUCCESS;
        }

        $rolled = $migrator->rollback($steps);

        foreach ($rolled as $file) {
            $output->writeln('  <info>rolled back</info> ' . $file);
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Rolled back %d migration(s).</info>', count($rolled)));

        return Command::SUCCESS;
    }

    private function status(Migrator $migrator, Connection $connection, OutputInterface $output): int
    {
        $this->describeDatabase($connection, $output);
        $output->writeln('');

        $applied = $migrator->applied();
        $files = $migrator->files();

        if ($files === []) {
            $output->writeln('<comment>No migration files found.</comment>');

            return Command::SUCCESS;
        }

        foreach ($files as $file) {
            $ran = in_array($file, $applied, true);
            $output->writeln(sprintf(
                '  %s %s',
                $ran ? '<info>[x]</info>' : '<comment>[ ]</comment>',
                $file
            ));
        }

        return Command::SUCCESS;
    }

    private function fresh(Migrator $migrator, Connection $connection, OutputInterface $output): int
    {
        $output->writeln('<comment>Dropping all tables...</comment>');

        // Foreign keys have to be suspended while tables are dropped in an
        // arbitrary order, and every driver spells that differently.
        $this->suspendForeignKeys($connection, true);

        // Postgres refuses to drop a table another table still references
        // unless the drop is told to cascade.
        $cascade = $connection->driver() === 'pgsql' ? ' cascade' : '';

        foreach ($this->tables($connection) as $table) {
            $connection->statement(
                'DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table) . $cascade
            );
        }

        $this->suspendForeignKeys($connection, false);

        $output->writeln('<comment>Tables dropped. Re-running migrations.</comment>');
        $output->writeln('');

        return $this->runPending($migrator, $connection, $output);
    }

    private function suspendForeignKeys(Connection $connection, bool $suspend): void
    {
        match ($connection->driver()) {
            'mysql' => $connection->statement(
                'SET FOREIGN_KEY_CHECKS = ' . ($suspend ? '0' : '1')
            ),
            'pgsql' => null, // Postgres has no session-level equivalent.
            default => $connection->statement(
                'PRAGMA foreign_keys = ' . ($suspend ? 'OFF' : 'ON')
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        $rows = match ($connection->driver()) {
            'mysql' => $connection->select('SHOW TABLES'),
            'pgsql' => $connection->select(
                "select tablename as name from pg_tables where schemaname = 'public'"
            ),
            default => $connection->select(
                "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"
            ),
        };

        // SHOW TABLES names the column after the table, so reset() rather than
        // a fixed key.
        return array_map(static fn (array $row): string => (string) reset($row), $rows);
    }

    private function describeDatabase(Connection $connection, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            'Using <info>%s</info> database.',
            $connection->driver()
        ));
    }

    private function unknownAction(string $action, OutputInterface $output): int
    {
        $output->writeln(sprintf('<error>Unknown action [%s].</error>', $action));
        $output->writeln('Try: <info>vigen migrate</info>, <info>vigen migrate rollback</info>, '
            . '<info>vigen migrate status</info> or <info>vigen migrate --fresh</info>.');

        return Command::FAILURE;
    }
}
