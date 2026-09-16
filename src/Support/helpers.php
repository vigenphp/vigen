<?php

declare(strict_types=1);

use Vigen\Core\Application;

/*
 * Global helpers. Each is guarded by function_exists() so a Vigen package
 * installed alongside another framework (or another copy of itself) can
 * never cause a fatal "cannot redeclare" error.
 */

if (! function_exists('e')) {
    /**
     * Escape a value for safe output inside an HTML template. Every value
     * echoed in a view should pass through this - generated templates do,
     * and so should hand-written ones.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('env')) {
    /**
     * Read a value from the environment, with the usual string coercions:
     * "true"/"false"/"null" (and their parenthesised forms) become their
     * real types, and an empty value falls back to the default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (! function_exists('base_path')) {
    /**
     * An absolute path inside the project root. Falls back to the working
     * directory when no application has been booted, so config files that
     * call it can still be evaluated on their own.
     */
    function base_path(string $append = ''): string
    {
        $base = rtrim(Application::instance()?->basePath() ?? (getcwd() ?: '.'), '/\\');

        return $append === '' ? $base : $base . '/' . ltrim($append, '/\\');
    }
}

if (! function_exists('config')) {
    /**
     * Read a dot-notation configuration value, e.g. config('app.paths.views').
     *
     * Returns $default rather than null when no application is booted, so a
     * config file or a unit test can call this safely outside a request.
     */
    function config(string $key, mixed $default = null): mixed
    {
        $app = Application::instance();

        if ($app === null) {
            return $default;
        }

        return $app->config()->get($key, $default);
    }
}
