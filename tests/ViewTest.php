<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Http\Session;
use Vigen\View\View;

final class ViewTest extends TestCase
{
    use CreatesTempProject;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        View::path($this->createTempProject() . '/resources/views');

        $this->session = [];
        Session::use($this->session);
    }

    protected function tearDown(): void
    {
        View::reset();
        Session::reset();
        $this->removeTempProject();
    }

    public function testDottedNamesResolveToNestedDirectories(): void
    {
        $this->writeFixture('resources/views/auth/login.php', 'LOGIN');

        self::assertSame('LOGIN', View::render('auth.login')->body());
        self::assertTrue(View::exists('auth.login'));
    }

    public function testTopLevelNamesResolve(): void
    {
        $this->writeFixture('resources/views/welcome.php', 'WELCOME');

        self::assertSame('WELCOME', View::render('welcome')->body());
    }

    public function testDataKeysBecomeLocalVariables(): void
    {
        $this->writeFixture('resources/views/greet.php', 'Hello <?= e($name) ?>');

        self::assertSame('Hello Ada', View::render('greet', ['name' => 'Ada'])->body());
    }

    public function testMissingViewReportsThePathItLookedIn(): void
    {
        try {
            View::render('nope.missing');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('nope.missing', $e->getMessage());
            self::assertStringContainsString('resources/views', str_replace('\\', '/', $e->getMessage()));

            return;
        }

        self::fail('Expected a RuntimeException.');
    }

    public function testExistsIsFalseForAMissingView(): void
    {
        self::assertFalse(View::exists('nope.missing'));
    }

    /**
     * A template that throws must not leave its half-written buffer on the
     * output stack, or the error page itself comes back wrapped in the
     * partial page.
     */
    public function testAFailingTemplateDoesNotLeakItsOutputBuffer(): void
    {
        $this->writeFixture(
            'resources/views/boom.php',
            'BEFORE<?php throw new \RuntimeException("boom"); ?>'
        );

        $depth = ob_get_level();

        try {
            View::render('boom');
        } catch (RuntimeException) {
            self::assertSame($depth, ob_get_level());

            return;
        }

        self::fail('Expected a RuntimeException.');
    }

    public function testPartialsRenderInsideAnotherTemplate(): void
    {
        $this->writeFixture('resources/views/partials/header.php', 'HEADER');
        // Fully qualified: a template is a plain PHP file, so a bare View::
        // resolves to the global \View and the include fails to load.
        $this->writeFixture(
            'resources/views/page.php',
            '<?= \Vigen\View\View::partial("partials.header") ?> + BODY'
        );

        self::assertSame('HEADER + BODY', View::render('page')->body());
    }

    public function testErrorsFromTheLastRequestReachTheTemplate(): void
    {
        // Flash on one request, read on the next - which is exactly what a
        // failed login followed by a re-rendered form does.
        Session::flash('errors', ['email' => 'The email field is required.']);
        Session::startRequest();

        self::assertSame('The email field is required.', View::error('email'));
        self::assertNull(View::error('password'));
    }

    public function testOldInputFromTheLastRequestReachesTheTemplate(): void
    {
        Session::flash('old', ['email' => 'typed@example.com']);
        Session::startRequest();

        self::assertSame('typed@example.com', View::old('email'));
        self::assertSame('', View::old('missing'));
        self::assertSame('fallback', View::old('missing', 'fallback'));
    }

    /**
     * Flash values live for exactly one further request, so a form is not
     * still showing last time's error after a successful submit.
     */
    public function testFlashValuesAreConsumedAfterOneRequest(): void
    {
        Session::flash('errors', ['email' => 'boom']);

        Session::startRequest();

        self::assertSame(['email' => 'boom'], View::errors());

        Session::startRequest();

        self::assertSame([], View::errors());
    }

    public function testEscapeNeutralisesHtml(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            View::escape('<script>alert(1)</script>')
        );
    }

    public function testEscapeQuotesAttributes(): void
    {
        self::assertSame('&quot; onmouseover=&quot;x', View::escape('" onmouseover="x'));
    }
}
