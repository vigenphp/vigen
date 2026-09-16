<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

use Vigen\Http\Middleware;
use Vigen\Http\Request;
use Vigen\Http\Response;

/**
 * A middleware that passes through but records when it ran, so the pipeline
 * order is observable.
 */
final class RecordsOrder implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public static function add(string $entry): void
    {
        self::$log[] = $entry;
    }

    public static function reset(): void
    {
        self::$log = [];
    }

    public function handle(Request $request, callable $next): Response
    {
        self::add('before');

        $response = $next($request);

        self::add('after');

        return $response;
    }
}
