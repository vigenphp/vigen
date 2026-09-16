<?php

declare(strict_types=1);

namespace Vigen\Project;

use Vigen\AI\ValidationResult;

/**
 * Lints generated PHP so the AI Engine's self-correction loop has something
 * real to react to.
 *
 * Before this existed, validation always passed and the fix stage documented
 * in docs/troubleshooting.md could never run. Only files ending in .php are
 * checked; anything Vigen cannot lint (no PHP binary reachable) passes rather
 * than failing the run over a check that was never performed.
 */
final class SyntaxValidator
{
    private ?string $binary = null;
    private bool $probed = false;

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Lint files as they exist on disk, after they have been written.
     *
     * @param list<string> $paths Project-relative paths that were just written.
     */
    public function validateFiles(array $paths): ValidationResult
    {
        $binary = $this->phpBinary();

        if ($binary === null) {
            return ValidationResult::ok();
        }

        $errors = [];

        foreach ($paths as $path) {
            if (! $this->isPhp($path)) {
                continue;
            }

            $absolute = $this->basePath . '/' . ltrim(str_replace('\\', '/', $path), '/');

            if (! is_file($absolute)) {
                continue;
            }

            $error = $this->lint($binary, $absolute, $path);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors === [] ? ValidationResult::ok() : ValidationResult::failed($errors);
    }

    /**
     * Lint proposed contents without touching the project, so a --dry-run
     * preview can still report syntax problems before anything is written.
     *
     * @param array<string, string> $contentsByPath project-relative path => proposed body
     */
    public function validateContents(array $contentsByPath): ValidationResult
    {
        $binary = $this->phpBinary();

        if ($binary === null) {
            return ValidationResult::ok();
        }

        $tempDir = sys_get_temp_dir() . '/vigen-lint-' . bin2hex(random_bytes(6));

        if (! @mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            // Cannot create a scratch directory - do not fail the run over a
            // check we were unable to perform.
            return ValidationResult::ok();
        }

        $errors = [];
        $index = 0;

        try {
            foreach ($contentsByPath as $path => $contents) {
                if (! $this->isPhp($path)) {
                    continue;
                }

                $temp = $tempDir . '/' . (++$index) . '.php';

                if (@file_put_contents($temp, $contents) === false) {
                    continue;
                }

                $error = $this->lint($binary, $temp, $path);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        } finally {
            $this->removeDirectory($tempDir);
        }

        return $errors === [] ? ValidationResult::ok() : ValidationResult::failed($errors);
    }

    /**
     * @return string|null The error message, or null when the file is valid.
     */
    private function lint(string $binary, string $absolute, string $label): ?string
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg($binary), escapeshellarg($absolute)), $output, $exitCode);

        if ($exitCode === 0) {
            return null;
        }

        $detail = trim(implode(' ', $output));

        return $detail === '' ? "{$label}: syntax check failed." : "{$label}: {$detail}";
    }

    private function isPhp(string $path): bool
    {
        return str_ends_with(strtolower($path), '.php');
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /**
     * Locate a usable PHP binary, or null if none can be executed.
     *
     * Probed once and cached - `php -v` is cheap but not free, and every
     * generated file would otherwise re-run it.
     */
    private function phpBinary(): ?string
    {
        if ($this->probed) {
            return $this->binary;
        }

        $this->probed = true;

        // A host may disable exec() entirely; in that case we simply cannot
        // lint and pass, rather than fatalling.
        if (! function_exists('exec')) {
            return null;
        }

        foreach ([PHP_BINARY, 'php'] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            // PHP_BINARY can point at a non-existent path under some SAPIs;
            // "php" is resolved through PATH instead.
            if ($candidate !== 'php' && ! is_file($candidate)) {
                continue;
            }

            $output = [];
            $exitCode = 0;
            exec(sprintf('%s -v 2>&1', escapeshellarg($candidate)), $output, $exitCode);

            if ($exitCode === 0) {
                $this->binary = $candidate;

                return $this->binary;
            }
        }

        return null;
    }
}
