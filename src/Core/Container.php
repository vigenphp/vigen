<?php

declare(strict_types=1);

namespace Vigen\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * A deliberately small service container. Its one job is to build objects
 * whose constructor dependencies are themselves classes - which is what
 * lets a generated controller declare `__construct(private UserRepository $users)`
 * and still be instantiated by the router without any registration.
 *
 * It is not a full DI framework: no interfaces-to-implementations magic, no
 * tagging, no contextual binding. Bind those explicitly when you need them.
 */
class Container
{
    /** @var array<string, Closure|string> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> Guards against a circular dependency recursing forever. */
    private array $building = [];

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * @param Closure|class-string $concrete
     */
    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->instances[$abstract]);
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
    }

    /**
     * Resolve a class, building and caching it on first request.
     *
     * @param class-string $abstract
     */
    public function get(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $object = isset($this->bindings[$abstract])
            ? $this->resolve($this->bindings[$abstract])
            : $this->build($abstract);

        return $this->instances[$abstract] = $object;
    }

    /**
     * Resolve without caching - for objects that should be fresh each time,
     * such as controllers handling a request.
     *
     * A cached instance is deliberately ignored rather than reused. Consulting
     * $this->instances here - which delegating to get() did - hands back
     * whatever an earlier get() happened to build, and sharing one controller
     * between two requests is precisely what this method exists to prevent.
     * Explicit bindings are still honoured, because those say how to build the
     * object rather than which object to hand back.
     *
     * @param class-string $class
     */
    public function make(string $class): object
    {
        return isset($this->bindings[$class])
            ? $this->resolve($this->bindings[$class])
            : $this->build($class);
    }

    /**
     * @param Closure|class-string $concrete
     */
    private function resolve(Closure|string $concrete): object
    {
        return $concrete instanceof Closure ? $concrete($this) : $this->build($concrete);
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): object
    {
        if (! class_exists($class)) {
            throw new RuntimeException("Vigen could not resolve [{$class}]: the class does not exist.");
        }

        if (in_array($class, $this->building, true)) {
            throw new RuntimeException(
                'Vigen found a circular dependency while building [' . $class . ']: '
                . implode(' -> ', [...$this->building, $class])
            );
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new RuntimeException("Vigen cannot instantiate [{$class}] - it is abstract or has no public constructor.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $this->building[] = $class;

        try {
            $arguments = array_map(
                fn (ReflectionParameter $parameter): mixed => $this->resolveParameter($class, $parameter),
                $constructor->getParameters()
            );
        } finally {
            array_pop($this->building);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(string $class, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();

            return $this->get($dependency);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException(sprintf(
            'Vigen cannot build [%s]: parameter $%s is a %s with no default value. '
            . 'Bind it explicitly in the container.',
            $class,
            $parameter->getName(),
            $type instanceof ReflectionNamedType ? $type->getName() : 'mixed'
        ));
    }
}
