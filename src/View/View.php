<?php

declare(strict_types=1);

namespace Vigen\View;

use RuntimeException;
use Throwable;
use Vigen\Http\Response;
use Vigen\Http\Session;

/**
 * Renders plain-PHP templates from the project's views directory.
 *
 * There is no template language to learn or to mis-generate: a view is a PHP
 * file, the keys of $data become local variables, and e() escapes output.
 *
 *   View::render('auth.login', ['error' => 'Invalid credentials']);
 *
 * Names use dots for directories: 'auth.login' resolves to
 * resources/views/auth/login.php.
 */
final class View
{
    /**
     * An explicitly configured views directory, overriding config/app.php.
     */
    private static ?string $path = null;

    public static function path(?string $path = null): string
    {
        if ($path !== null) {
            self::$path = rtrim($path, '/\\');
        }

        return self::resolvePath();
    }

    public static function reset(): void
    {
        self::$path = null;
    }

    public static function exists(string $template): bool
    {
        return is_file(self::file($template));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): Response
    {
        return Response::html(self::partial($template, $data));
    }

    /**
     * Render to a string, for use inside another template.
     *
     * The class name must be fully qualified, or the template must import it.
     * A template is *included*, and an included file is compiled in whatever
     * namespace that file itself declares - templates declare none, so a bare
     * `View::` inside one resolves to the global \View and dies with
     * "Class View not found":
     *
     *   <?= \Vigen\View\View::partial('partials.header') ?>
     *
     * or, with an import at the top of the template:
     *
     *   <?php use Vigen\View\View; ?><?= View::partial('partials.header') ?>
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::file($template);

        if (! is_file($file)) {
            throw new RuntimeException(sprintf(
                'View [%s] not found. Looked for %s (views directory: %s).',
                $template,
                $file,
                self::resolvePath()
            ));
        }

        // A static closure, so a template cannot reach $this, and EXTR_SKIP so
        // a $data key named __vigenView cannot clobber the include target.
        return (static function (string $__vigenView, array $__vigenData): string {
            extract($__vigenData, EXTR_SKIP);

            ob_start();

            try {
                include $__vigenView;
            } catch (Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            return (string) ob_get_clean();
        })($file, $data);
    }

    public static function escape(mixed $value): string
    {
        return e($value);
    }

    /**
     * The first validation message for a field, from the last request's
     * flashed errors - what a form renders next to an input.
     */
    public static function error(string $field): ?string
    {
        $message = self::errors()[$field] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * @return array<string, string>
     */
    public static function errors(): array
    {
        $errors = Session::getFlash('errors');

        if (! is_array($errors)) {
            return [];
        }

        return array_filter($errors, 'is_string');
    }

    /**
     * A submitted value from the previous request, so a form can be
     * re-rendered with what the user typed rather than empty.
     */
    public static function old(string $field, mixed $default = ''): mixed
    {
        $old = Session::getFlash('old');

        return is_array($old) && array_key_exists($field, $old) ? $old[$field] : $default;
    }

    private static function file(string $template): string
    {
        $relative = str_replace('.', '/', trim($template, '.'));

        return self::resolvePath() . '/' . $relative . '.php';
    }

    private static function resolvePath(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $configured = (string) config('app.paths.views', 'resources/views');

        if (str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:#', $configured) === 1) {
            return rtrim($configured, '/\\');
        }

        return base_path($configured);
    }
}
