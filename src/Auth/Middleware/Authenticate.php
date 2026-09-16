<?php

declare(strict_types=1);

namespace Vigen\Auth\Middleware;

use Vigen\Auth\Auth;
use Vigen\Http\Middleware;
use Vigen\Http\Request;
use Vigen\Http\Response;
use Vigen\Http\Session;

/**
 * Blocks guests from a route. Attach it by alias:
 *
 *     $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
 *
 * The login path comes from config/auth.php so a project that uses a different
 * URL is not sent to a route it does not have.
 */
class Authenticate implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return Response::json(['message' => 'Unauthenticated.'], 401);
        }

        // Remember where they were headed so the login handler can return them
        // there. The path only, never a full URL, so this cannot be turned into
        // an open redirect.
        Session::put('url.intended', $request->path());

        return Response::redirect(self::loginPath());
    }

    public static function loginPath(): string
    {
        return (string) config('auth.login_path', '/login');
    }
}
