<?php

declare(strict_types=1);

namespace Vigen\Http;

/**
 * Middleware wraps a route. Call $next($request) to continue the chain and
 * return its response, or return your own response to short-circuit.
 *
 *   class Authenticate implements Middleware
 *   {
 *       public function handle(Request $request, callable $next): Response
 *       {
 *           if (! Auth::check()) {
 *               return Response::redirect('/login');
 *           }
 *
 *           return $next($request);
 *       }
 *   }
 */
interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}
