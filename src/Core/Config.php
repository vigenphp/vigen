<?php

declare(strict_types=1);

namespace Vigen\Core;

/**
 * Loads every config/*.php file in the project (each expected to `return`
 * an array, Laravel-style) into a single repository accessed by dot
 * notation, e.g. config('app.paths.models').
 */
class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(private readonly string $basePath)
    {
        $this->load();
    }

    /**
     * Re-read config/*.php from disk. Needed after a config file is published
     * mid-process - a CLI command that scaffolds config/database.php would
     * otherwise read an empty repository for the rest of the run.
     */
    public function reload(): void
    {
        $this->items = [];
        $this->load();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function load(): void
    {
        $dir = $this->basePath . '/config';
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;
            $this->items[$key] = is_array($value) ? $value : [];
        }
    }
}
