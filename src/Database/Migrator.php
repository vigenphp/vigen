<?php

declare(strict_types=1);

namespace Vigen\Database;

use RuntimeException;

/**
 * Runs migration files in filename order and records what has run, so
 * `vigen migrate` is safe to call repeatedly.
 *
 * A migration file is plain PHP that returns `['up' => fn, 'down' => fn]`.
 * There is no schema builder: generated migrations write real SQL, which is
 * one less API for a model to get wrong.
 */
class Migrator
{
    private const TABLE = 'migrations';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
    ) {
    }

    /**
     * @return list<string> the migrations that ran
     */
    public function run(): array
    {
        $this->ensureRepository();

        $ran = [];

        foreach ($this->pending() as $file) {
            $this->runFile($file);
            $ran[] = $file;
        }

        return $ran;
    }

    /**
     * Run one named migration, recording it. Split out from run() so the CLI
     * can report which file failed rather than blaming the whole batch.
     */
    public function runFile(string $file): void
    {
        $this->ensureRepository();

        $migration = $this->load($file);

        $this->connection->transaction(function () use ($migration): void {
            ($migration['up'])($this->connection);
        });

        $this->record($file);
    }

    /**
     * Roll back the most recent batch, or the last $steps migrations.
     *
     * @return list<string> the migrations that were rolled back
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();

        $rolledBack = [];

        foreach ($this->applied(limit: max(1, $steps)) as $file) {
            if (! is_file($this->path . DIRECTORY_SEPARATOR . $file)) {
                throw new RuntimeException(sprintf(
                    'Cannot roll back [%s]: the migration file is missing.',
                    $file
                ));
            }

            $migration = $this->load($file);

            $this->connection->transaction(function () use ($migration): void {
                if (isset($migration['down']) && is_callable($migration['down'])) {
                    ($migration['down'])($this->connection);
                }
            });

            $this->forget($file);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    /**
     * @return list<string> filenames (no directory) of migrations not yet run
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->files(),
            static fn (string $file): bool => ! in_array($file, $applied, true)
        ));
    }

    public function isApplied(string $file): bool
    {
        return in_array($file, $this->applied(), true);
    }

    /**
     * @return list<string> filenames, newest first
     */
    public function applied(?int $limit = null): array
    {
        // Ensuring here, rather than only in the callers, is what makes a
        // brand-new database work at all. `vigen migrate` asks pending() for
        // its work list *before* it runs anything, and pending() is built on
        // this method - so with the ensure living only in run() and runFile(),
        // the very first migrate against an empty database died on
        // "Table 'vigen.migrations' doesn't exist" before any migration file
        // had a chance to create the table. Every other reader of the table
        // (pending, isApplied, `migrate status`, rollback) goes through here,
        // so this one call covers them all. ensureRepository() is a no-op
        // once the table exists.
        $this->ensureRepository();

        $sql = sprintf(
            'select migration from %s order by id desc',
            $this->connection->quoteIdentifier(self::TABLE)
        );

        if ($limit !== null) {
            $sql .= ' limit ' . max(1, $limit);
        }

        $rows = $this->connection->select($sql);

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    /**
     * @return list<string> all migration filenames, in run order
     */
    public function files(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return [];
        }

        $names = array_map(static fn (string $path): string => basename($path), $files);
        sort($names, SORT_STRING);

        return array_values($names);
    }

    public function repositoryExists(): bool
    {
        try {
            $this->connection->select(sprintf(
                'select 1 from %s limit 1',
                $this->connection->quoteIdentifier(self::TABLE)
            ));

            return true;
        } catch (\PDOException) {
            // Every driver reports "no such table" as a PDOException, so this
            // works without a driver-specific catalogue query.
            return false;
        }
    }

    private function ensureRepository(): void
    {
        if ($this->repositoryExists()) {
            return;
        }

        // Auto-increment columns are the one place the three drivers cannot
        // agree, so the repository table is the one statement that has to be
        // written per driver.
        $id = match ($this->connection->driver()) {
            'mysql' => 'id int auto_increment primary key',
            'pgsql' => 'id serial primary key',
            default => 'id integer primary key autoincrement',
        };

        $this->connection->statement(sprintf(
            'create table %s (
                %s,
                migration varchar(255) not null,
                batch integer not null default 1,
                created_at varchar(32)
            )',
            $this->connection->quoteIdentifier(self::TABLE),
            $id
        ));
    }

    private function record(string $file): void
    {
        $this->connection->statement(
            sprintf(
                'insert into %s (migration, batch, created_at) values (?, ?, ?)',
                $this->connection->quoteIdentifier(self::TABLE)
            ),
            [$file, $this->currentBatch(), date('Y-m-d H:i:s')]
        );
    }

    private function forget(string $file): void
    {
        $this->connection->statement(
            sprintf(
                'delete from %s where migration = ?',
                $this->connection->quoteIdentifier(self::TABLE)
            ),
            [$file]
        );
    }

    private function currentBatch(): int
    {
        $rows = $this->connection->select(sprintf(
            'select max(batch) as batch from %s',
            $this->connection->quoteIdentifier(self::TABLE)
        ));

        return ((int) ($rows[0]['batch'] ?? 0)) + 1;
    }

    /**
     * @return array{up: callable, down?: callable}
     */
    private function load(string $file): array
    {
        $path = $this->path . DIRECTORY_SEPARATOR . $file;

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Migration [%s] not found in %s.', $file, $this->path));
        }

        $migration = require $path;

        if (! is_array($migration) || ! isset($migration['up']) || ! is_callable($migration['up'])) {
            throw new RuntimeException(sprintf(
                'Migration [%s] must return an array with a callable "up" key.',
                $file
            ));
        }

        /** @var array{up: callable, down?: callable} $migration */
        return $migration;
    }
}
