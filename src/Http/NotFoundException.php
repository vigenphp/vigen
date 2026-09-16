<?php

declare(strict_types=1);

namespace Vigen\Http;

use RuntimeException;

/**
 * Thrown when no route matches the request. The Kernel catches it and renders
 * a 404 - either the project's own resources/views/errors/404.php, or a
 * built-in page that lists the routes which do exist.
 */
class NotFoundException extends RuntimeException
{
    public function __construct(private readonly string $method, private readonly string $path)
    {
        parent::__construct("No route matches {$method} {$path}.");
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }
}
