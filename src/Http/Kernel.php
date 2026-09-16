<?php

declare(strict_types=1);

namespace Vigen\Http;

use Throwable;
use Vigen\Core\Application;
use Vigen\Core\Container;
use Vigen\View\View;

/**
 * Turns an incoming Request into a Response.
 *
 * This is what a project's public/index.php boots. Its job is to load the
 * route file, dispatch through the router's middleware, and - just as
 * importantly - translate every failure into a page that says what went
 * wrong. A 404 lists the routes that do exist; a 419 shows the CSRF field the
 * form is missing; a 500 logs the trace and, in debug mode, prints it.
 */
class Kernel
{
    /**
     * Middleware aliases every Vigen project has, without declaring them.
     * config/app.php's "middleware" key is merged over this, so a project can
     * add its own or point an alias at its own class.
     */
    private const DEFAULT_MIDDLEWARE = [
        'auth' => \Vigen\Auth\Middleware\Authenticate::class,
        'guest' => \Vigen\Auth\Middleware\RedirectIfAuthenticated::class,
    ];

    /** @var array<string, class-string<Middleware>> */
    private array $middlewareAliases;

    private ?Router $router = null;

    public function __construct(private readonly Application $app)
    {
        $configured = config('app.middleware', []);
        $configured = is_array($configured) ? $configured : [];

        /** @var array<string, class-string<Middleware>> $aliases */
        $aliases = array_merge(self::DEFAULT_MIDDLEWARE, $configured);

        $this->middlewareAliases = $aliases;
    }

    /**
     * The application's shared container, so anything bound on the app is
     * resolvable by a controller.
     */
    public function container(): Container
    {
        return $this->app->container();
    }

    /**
     * @param class-string<Middleware> $class
     */
    public function middleware(string $alias, string $class): void
    {
        $this->middlewareAliases[$alias] = $class;
        $this->router = null;
    }

    /**
     * The router, with the project's route file loaded into it. Built once
     * per process - the route file is `require`d exactly once.
     */
    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $router = new Router($this->container(), $this->middlewareAliases);
        $file = $this->routesFile();

        if (is_file($file)) {
            // $router is a parameter of this closure, which is what puts a
            // $router variable in scope for the route file.
            (static function (Router $router) use ($file): void {
                require $file;
            })($router);
        }

        return $this->router = $router;
    }

    public function routesFile(): string
    {
        $configured = (string) config('app.paths.routes', 'routes/web.php');

        return $this->app->basePath() . '/' . ltrim($configured, '/');
    }

    /**
     * The entry point for a real HTTP request.
     */
    public function handleFromGlobals(): Response
    {
        Session::boot();

        return $this->handle(Request::fromGlobals());
    }

    public function handle(Request $request): Response
    {
        // One request starts here, so this is where last request's flash
        // values move into place.
        Session::startRequest();

        try {
            $this->guardCsrf($request);

            return $this->router()->dispatch($request);
        } catch (ValidationException $e) {
            return $this->validationFailed($request, $e);
        } catch (TokenMismatchException) {
            return $this->tokenMismatch();
        } catch (NotFoundException $e) {
            return $this->notFound($request, $e);
        } catch (Throwable $e) {
            return $this->serverError($e);
        }
    }

    /**
     * Reject a state-changing request that did not come from one of our own
     * forms. JSON clients are exempt, matching the usual convention that an
     * API authenticates with a token rather than a session cookie.
     */
    private function guardCsrf(Request $request): void
    {
        if (! (bool) config('app.csrf', true)) {
            return;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        if ($request->expectsJson()) {
            return;
        }

        $token = $request->input('_token');

        if (! Session::verifyToken(is_string($token) ? $token : null)) {
            throw new TokenMismatchException();
        }
    }

    private function validationFailed(Request $request, ValidationException $e): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        Session::flash('errors', $e->firstErrors());
        Session::flash('old', $this->safeOldInput($request));

        return Response::redirect($this->backTo($request));
    }

    /**
     * Never flash credentials back into the session. The session file is on
     * disk and the values would be re-rendered into the form.
     *
     * @return array<string, mixed>
     */
    private function safeOldInput(Request $request): array
    {
        $safe = [];

        foreach ($request->all() as $key => $value) {
            $lower = strtolower((string) $key);

            if ($lower === '_token' || str_contains($lower, 'password') || str_contains($lower, 'secret')) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    /**
     * Send the user back where the form was. Only the path is reused, never
     * the full referer, so this can never become an open redirect.
     */
    private function backTo(Request $request): string
    {
        $referer = (string) $request->header('Referer');

        if ($referer === '') {
            return '/';
        }

        $path = parse_url($referer, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function tokenMismatch(): Response
    {
        return Response::html(
            '<h1>419 Page Expired</h1>'
            . '<p>The form was submitted without a valid CSRF token, so Vigen rejected it.</p>'
            . '<p>Add this inside the &lt;form&gt;:</p>'
            . '<pre>&lt;input type="hidden" name="_token" value="&lt;?= e(Vigen\Http\Session::token()) ?&gt;"&gt;</pre>',
            419
        );
    }

    private function notFound(Request $request, NotFoundException $e): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['message' => 'Not Found', 'path' => $e->path()], 404);
        }

        if (View::exists('errors.404')) {
            return View::render('errors.404', [
                'method' => $e->method(),
                'path' => $e->path(),
                'routes' => $this->routeLabels(),
            ])->withStatus(404);
        }

        return Response::html($this->defaultNotFoundPage($e), 404);
    }

    /**
     * @return list<string>
     */
    private function routeLabels(): array
    {
        return array_map(
            static fn (Route $route): string => $route->method . ' ' . $route->uri,
            $this->router()->routes()
        );
    }

    /**
     * A 404 that lists the routes which do exist. When a generated route
     * never appears, seeing the real route table is the fastest way to find
     * out why.
     */
    private function defaultNotFoundPage(NotFoundException $e): string
    {
        $routes = $this->router()->routes();

        if ($routes === []) {
            $list = '<p>No routes are registered at all. Add some to <code>'
                . e($this->relativeRoutesFile()) . '</code>.</p>';
        } else {
            $list = '<ul>' . implode('', array_map(
                static fn (Route $route): string => '<li><code>'
                    . e($route->method . ' ' . $route->uri) . '</code></li>',
                $routes
            )) . '</ul>';
        }

        return '<h1>404 Not Found</h1>'
            . '<p>No route matches <code>' . e($e->method() . ' ' . $e->path()) . '</code>.</p>'
            . '<p>Registered routes:</p>' . $list;
    }

    private function relativeRoutesFile(): string
    {
        return ltrim(str_replace($this->app->basePath(), '', $this->routesFile()), '/\\');
    }

    private function serverError(Throwable $e): Response
    {
        $this->log($e);

        if (! (bool) config('app.debug', false)) {
            return Response::html('<h1>500 Internal Server Error</h1>', 500);
        }

        return Response::html(
            '<h1>500 ' . e($e::class) . '</h1>'
            . '<p>' . e($e->getMessage()) . '</p>'
            . '<pre>' . e($e->getTraceAsString()) . '</pre>',
            500
        );
    }

    private function log(Throwable $e): void
    {
        $dir = $this->app->basePath() . '/storage/logs';

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $dir . '/vigen.log',
            sprintf(
                "[%s] %s: %s in %s:%d\n",
                date('Y-m-d H:i:s'),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ),
            FILE_APPEND
        );
    }
}
