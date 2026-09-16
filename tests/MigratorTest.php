<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Database\Connection;
use Vigen\Database\Migrator;

#[RequiresPhpExtension('pdo_sqlite')]
final class MigratorTest extends TestCase
{
    use CreatesTempProject;

    private Connection $connection;

    private string $migrations;

    protected function setUp(): void
    {
        $this->createTempProject();
        $this->migrations = $this->tempProject . '/database/migrations';

        $this->connection = new Connection(
            ['driver' => 'sqlite', 'database' => ':memory:'],
            $this->tempProject
        );

        Connection::swap($this->connection);
    }

    protected function tearDown(): void
    {
        Connection::swap(null);
        $this->removeTempProject();
    }

    public function testItRunsPendingMigrationsInFilenameOrder(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->writeMigration('2026_01_02_000000_create_posts_table.php', 'posts');

        $ran = $this->migrator()->run();

        self::assertSame(
            ['2026_01_01_000000_create_users_table.php', '2026_01_02_000000_create_posts_table.php'],
            $ran
        );
        self::assertTrue($this->hasTable('users'));
        self::assertTrue($this->hasTable('posts'));
    }

    /**
     * Running migrate twice must be a no-op, or every deploy would try to
     * create the same tables again.
     */
    public function testRunningTwiceAppliesNothingTheSecondTime(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');

        $this->migrator()->run();

        self::assertSame([], $this->migrator()->pending());
        self::assertSame([], $this->migrator()->run());
    }

    public function testOnlyNewMigrationsRunOnASubsequentCall(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->migrator()->run();

        $this->writeMigration('2026_01_02_000000_create_posts_table.php', 'posts');

        self::assertSame(['2026_01_02_000000_create_posts_table.php'], $this->migrator()->run());
    }

    public function testRollbackRunsTheDownClosureAndForgets(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->migrator()->run();

        $rolled = $this->migrator()->rollback();

        self::assertSame(['2026_01_01_000000_create_users_table.php'], $rolled);
        self::assertFalse($this->hasTable('users'));
        self::assertSame([], $this->migrator()->applied());
    }

    public function testRollbackStepsBackSeveralMigrations(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->writeMigration('2026_01_02_000000_create_posts_table.php', 'posts');
        $this->migrator()->run();

        $rolled = $this->migrator()->rollback(2);

        self::assertCount(2, $rolled);
        self::assertFalse($this->hasTable('users'));
        self::assertFalse($this->hasTable('posts'));
    }

    public function testARolledBackMigrationCanBeReRun(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->migrator()->run();
        $this->migrator()->rollback();

        self::assertSame(['2026_01_01_000000_create_users_table.php'], $this->migrator()->run());
        self::assertTrue($this->hasTable('users'));
    }

    /**
     * A migration that throws must not be recorded, or the broken migration
     * would be marked done and never retried.
     */
    public function testAFailingMigrationIsNotRecorded(): void
    {
        $this->writeFixture(
            'database/migrations/2026_01_01_000000_broken.php',
            "<?php\n\nreturn [\n    'up' => function (\$db): void {\n"
            . "        \$db->statement('THIS IS NOT SQL');\n    },\n];\n"
        );

        try {
            $this->migrator()->run();
        } catch (\Throwable) {
            // The driver's exception type is not ours to assert on.
        }

        self::assertSame([], $this->migrator()->applied());
        self::assertSame(['2026_01_01_000000_broken.php'], $this->migrator()->pending());
    }

    public function testAMigrationWithoutAnUpCallableIsRefused(): void
    {
        $this->writeFixture('database/migrations/2026_01_01_000000_bad.php', "<?php\n\nreturn ['nope' => true];\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/callable "up" key/');

        $this->migrator()->run();
    }

    public function testIsAppliedReportsEachMigration(): void
    {
        $this->writeMigration('2026_01_01_000000_create_users_table.php', 'users');
        $this->migrator()->run();

        self::assertTrue($this->migrator()->isApplied('2026_01_01_000000_create_users_table.php'));
        self::assertFalse($this->migrator()->isApplied('2026_01_02_000000_other.php'));
    }

    public function testAMissingMigrationsDirectoryIsNotAnError(): void
    {
        self::assertSame([], $this->migrator()->files());
        self::assertSame([], $this->migrator()->run());
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->connection, $this->migrations);
    }

    /**
     * A migration file in exactly the shape Vigen documents: it returns an
     * array with "up" and "down" closures that receive the connection.
     */
    private function writeMigration(string $filename, string $table): void
    {
        $create = "CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT)";
        $drop = "DROP TABLE {$table}";

        $this->writeFixture(
            'database/migrations/' . $filename,
            <<<PHP
            <?php

            return [
                'up' => function (\\Vigen\\Database\\Connection \$db): void {
                    \$db->statement('{$create}');
                },
                'down' => function (\\Vigen\\Database\\Connection \$db): void {
                    \$db->statement('{$drop}');
                },
            ];

            PHP
        );
    }

    private function hasTable(string $table): bool
    {
        $rows = $this->connection->select(
            "select name from sqlite_master where type = 'table' and name = ?",
            [$table]
        );

        return $rows !== [];
    }
}
