<?php

declare(strict_types=1);

namespace Vigen\Http;

use Vigen\Auth\Auth;

/**
 * An incoming HTTP request.
 *
 * Immutable apart from the route parameters, which the Router fills in once
 * it has matched a route - the request has to exist before matching, but the
 * captured {params} only become known after it.
 */
class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $routeParams
     * @param array<string, mixed>  $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private array $routeParams = [],
        private readonly array $files = [],
    ) {
    }

    /**
     * Build a request from PHP's superglobals. JSON bodies are decoded so a
     * fetch()/axios client can post JSON and read it with input() exactly as
     * a form post would.
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $headers = self::headersFromServer($_SERVER);

        $body = $_POST;

        if (stripos($headers['Content-Type'] ?? '', 'application/json') !== false) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self(
            self::spoofedMethod($method, $body),
            $path,
            $_GET,
            $body,
            $headers,
            [],
            $_FILES
        );
    }

    /**
     * Honour a _method field in a POST body.
     *
     * An HTML form can only send GET or POST, so the only way a generated edit
     * or delete form can reach a put()/delete() route is by posting a hidden
     * _method field - which is exactly what generated views write. Without
     * this, every one of those forms 405s.
     *
     * Only POST is rewritten, so a link or an <img> carrying ?_method=DELETE
     * cannot turn a safe request into a destructive one. The Kernel still
     * verifies the CSRF token afterwards, because the rewritten method is what
     * it sees.
     *
     * @param array<string, mixed> $body
     */
    private static function spoofedMethod(string $method, array $body): string
    {
        if ($method !== 'POST') {
            return $method;
        }

        $spoofed = strtoupper((string) ($body['_method'] ?? ''));

        return in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true) ? $spoofed : $method;
    }

    /**
     * Look a value up in the body first, then the query string. Supports
     * dot notation for nested arrays: input('user.email').
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->dig($this->body, $key);

        if ($value === null) {
            $value = $this->dig($this->query, $key);
        }

        return $value ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->dig($this->query, $key) ?? $default;
    }

    /**
     * A value from the request body only, ignoring the query string.
     *
     * input() searches both, which is right for reading a submitted form but
     * wrong for telling a posted email apart from ?email=. Models reach for
     * post() by reflex, so it exists rather than being a fatal "Call to
     * undefined method".
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->dig($this->body, $key) ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @param  list<string>        $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        $subset = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $subset[$key] = $all[$key];
            }
        }

        return $subset;
    }

    public function has(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * True when the client would rather have JSON than HTML - either because
     * it asked for it, or because it sent JSON.
     */
    public function expectsJson(): bool
    {
        if (stripos((string) $this->header('Accept'), 'application/json') !== false) {
            return true;
        }

        if (stripos((string) $this->header('Content-Type'), 'application/json') !== false) {
            return true;
        }

        return strcasecmp((string) $this->header('X-Requested-With'), 'XMLHttpRequest') === 0;
    }

    /**
     * @param array<string, string|list<string>> $rules
     * @return array<string, mixed> the validated subset of the input
     *
     * @throws ValidationException
     */
    public function validate(array $rules): array
    {
        return Validator::validate($this->all(), $rules);
    }

    /**
     * The authenticated user, or null.
     */
    public function user(): mixed
    {
        return Auth::user();
    }

    public function isSecure(): bool
    {
        return strcasecmp((string) ($_SERVER['HTTPS'] ?? ''), 'on') === 0;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * A route parameter captured from the URL, e.g. {id}.
     */
    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function dig(array $source, string $key): mixed
    {
        if (array_key_exists($key, $source)) {
            return $source[$key];
        }

        if (! str_contains($key, '.')) {
            return null;
        }

        $value = $source;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }

        // These two are not exposed under HTTP_ by every SAPI.
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                $headers[$name] = (string) $server[$key];
            }
        }

        return $headers;
    }
}
