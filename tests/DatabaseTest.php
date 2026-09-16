<?php

declare(strict_types=1);

namespace Vigen\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Database\Connection;
use Vigen\Database\QueryBuilder;
use Vigen\Tests\Fixtures\Account;
use Vigen\Tests\Fixtures\Plain;

/**
 * Vigen's default database is SQLite, so every test here needs pdo_sqlite.
 * Without this attribute a build missing the driver reports each test as a
 * "could not find driver" exception - forty failures that read as broken code
 * rather than as one missing extension.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:'], sys_get_temp_dir());
        Connection::swap($this->connection);

        $this->connection->statement('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->connection->statement('CREATE TABLE plain_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL
        )');
    }

    protected function tearDown(): void
    {
        Connection::swap(null);
    }

    public function testCreateInsertsAndReturnsTheModel(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertTrue($account->exists());
        self::assertSame(1, $account->id);
        self::assertSame('Ada', $account->name);
        self::assertSame(1, Account::count());
    }

    public function testFindReturnsTheRowOrNull(): void
    {
        $created = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertSame('Ada', Account::find($created->id)?->name);
        self::assertNull(Account::find(999));
    }

    public function testFindOrFailThrowsWhenAbsent(): void
    {
        $this->expectException(RuntimeException::class);

        Account::findOrFail(999);
    }

    public function testWhereReturnsAMatchingRow(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Account::create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'y']);

        self::assertSame('Grace', Account::where('email', 'grace@example.com')->first()?->name);
        self::assertNull(Account::where('email', 'nobody@example.com')->first());
    }

    /**
     * An unknown column means the model and the migration disagree; failing
     * loudly beats silently accepting anything.
     */
    public function testUnknownColumnIsRefusedAtFillTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not fillable/');

        Account::create(['name' => 'Ada', 'email' => 'a@b.com', 'password' => 'x', 'is_admin' => 1]);
    }

    public function testHiddenColumnsAreRemovedFromArrays(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);

        $array = $account->toArray();

        self::assertArrayNotHasKey('password', $array);
        self::assertArrayHasKey('email', $array);
    }

    /**
     * ...but the value is still readable in code, so Auth can verify it.
     */
    public function testHiddenColumnsRemainReadableAsProperties(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);

        self::assertSame('secret', $account->password);
    }

    public function testTimestampsAreMaintainedAutomatically(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertNotNull($account->created_at);
        self::assertSame($account->created_at, $account->updated_at);
    }

    public function testTimestampsAreSkippedWhenDisabled(): void
    {
        $row = Plain::create(['label' => 'hello']);

        self::assertSame('hello', $row->label);
        self::assertNull($row->created_at);
    }

    public function testUpdateWritesThrough(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertTrue($account->update(['name' => 'Ada Lovelace']));
        self::assertSame('Ada Lovelace', Account::find($account->id)?->name);
    }

    public function testSaveOnAnExistingModelUpdates(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        $account->name = 'Grace';
        $account->save();

        self::assertSame('Grace', Account::find($account->id)?->name);
        self::assertSame(1, Account::count());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $account = Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertTrue($account->delete());
        self::assertFalse($account->exists());
        self::assertSame(0, Account::count());
    }

    public function testAllReturnsEveryRow(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Account::create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'y']);

        self::assertCount(2, Account::all());
    }

    public function testOrderByLimitAndOffset(): void
    {
        foreach (['Ada', 'Grace', 'Alan'] as $index => $name) {
            Account::create(['name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => (string) $index]);
        }

        $names = array_map(
            static fn (Account $account): string => $account->name,
            Account::query()->orderBy('name')->get()
        );

        self::assertSame(['Ada', 'Alan', 'Grace'], $names);

        $descending = array_map(
            static fn (Account $account): string => $account->name,
            Account::query()->orderBy('name', 'desc')->limit(2)->get()
        );

        self::assertSame(['Grace', 'Alan'], $descending);
    }

    public function testWhereInMatchesAnyOfTheGivenValues(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Account::create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'y']);

        self::assertSame(2, Account::query()->whereIn('name', ['Ada', 'Grace'])->count());
        self::assertSame(1, Account::query()->whereIn('name', ['Ada'])->count());
    }

    /**
     * An empty IN () is a syntax error in SQL, and "matches nothing" is the
     * only truthful answer.
     */
    public function testWhereInWithNoValuesMatchesNothing(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertSame(0, Account::query()->whereIn('name', [])->count());
    }

    public function testNullableColumnComparisonBecomesIsNull(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertSame(1, Account::query()->whereNotNull('created_at')->count());
        self::assertSame(0, Account::query()->whereNull('created_at')->count());
    }

    public function testExistsReportsWhetherAnythingMatched(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        self::assertTrue(Account::where('name', 'Ada')->exists());
        self::assertFalse(Account::where('name', 'Nobody')->exists());
    }

    public function testBuilderUpdateAndDeleteAffectOnlyMatchingRows(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Account::create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'y']);

        self::assertSame(1, Account::where('name', 'Ada')->update(['name' => 'Ada L.']));
        self::assertSame(0, Account::where('name', 'Nobody')->update(['name' => 'x']));
        self::assertSame(1, Account::where('name', 'Grace')->delete());
        self::assertSame(1, Account::count());
    }

    /**
     * Values are always bound, never interpolated, so a quote in user input
     * cannot change the statement.
     */
    public function testValuesAreBoundNotInterpolated(): void
    {
        $hostile = "Robert'); DROP TABLE accounts;--";

        Account::create(['name' => $hostile, 'email' => 'bobby@example.com', 'password' => 'x']);

        self::assertSame($hostile, Account::where('name', $hostile)->first()?->name);
        self::assertSame(1, Account::count());
    }

    public function testAnUnsafeIdentifierIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsafe SQL identifier/');

        Account::query()->where('name" OR "1"="1', 'x');
    }

    public function testAnUnsupportedOperatorIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unsupported where operator/');

        Account::query()->where('name', 'union select', 'x');
    }

    public function testTheThreeArgumentFormTakesTheOperatorSecond(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        Account::create(['name' => 'Alan', 'email' => 'alan@example.com', 'password' => 'x']);
        Account::create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'x']);

        // where($column, $operator, $value) - Laravel's order, and therefore
        // the order a generated controller will write.
        $found = Account::where('name', 'like', 'A%')->orderBy('name')->get();

        self::assertSame(['Ada', 'Alan'], array_map(static fn (Account $a): string => $a->name, $found));
    }

    public function testTheOperatorIsNotBoundAsAValue(): void
    {
        Account::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        // A reversed call must not silently become name = 'like'.
        $this->expectException(RuntimeException::class);

        Account::query()->where('name', 'A%', 'like');
    }

    public function testTableNameIsDerivedFromTheClassName(): void
    {
        self::assertSame('accounts', Account::table());
    }

    public function testATransactionRollsBackOnFailure(): void
    {
        try {
            $this->connection->transaction(function (Connection $db): void {
                $db->statement(
                    'insert into accounts (name, email, password) values (?, ?, ?)',
                    ['Ada', 'ada@example.com', 'x']
                );

                throw new RuntimeException('something went wrong');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, Account::count());
    }

    public function testATransactionCommitsOnSuccess(): void
    {
        $this->connection->transaction(function (Connection $db): void {
            $db->statement(
                'insert into accounts (name, email, password) values (?, ?, ?)',
                ['Ada', 'ada@example.com', 'x']
            );
        });

        self::assertSame(1, Account::count());
    }

    public function testHydrateDoesNotApplyFillableRules(): void
    {
        $account = Account::hydrate(['id' => 3, 'name' => 'Ada', 'password' => 'hash']);

        self::assertTrue($account->exists());
        self::assertSame(3, $account->getKey());
    }

    public function testInsertViaTheBuilderReturnsTrue(): void
    {
        self::assertTrue(Account::query()->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']));
        self::assertSame(1, Account::count());
    }

    public function testTheBuilderIsFluent(): void
    {
        self::assertInstanceOf(QueryBuilder::class, Account::query()->where('name', 'x')->orderBy('name')->limit(1));
    }
}
