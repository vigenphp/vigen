<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Auth\Auth;
use Vigen\Auth\Hash;
use Vigen\Core\Application;
use Vigen\Database\Connection;
use Vigen\Http\Session;
use Vigen\Tests\Fixtures\Account;
use Vigen\Tests\Fixtures\Plain;

#[RequiresPhpExtension('pdo_sqlite')]
final class AuthTest extends TestCase
{
    use CreatesTempProject;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $basePath = $this->createTempProject();

        // Written before the application boots, so config() resolves against
        // this scratch project rather than the developer's own.
        $this->writeFixture('config/auth.php', "<?php\n\nreturn [\n"
            . "    'model' => \\Vigen\\Tests\\Fixtures\\Account::class,\n"
            . "    'username' => 'email',\n"
            . "    'login_path' => '/login',\n"
            . "];\n");

        new Application($basePath);

        Connection::swap(new Connection(
            ['driver' => 'sqlite', 'database' => ':memory:'],
            $basePath
        ));

        Connection::current()->statement('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        $this->session = [];
        Session::use($this->session);
        Auth::reset();
    }

    protected function tearDown(): void
    {
        Auth::reset();
        Session::reset();
        Connection::swap(null);
        Application::forget();
        $this->removeTempProject();
    }

    public function testHashRoundTrips(): void
    {
        $hash = Hash::make('correct horse');

        self::assertNotSame('correct horse', $hash);
        self::assertTrue(Hash::check('correct horse', $hash));
        self::assertFalse(Hash::check('wrong', $hash));
    }

    public function testHashUsesPHPDefaultAlgorithm(): void
    {
        self::assertTrue(password_verify('secret', Hash::make('secret')));
    }

    public function testCheckAgainstAnEmptyHashIsFalse(): void
    {
        self::assertFalse(Hash::check('anything', null));
        self::assertFalse(Hash::check('anything', ''));
    }

    public function testAttemptSucceedsWithCorrectCredentials(): void
    {
        $this->makeUser('ada@example.com', 'secret');

        self::assertTrue(Auth::attempt('ada@example.com', 'secret'));
        self::assertTrue(Auth::check());
        self::assertSame('ada@example.com', Auth::user()?->email);
    }

    public function testAttemptFailsWithTheWrongPassword(): void
    {
        $this->makeUser('ada@example.com', 'secret');

        self::assertFalse(Auth::attempt('ada@example.com', 'wrong'));
        self::assertFalse(Auth::check());
        self::assertNull(Auth::user());
    }

    public function testAttemptFailsForAnUnknownAccount(): void
    {
        self::assertFalse(Auth::attempt('nobody@example.com', 'secret'));
        self::assertFalse(Auth::check());
    }

    public function testLoginAcceptsAModelDirectly(): void
    {
        $user = $this->makeUser('ada@example.com', 'secret');

        Auth::login($user);

        self::assertTrue(Auth::check());
        self::assertSame($user->id, Auth::id());
    }

    /**
     * The session stores only the id, so a row changed after login is
     * reflected immediately rather than living on in a stale session.
     */
    public function testUserIsReReadFromTheDatabase(): void
    {
        $user = $this->makeUser('ada@example.com', 'secret');
        Auth::login($user);

        Account::where('id', $user->id)->update(['name' => 'Ada Lovelace']);

        Auth::reset();

        self::assertSame('Ada Lovelace', Auth::user()?->name);
    }

    public function testLogoutClearsTheSession(): void
    {
        Auth::login($this->makeUser('ada@example.com', 'secret'));

        Auth::logout();

        self::assertFalse(Auth::check());
        self::assertTrue(Auth::guest());
        self::assertNull(Auth::id());
    }

    /**
     * A user deleted mid-session must not authenticate, and the dangling id
     * must be dropped rather than re-queried on every request.
     */
    public function testADeletedUserIsNotAuthenticated(): void
    {
        $user = $this->makeUser('ada@example.com', 'secret');
        Auth::login($user);
        $user->delete();

        Auth::reset();

        self::assertFalse(Auth::check());
        self::assertNull(Session::get('vigen_user_id'));
    }

    public function testLoginRotatesTheCsrfToken(): void
    {
        $before = Session::token();

        Auth::login($this->makeUser('ada@example.com', 'secret'));

        self::assertNotSame($before, Session::token());
    }

    public function testAModelThatIsNotAVigenModelIsRejected(): void
    {
        $this->writeFixture('config/auth.php', "<?php\n\nreturn [\n"
            . "    'model' => \\stdClass::class,\n"
            . "    'username' => 'email',\n"
            . "];\n");

        Application::instance()?->config()->reload();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must extend/');

        Auth::attempt('ada@example.com', 'secret');
    }

    public function testAMissingAuthModelIsRejectedClearly(): void
    {
        $this->writeFixture('config/auth.php', "<?php\n\nreturn [\n"
            . "    'model' => 'App\\\\Models\\\\Missing',\n"
            . "];\n");

        Application::instance()?->config()->reload();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        Auth::attempt('ada@example.com', 'secret');
    }

    public function testTheUsernameColumnIsConfigurable(): void
    {
        $this->writeFixture('config/auth.php', "<?php\n\nreturn [\n"
            . "    'model' => \\Vigen\\Tests\\Fixtures\\Plain::class,\n"
            . "    'username' => 'label',\n"
            . "];\n");

        Application::instance()?->config()->reload();

        Connection::current()->statement('CREATE TABLE plain_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL
        )');

        Plain::create(['label' => 'ada@example.com']);

        // Plain has no password column, so a login attempt fails rather than
        // erroring - what matters is that 'label' was the column matched.
        self::assertFalse(Auth::attempt('ada@example.com', 'secret'));
    }

    private function makeUser(string $email, string $password): Account
    {
        return Account::create([
            'name' => 'Ada',
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }
}
