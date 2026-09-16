<?php

declare(strict_types=1);

namespace Vigen\Auth;

use RuntimeException;

/**
 * Password hashing, wrapping PHP's own password_* functions so application
 * code never has to remember the algorithm or the options array.
 */
final class Hash
{
    public static function make(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public static function check(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * True when a stored hash was made with an algorithm or cost that no longer
     * matches the current default - the signal to rehash on next login.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
