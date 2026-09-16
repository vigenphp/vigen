<?php

declare(strict_types=1);

namespace Vigen\Http;

use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Vigen\Core\Container;

/**
 * Matches an incoming request to a registered route and calls its action.
 *
 * Routes are registered by the project's route file, which the Kernel
 * `require`s with $router already in scope:
 *
 *   $router->get('/login', [AuthController::class, 'showLogin']);
 *   $router->post('/login', [AuthController::class, 'login'], ['guest']);
 *   $router->get('/hello/{name}', fn (string $name) => "Hello {$name}");
 *
 * Action arguments are matched by parameter *name* against the {params}
 * captured from the URL, and any parameter typed as Request receives the
 * request itself.
 */
class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, class-string<Middleware>> */
    private array $middlewareAliases;

    /**
     * @param array<string, class-string<Middleware>> $middlewareAliases
     */
    public function __construct(
        private readonly Container $container,
        array $middlewareAliases = [],
    ) {
        $this->middlewareAliases = $middlewareAliases;
    }

    public function get(string $uri, Closure|array $action, array $middleware = []): Route
    {
        return $this->add('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, Closure|array $action, array $middleware = []): Route
    {
        return $this->add('POST', $uri, $action, $middleware);
    }

    public function put(string $uri, Closure|array $action, array $middleware = []): Route
    {
        return $this->add('PUT', $uri, $action, $middleware);
    }

    public function patch(string $uri, Closure|array $action, array $middleware = []): Route
    {
        return $this->add('PATCH', $uri, $action, $middleware);
    }

    public function delete(string $uri, Closure|array $action, array $middleware = []): Route
    {
        return $this->add('DELETE', $uri, $action, $middleware);
    }

    /**
     * @param Closure|array{0: class-string, 1: string} $action
     * @param list<string>                              $middleware
     */
    public function add(string $method, string $uri, Closure|array $action, array $middleware = []): Route
    {
        $route = new Route(strtoupper($method), self::normalise($uri), $action, $middleware);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param class-string<Middleware> $class
     */
    public function alias(string $alias, string $class): void
    {
        $this->middlewareAliases[$alias] = $class;
    }

    public function dispatch(Request $request): Response
    {
        $match = $this->match($request->method(), $request->path());

        if ($match !== null) {
            return $this->run($match[0], $match[1], $request);
        }

        // The path exists but not for this verb. Saying so - and naming the
        // verbs it does accept - is the difference between a five-second fix
        // and an afternoon of guessing.
        $allowed = $this->allowedMethodsFor($request->path());

        if ($allowed !== []) {
            return Response::html(
                sprintf(
                    "<h1>405 Method Not Allowed</h1><p>%s %s is not a route, but %s is.</p>",
                    e($request->method()),
                    e($request->path()),
                    e(implode(', ', $allowed))
                ),
                405
            )->withHeader('Allow', implode(', ', $allowed));
        }

        throw new NotFoundException($request->method(), $request->path());
    }

    /**
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = self::normalise($path);

        $best = null;
        $bestParams = [];

        foreach ($this->routes as $route) {
            if ($route->method !== $method) {
                continue;
            }

            if (preg_match($this->compile($route->uri), $path, $matches) !== 1) {
                continue;
            }

            // A literal segment beats a {placeholder} at the first point where
            // two matching routes disagree; ties keep registration order.
            //
            // Without this, `$router->get('/users/{id}')` declared before
            // `$router->get('/users/create')` - the order a model naturally
            // writes, and the order generated route files come out in - sends
            // GET /users/create to show(int $id) with the string "create", which
            // is a TypeError under strict types. The route was never wrong; the
            // precedence was.
            if ($best !== null && ! self::moreSpecificThan($route->uri, $best->uri)) {
                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            $best = $route;
            $bestParams = $params;
        }

        return $best === null ? null : [$best, $bestParams];
    }

    /**
     * Whether one route URI is strictly more specific than another that also
     * matched the same path.
     *
     * Two routes can only both match when they differ by a literal against a
     * placeholder, or by a trailing optional segment - a differing pair of
     * literals cannot both equal the same path segment. So the first position
     * where they disagree decides, and a literal wins there.
     */
    private static function moreSpecificThan(string $candidate, string $incumbent): bool
    {
        $candidateSegments = explode('/', trim($candidate, '/'));
        $incumbentSegments = explode('/', trim($incumbent, '/'));

        foreach ($candidateSegments as $index => $segment) {
            $other = $incumbentSegments[$index] ?? null;

            // The candidate has an extra (necessarily optional) segment, which
            // makes it the less specific of the two.
            if ($other === null) {
                return false;
            }

            if ($other === $segment) {
                continue;
            }

            $candidateIsLiteral = ! str_contains($segment, '{');
            $incumbentIsLiteral = ! str_contains($other, '{');

            if ($candidateIsLiteral !== $incumbentIsLiteral) {
                return $candidateIsLiteral;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $params
     */
    private function run(Route $route, array $params, Request $request): Response
    {
        $core = fn (Request $current): Response => $this->invoke($route, $params, $current);

        $pipeline = array_reduce(
            array_reverse($this->middlewareFor($route)),
            static fn (callable $next, Middleware $middleware): callable
                => static fn (Request $current): Response => $middleware->handle($current, $next),
            $core
        );

        return $pipeline($request);
    }

    /**
     * @param array<string, string> $params
     */
    private function invoke(Route $route, array $params, Request $request): Response
    {
        $request->setRouteParams($params);

        if ($route->action instanceof Closure) {
            // The closure is copied to a local before it is called. PHP reads
            // "$route->action(...)" as a *method* call named action(), never as
            // an invocation of the closure held in the property - properties
            // are not consulted for that form, and Route has no __call - so the
            // direct form is a fatal "Call to undefined method
            // Vigen\Http\Route::action()" on every closure route.
            $action = $route->action;
            $arguments = $this->argumentsFor(new ReflectionFunction($action), $params, $request);
            $result = $action(...$arguments);
        } else {
            $result = $this->invokeController($route->action, $params, $request);
        }

        return $this->toResponse($result);
    }

    /**
     * @param array{0: class-string, 1: string} $action
     * @param array<string, string>             $params
     */
    private function invokeController(array $action, array $params, Request $request): mixed
    {
        [$class, $method] = $action;

        if (! class_exists($class)) {
            throw new RuntimeException(sprintf(
                'A route points at controller [%s], which does not exist. Check the "use" statement in your route file.',
                $class
            ));
        }

        $controller = $this->container->make($class);

        if (! method_exists($controller, $method)) {
            throw new RuntimeException(sprintf(
                'Controller [%s] has no method [%s].',
                $class,
                $method
            ));
        }

        return $controller->{$method}(
            ...$this->argumentsFor(new ReflectionMethod($controller, $method), $params, $request)
        );
    }

    /**
     * @param array<string, string> $params
     * @return list<mixed>
     */
    private function argumentsFor(
        ReflectionFunctionAbstract $reflection,
        array $params,
        Request $request,
    ): array {
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if (
                $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_a($request, $type->getName())
            ) {
                $arguments[] = $request;

                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $params[$name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new RuntimeException(sprintf(
                '%s() expects $%s, but the route captured no such parameter and there is no default.',
                $reflection->getName(),
                $name
            ));
        }

        return $arguments;
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        throw new RuntimeException(sprintf(
            'A route action must return a %s, a string, an array or null; it returned %s.',
            Response::class,
            get_debug_type($result)
        ));
    }

    /**
     * @return list<Middleware>
     */
    private function middlewareFor(Route $route): array
    {
        $instances = [];

        foreach ($route->middleware as $alias) {
            $instances[] = $this->resolveMiddleware($alias);
        }

        return $instances;
    }

    private function resolveMiddleware(string $alias): Middleware
    {
        $class = $this->middlewareAliases[$alias] ?? $alias;

        if (! class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Middleware [%s] could not be resolved: class [%s] does not exist. '
                . 'Register an alias under "middleware" in config/app.php.',
                $alias,
                $class
            ));
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof Middleware) {
            throw new RuntimeException(sprintf(
                'Middleware [%s] must implement %s.',
                $class,
                Middleware::class
            ));
        }

        return $instance;
    }

    /**
     * @return list<string>
     */
    private function allowedMethodsFor(string $path): array
    {
        $path = self::normalise($path);
        $methods = [];

        foreach ($this->routes as $route) {
            if (preg_match($this->compile($route->uri), $path) === 1) {
                $methods[] = $route->method;
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * Turn '/posts/{id}' into a matching regex. Values are read from the URL,
     * so the literal segments are quoted and the placeholders become named
     * capture groups; a trailing '?' makes a placeholder optional.
     */
    private function compile(string $uri): string
    {
        $trimmed = trim($uri, '/');

        if ($trimmed === '') {
            return '#^/$#';
        }

        $pattern = '';

        foreach (explode('/', $trimmed) as $segment) {
            if (preg_match('#^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}$#', $segment, $matches) === 1) {
                $name = $matches[1];

                // The separator goes *inside* the optional group. Appending an
                // optional capture to a '/blog' prefix leaves the slash itself
                // mandatory, so '/blog/{page?}' would never match '/blog' - the
                // one path an optional segment exists to allow.
                $pattern .= isset($matches[2]) && $matches[2] === '?'
                    ? '(?:/(?P<' . $name . '>[^/]*))?'
                    : '/(?P<' . $name . '>[^/]+)';

                continue;
            }

            $pattern .= '/' . preg_quote($segment, '#');
        }

        return '#^' . $pattern . '$#';
    }

    /**
     * A leading slash and no trailing slash, so '/posts/' and 'posts' both
     * become '/posts' and match the same route.
     */
    private static function normalise(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
