<?php

declare(strict_types=1);

namespace Vigen\Http;

use Closure;

/**
 * A single registered route.
 */
final class Route
{
    /**
     * @param Closure|array{0: class-string, 1: string} $action
     * @param list<string>                              $middleware middleware aliases, in run order
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly Closure|array $action,
        public readonly array $middleware = [],
    ) {
    }
}
