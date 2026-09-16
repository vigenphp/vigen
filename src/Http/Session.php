<?php

declare(strict_types=1);

namespace Vigen\Http;

/**
 * The session, as a small static API over a swappable backing array.
 *
 * Statics (rather than an injected instance) keep generated controllers and
 * templates short: `Session::token()` in a form, `Session::flash(...)` after
 * a failed login. Tests and CLI commands swap the backing store with use()
 * instead of touching PHP's session machinery.
 */
final class Session
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $store = null;

    /**
     * Bind the real PHP session. Called once by the Kernel before a request
     * is dispatched.
     */
    public static function boot(): void
    {
        if (self::$store !== null) {
            return;
        }

        // The built-in server runs as `cli-server`, where sessions work
        // normally; only the plain `cli` SAPI (console commands, tests) has
        // nowhere to put one.
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (! isset($_SESSION) || ! is_array($_SESSION)) {
            $_SESSION = [];
        }

        self::$store = &$_SESSION;
    }

    /**
     * Begin a request: age the flash bucket so last request's values are
     * readable and this request's start fresh.
     *
     * Called by the kernel for every request rather than once per process -
     * a long-running worker (or a test) handles many requests in one process,
     * and flash that never ages would show a stale error forever.
     */
    public static function startRequest(): void
    {
        self::ageFlash();
    }

    /**
     * Point the session at an arbitrary array. Used by tests, and by any
     * caller that wants a session without PHP's session handling.
     *
     * @param array<string, mixed> $store
     */
    public static function use(array &$store): void
    {
        self::$store = &$store;
    }

    /**
     * Forget the bound store, so the next access boots a fresh one.
     */
    public static function reset(): void
    {
        // Rebinding, not assigning. After use(), self::$store is a *reference*
        // to the caller's array, so `self::$store = null` writes null straight
        // through that reference - a fatal TypeError when the caller's array is
        // held in a property typed `array`, which is how every test binds one.
        // Pointing the static at a fresh variable drops the old link instead of
        // following it, leaving the caller's array untouched.
        $empty = null;
        self::$store = &$empty;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $store = &self::store();

        return $store[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $store = &self::store();
        $store[$key] = $value;
    }

    public static function has(string $key): bool
    {
        $store = &self::store();

        return array_key_exists($key, $store);
    }

    public static function forget(string $key): void
    {
        $store = &self::store();
        unset($store[$key]);
    }

    /**
     * Clear the session's own data, keeping the CSRF token and any flash
     * values still waiting to be read.
     */
    public static function flush(): void
    {
        $store = &self::store();
        $keep = array_intersect_key($store, ['_token' => true, '_flash' => true]);

        $store = $keep;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $store = &self::store();

        return array_diff_key($store, ['_flash' => true]);
    }

    /**
     * Store a value that survives exactly one further request - which is how
     * errors and old input reach the page a failed login redirects back to.
     */
    public static function flash(string $key, mixed $value): void
    {
        $store = &self::store();
        $store['_flash']['new'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $store = &self::store();

        return $store['_flash']['old'][$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function allFlash(): array
    {
        $store = &self::store();
        $old = $store['_flash']['old'] ?? [];

        return is_array($old) ? $old : [];
    }

    /**
     * The CSRF token for this session, generated on first use.
     */
    public static function token(): string
    {
        $store = &self::store();

        if (! isset($store['_token']) || ! is_string($store['_token']) || $store['_token'] === '') {
            $store['_token'] = bin2hex(random_bytes(32));
        }

        return $store['_token'];
    }

    public static function regenerateToken(): string
    {
        $store = &self::store();
        $store['_token'] = bin2hex(random_bytes(32));

        return $store['_token'];
    }

    /**
     * Rotate the session id, keeping the data. Called on login and logout so a
     * session id captured before authentication cannot be reused afterwards
     * (session fixation).
     */
    public static function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        self::regenerateToken();
    }

    /**
     * Constant-time comparison, so a wrong token cannot be discovered by
     * timing how long the check takes.
     */
    public static function verifyToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::token(), $token);
    }

    /**
     * Move the last request's flash values into place, and start a fresh
     * bucket for this one.
     */
    private static function ageFlash(): void
    {
        $store = &self::store();
        $new = $store['_flash']['new'] ?? [];

        $store['_flash']['old'] = is_array($new) ? $new : [];
        $store['_flash']['new'] = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function &store(): array
    {
        if (self::$store === null) {
            self::boot();
        }

        return self::$store;
    }
}
