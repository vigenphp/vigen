<?php

declare(strict_types=1);

namespace Vigen\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vigen\Core\Container;
use Vigen\Tests\Fixtures\GreetingController;
use Vigen\Tests\Fixtures\GreetingRepository;

final class ContainerTest extends TestCase
{
    public function testItResolvesAClassWithNoConstructor(): void
    {
        self::assertInstanceOf(GreetingRepository::class, (new Container())->get(GreetingRepository::class));
    }

    public function testItAutowiredsConstructorDependencies(): void
    {
        $controller = (new Container())->get(GreetingController::class);

        self::assertInstanceOf(GreetingController::class, $controller);
        self::assertSame('Hello Ada', $controller->greet('Ada'));
    }

    /**
     * get() caches so a shared dependency is built once; make() always builds
     * fresh, which is what a controller needs - two requests must not share
     * one controller instance.
     */
    public function testGetCachesButMakeDoesNot(): void
    {
        $container = new Container();

        self::assertSame(
            $container->get(GreetingRepository::class),
            $container->get(GreetingRepository::class)
        );

        self::assertNotSame(
            $container->make(GreetingRepository::class),
            $container->make(GreetingRepository::class)
        );
    }

    public function testAnExplicitBindingWinsOverAutowiring(): void
    {
        $container = new Container();
        $container->bind(GreetingRepository::class, static fn (): GreetingRepository => new GreetingRepository());

        self::assertInstanceOf(GreetingRepository::class, $container->get(GreetingRepository::class));
        self::assertTrue($container->has(GreetingRepository::class));
    }

    public function testInstanceRegistersAnAlreadyBuiltObject(): void
    {
        $container = new Container();
        $repository = new GreetingRepository();
        $container->instance(GreetingRepository::class, $repository);

        self::assertSame($repository, $container->get(GreetingRepository::class));
    }

    public function testUnresolvableScalarParameterIsReportedClearly(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/NeedsAString/');

        $container->get(NeedsAString::class);
    }

    public function testUnknownClassIsReportedClearly(): void
    {
        $this->expectException(RuntimeException::class);

        (new Container())->get('App\\Nope\\Missing');
    }
}

/**
 * A class the container cannot build, because it would have to invent a string.
 */
final class NeedsAString
{
    public function __construct(private readonly string $value)
    {
    }
}
