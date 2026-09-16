<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

use Vigen\Http\Middleware;
use Vigen\Http\Request;
use Vigen\Http\Response;

/**
 * A middleware that always refuses, for testing that a route's action is never
 * reached when middleware short-circuits.
 */
final class BlockingMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::html('blocked', 403);
    }
}
