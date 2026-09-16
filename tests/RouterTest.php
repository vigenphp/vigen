<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Core\Container;
use Vigen\Http\Middleware;
use Vigen\Http\NotFoundException;
use Vigen\Http\Request;
use Vigen\Http\Response;
use Vigen\Http\Router;
use Vigen\Tests\Fixtures\BlockingMiddleware;
use Vigen\Tests\Fixtures\GreetingController;
use Vigen\Tests\Fixtures\RecordsOrder;
use Vigen\Tests\Fixtures\GreetingRepository;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(new Container());
    }

    public function testRootRouteMatches(): void
    {
        $this->router->get('/', static fn (): string => 'home');

        self::assertSame('home', $this->dispatch('GET', '/')->body());
    }

    /**
     * A trailing slash is the same resource, so it must not 404.
     */
    public function testTrailingSlashMatchesTheSameRoute(): void
    {
        $this->router->get('/about', static fn (): string => 'about');

        self::assertSame('about', $this->dispatch('GET', '/about/')->body());
    }

    public function testRouteParametersArePassedToTheAction(): void
    {
        $this->router->get('/posts/{id}', static fn (string $id): string => "post {$id}");

        self::assertSame('post 42', $this->dispatch('GET', '/posts/42')->body());
    }

    /**
     * Arguments match by parameter name, not position, so a controller can
     * declare them in whatever order reads best.
     */
    public function testParametersMatchByNameNotPosition(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', static fn (string $postId, string $userId): string => "{$userId}:{$postId}"
        );

        self::assertSame('7:3', $this->dispatch('GET', '/users/7/posts/3')->body());
    }

    public function testOptionalParameterMatchesWhenAbsent(): void
    {
        $this->router->get('/blog/{page?}', static fn (string $page = '1'): string => "page {$page}");

        self::assertSame('page 1', $this->dispatch('GET', '/blog')->body());
        self::assertSame('page 9', $this->dispatch('GET', '/blog/9')->body());
    }

    public function testRequestIsInjectedIntoActionsThatTypeHintIt(): void
    {
        $this->router->get('/whoami', static fn (Request $request): string => $request->method());

        self::assertSame('GET', $this->dispatch('GET', '/whoami')->body());
    }

    /**
     * A parameter the route did not capture, declared nullable, arrives as
     * null rather than raising - so an action can be reused across routes.
     */
    public function testUncapturedNullableParameterArrivesAsNull(): void
    {
        $this->router->get('/n/{value}', static fn (string $value, ?string $missing = null): string => $value . '/' . var_export($missing, true));

        self::assertSame('5/NULL', $this->dispatch('GET', '/n/5')->body());
    }

    public function testDifferentVerbsOnTheSamePathDoNotCollide(): void
    {
        $this->router->get('/login', static fn (): string => 'form');
        $this->router->post('/login', static fn (): string => 'submitted');

        self::assertSame('form', $this->dispatch('GET', '/login')->body());
        self::assertSame('submitted', $this->dispatch('POST', '/login')->body());
    }

    /**
     * A 405 that names the verbs it does accept turns a silent failure into a
     * one-line fix.
     */
    public function testMethodMismatchReturns405AndAnAllowHeader(): void
    {
        $this->router->post('/login', static fn (): string => 'submitted');

        $response = $this->dispatch('GET', '/login');

        self::assertSame(405, $response->status());
        self::assertSame('POST', $response->header('Allow'));
        self::assertStringContainsString('POST', $response->body());
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $this->router->get('/', static fn (): string => 'home');

        $this->expectException(NotFoundException::class);

        $this->dispatch('GET', '/nope');
    }

    public function testNotFoundCarriesTheMethodAndPath(): void
    {
        try {
            $this->dispatch('DELETE', '/nope');
        } catch (NotFoundException $e) {
            self::assertSame('DELETE', $e->method());
            self::assertSame('/nope', $e->path());

            return;
        }

        self::fail('Expected a NotFoundException.');
    }

    public function testControllerActionsAreInvoked(): void
    {
        $this->router->get('/greet/{name}', [GreetingController::class, 'greet']);

        self::assertSame('Hello Ada', $this->dispatch('GET', '/greet/Ada')->body());
    }

    public function testControllerWithConstructorDependenciesIsAutowired(): void
    {
        $this->router->get('/greet', [GreetingController::class, 'greetDefault']);

        self::assertSame('Hello world', $this->dispatch('GET', '/greet')->body());
    }

    public function testMissingControllerProducesAnActionableError(): void
    {
        $this->router->get('/broken', ['App\\Http\\Controllers\\NopeController', 'index']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->dispatch('GET', '/broken');
    }

    public function testMiddlewareWrapsTheActionInRegistrationOrder(): void
    {
        $this->router->alias('one', RecordsOrder::class);
        RecordsOrder::reset();

        $this->router->get('/ordered', static function (): string {
            RecordsOrder::add('action');

            return 'done';
        }, ['one']);

        $this->dispatch('GET', '/ordered');

        self::assertSame(['before', 'action', 'after'], RecordsOrder::$log);
    }

    public function testMiddlewareCanShortCircuitTheAction(): void
    {
        $this->router->alias('block', BlockingMiddleware::class);
        $reached = false;

        $this->router->get('/blocked', static function () use (&$reached): string {
            $reached = true;

            return 'reached';
        }, ['block']);

        $response = $this->dispatch('GET', '/blocked');

        self::assertSame(403, $response->status());
        self::assertFalse($reached);
    }

    public function testMiddlewareCanBeReferencedByClassName(): void
    {
        $this->router->get('/direct', static fn (): string => 'ok', [BlockingMiddleware::class]);

        self::assertSame(403, $this->dispatch('GET', '/direct')->status());
    }

    public function testUnknownMiddlewareAliasProducesAnActionableError(): void
    {
        $this->router->get('/nope', static fn (): string => 'ok', ['typo']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Register an alias/');

        $this->dispatch('GET', '/nope');
    }

    public function testStringResultsBecomeHtmlResponses(): void
    {
        $this->router->get('/text', static fn (): string => '<p>hi</p>');

        $response = $this->dispatch('GET', '/text');

        self::assertSame('<p>hi</p>', $response->body());
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
    }

    public function testArrayResultsBecomeJsonResponses(): void
    {
        $this->router->get('/api', static fn (): array => ['ok' => true]);

        $response = $this->dispatch('GET', '/api');

        self::assertSame('{"ok":true}', $response->body());
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));
    }

    public function testNullResultsBecomeEmptyResponses(): void
    {
        $this->router->get('/nothing', static fn (): null => null);

        self::assertSame(204, $this->dispatch('GET', '/nothing')->status());
    }

    public function testRoutesAreListedInRegistrationOrder(): void
    {
        $this->router->get('/', static fn (): string => 'a');
        $this->router->post('/login', static fn (): string => 'b');

        $labels = array_map(
            static fn ($route): string => $route->method . ' ' . $route->uri,
            $this->router->routes()
        );

        self::assertSame(['GET /', 'POST /login'], $labels);
    }

    /**
     * Route URIs are compiled to regexes, so a literal '.' must not act as a
     * wildcard - otherwise /file.json would match /fileXjson.
     */
    #[DataProvider('pathMatchingProvider')]
    public function testPathMatchingIsExact(string $registered, string $requested, bool $matches): void
    {
        $this->router->get($registered, static fn (): string => 'hit');

        self::assertSame($matches, $this->router->match('GET', $requested) !== null);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function pathMatchingProvider(): array
    {
        return [
            'exact' => ['/about', '/about', true],
            'different literal' => ['/about', '/contact', false],
            'dot is literal' => ['/file.json', '/fileXjson', false],
            'regex metachar is literal' => ['/a+b', '/aab', false],
            'prefix is not a match' => ['/post', '/posts', false],
            'parameter does not span a slash' => ['/posts/{id}', '/posts/1/edit', false],
            'parameter takes one segment' => ['/posts/{id}', '/posts/1', true],
        ];
    }

    /**
     * A literal segment must beat a {placeholder}, in either registration
     * order.
     *
     * Generated route files put '/users/{id}' before '/users/create' - the
     * natural reading order - and first-match-wins then sent GET /users/create
     * to show(int $id) with the string "create": a TypeError, under strict
     * types, on a route that had been declared perfectly correctly.
     */
    public function testALiteralSegmentBeatsARouteParameter(): void
    {
        $this->router->get('/users/{id}', static fn (string $id): string => "show {$id}");
        $this->router->get('/users/create', static fn (): string => 'create form');

        self::assertSame('create form', $this->dispatch('GET', '/users/create')->body());
        self::assertSame('show 7', $this->dispatch('GET', '/users/7')->body());
    }

    public function testTheParameterStillWinsWhenNothingElseMatches(): void
    {
        $this->router->get('/users/create', static fn (): string => 'create form');
        $this->router->get('/users/{id}', static fn (string $id): string => "show {$id}");

        self::assertSame('show 7', $this->dispatch('GET', '/users/7')->body());
        self::assertSame('create form', $this->dispatch('GET', '/users/create')->body());
    }

    /**
     * Specificity is decided per segment, at the first point of difference -
     * not by counting placeholders, which would pick the wrong route here:
     * both have two, and the tie is broken by the third segment.
     */
    public function testSpecificityIsDecidedAtTheFirstDifferingSegment(): void
    {
        $this->router->get('/users/{id}/{action}', static fn (string $id, string $action): string => "{$action} {$id}");
        $this->router->get('/users/{id}/edit', static fn (string $id): string => "edit {$id}");

        self::assertSame('edit 7', $this->dispatch('GET', '/users/7/edit')->body());
        self::assertSame('show 7', $this->dispatch('GET', '/users/7/show')->body());
    }

    /**
     * Two routes that match equally well are separated by registration order,
     * as they always were.
     */
    public function testEquallySpecificRoutesKeepRegistrationOrder(): void
    {
        $this->router->get('/users/{id}', static fn (string $id): string => "first {$id}");
        $this->router->get('/users/{name}', static fn (string $name): string => "second {$name}");

        self::assertSame('first 7', $this->dispatch('GET', '/users/7')->body());
    }

    private function dispatch(string $method, string $path): Response
    {
        return $this->router->dispatch(new Request($method, $path));
    }
}
