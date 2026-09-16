<?php

declare(strict_types=1);

namespace Vigen\Auth\Middleware;

use Vigen\Auth\Auth;
use Vigen\Http\Middleware;
use Vigen\Http\Request;
use Vigen\Http\Response;

/**
 * The inverse of Authenticate: sends an already-logged-in user away from
 * /login and /register, so signing in twice does not land on a form.
 */
class RedirectIfAuthenticated implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::guest()) {
            return $next($request);
        }

        return Response::redirect((string) config('auth.home_path', '/'));
    }
}
