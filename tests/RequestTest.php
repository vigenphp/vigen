<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use Vigen\Http\Request;

/**
 * No database and no filesystem, so unlike most of the suite this runs on any
 * PHP build. That matters here: two of these cover behaviour that every
 * generated login and edit form depends on, and both were missing.
 */
final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_FILES = $this->files;
    }

    public function testInputSearchesTheBodyThenTheQueryString(): void
    {
        $request = new Request('POST', '/search', ['page' => '2'], ['term' => 'ada']);

        self::assertSame('ada', $request->input('term'));
        self::assertSame('2', $request->input('page'));
        self::assertNull($request->input('missing'));
        self::assertSame('fallback', $request->input('missing', 'fallback'));
    }

    /**
     * A generated controller reads posted form fields with post(). A missing
     * method is a fatal error rather than a null, so the whole login and
     * registration path dies without this.
     */
    public function testPostReadsTheBodyOnly(): void
    {
        $request = new Request(
            'POST',
            '/login',
            ['email' => 'from-query@example.com'],
            ['email' => 'posted@example.com']
        );

        self::assertSame('posted@example.com', $request->post('email'));
        self::assertNull($request->post('absent'));
        self::assertSame('fallback', $request->post('absent', 'fallback'));
    }

    public function testPostDoesNotSeeQueryParameters(): void
    {
        $request = new Request('POST', '/login', ['email' => 'from-query@example.com'], []);

        self::assertNull($request->post('email'));
        self::assertSame('from-query@example.com', $request->input('email'));
    }

    public function testPostSupportsDotNotation(): void
    {
        $request = new Request('POST', '/register', [], ['user' => ['email' => 'ada@example.com']]);

        self::assertSame('ada@example.com', $request->post('user.email'));
    }

    /**
     * An HTML form can only send GET or POST, so an edit or delete form
     * reaches a put()/delete() route by posting a hidden _method field -
     * which is exactly what generated views write. Nothing honoured it, so
     * every one of those forms answered 405.
     */
    public function testAPostedMethodFieldRewritesTheVerb(): void
    {
        self::assertSame('PUT', $this->fromGlobals('POST', ['_method' => 'PUT'])->method());
        self::assertSame('PATCH', $this->fromGlobals('POST', ['_method' => 'PATCH'])->method());
        self::assertSame('DELETE', $this->fromGlobals('POST', ['_method' => 'DELETE'])->method());
    }

    public function testTheMethodFieldIsCaseInsensitive(): void
    {
        self::assertSame('DELETE', $this->fromGlobals('POST', ['_method' => 'delete'])->method());
    }

    public function testAnUnrecognisedMethodFieldIsIgnored(): void
    {
        self::assertSame('POST', $this->fromGlobals('POST', ['_method' => 'TRACE'])->method());
        self::assertSame('POST', $this->fromGlobals('POST', [])->method());
    }

    /**
     * Only POST is rewritten. A GET carrying ?_method=DELETE - a link, an
     * <img>, a prefetcher following markup - must stay a GET rather than
     * become a destructive request nobody asked for.
     */
    public function testOnlyAPostCanBeSpoofed(): void
    {
        self::assertSame('GET', $this->fromGlobals('GET', ['_method' => 'DELETE'])->method());
    }

    public function testTheRequestPathIgnoresTheQueryString(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users?page=2';
        $_GET = [];
        $_POST = [];

        self::assertSame('/users', Request::fromGlobals()->path());
    }

    public function testHasIsFalseForAnEmptyString(): void
    {
        $request = new Request('POST', '/login', [], ['email' => '', 'name' => 'Ada']);

        self::assertFalse($request->has('email'));
        self::assertTrue($request->has('name'));
    }

    public function testOnlyPicksTheNamedKeysThatArePresent(): void
    {
        $request = new Request('POST', '/users', [], ['name' => 'Ada', 'email' => 'ada@example.com']);

        self::assertSame(['name' => 'Ada'], $request->only(['name', 'absent']));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fromGlobals(string $method, array $body): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = '/users/1';
        $_GET = [];
        $_POST = $body;
        $_FILES = [];

        return Request::fromGlobals();
    }
}
