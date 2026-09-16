<?php

declare(strict_types=1);

namespace Vigen\Auth;

use RuntimeException;
use Vigen\Database\Model;
use Vigen\Http\Session;

/**
 * A session-backed authentication guard.
 *
 * It stores the user's primary key in the session and re-reads the row on each
 * request, so a deleted or changed user is reflected immediately rather than
 * living on inside a stale session.
 *
 * The user class and the "username" column come from config/auth.php, so a
 * generated app can use `username` instead of `email` without touching this
 * class.
 */
final class Auth
{
    private const SESSION_KEY = 'vigen_user_id';

    /** @var array<int|string, Model> request-scoped cache, keyed by id */
    private static array $resolved = [];

    private static ?Model $user = null;

    /**
     * Verify credentials and log the user in on success.
     */
    public static function attempt(string $username, string $password): bool
    {
        $model = self::modelClass();
        $user = $model::where(self::usernameColumn(), $username)->first();

        if ($user === null) {
            // Hash anyway so a missing account and a wrong password take
            // comparable time - otherwise response timing reveals which
            // addresses are registered.
            Hash::check($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

            return false;
        }

        if (! Hash::check($password, self::passwordOf($user))) {
            return false;
        }

        self::login($user);

        return true;
    }

    /**
     * Log a model in directly - what a registration handler calls once it has
     * created the row.
     */
    public static function login(Model $user): void
    {
        Session::regenerate();

        $id = $user->getKey();

        if ($id === null) {
            throw new RuntimeException('Cannot log in a user that has not been saved.');
        }

        Session::put(self::SESSION_KEY, $id);

        self::$user = $user;
    }

    /**
     * The authenticated user for this request, or null.
     */
    public static function user(): ?Model
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $key = is_int($id) || is_string($id) ? $id : (string) $id;

        if (array_key_exists($key, self::$resolved)) {
            return self::$user = self::$resolved[$key];
        }

        $model = self::modelClass();

        $user = $model::find($key);

        if ($user === null) {
            // The account is gone; drop the dangling session rather than
            // leaving every request to re-query for it.
            Session::forget(self::SESSION_KEY);

            return null;
        }

        self::$resolved[$key] = $user;

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return ! self::check();
    }

    public static function id(): int|string|null
    {
        return self::user()?->getKey();
    }

    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::regenerate();

        self::$user = null;
        self::$resolved = [];
    }

    /**
     * Forget cached state. Called between requests in tests, where the process
     * outlives a single request.
     */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = [];
    }

    /**
     * @return class-string<Model>
     */
    private static function modelClass(): string
    {
        $class = (string) config('auth.model', 'App\\Models\\User');

        if (! class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Auth model [%s] not found. Set it in config/auth.php.',
                $class
            ));
        }

        if (! is_subclass_of($class, Model::class)) {
            throw new RuntimeException(sprintf(
                'Auth model [%s] must extend %s.',
                $class,
                Model::class
            ));
        }

        /** @var class-string<Model> $class */
        return $class;
    }

    private static function usernameColumn(): string
    {
        return (string) config('auth.username', 'email');
    }

    /**
     * The stored hash, read straight from the attributes so it works whether or
     * not the model hides the column.
     */
    private static function passwordOf(Model $user): string
    {
        $password = $user->getAttributes()['password'] ?? null;

        return is_string($password) ? $password : '';
    }
}
