<?php

declare(strict_types=1);

namespace Vigen\Project;

class EnvWriter
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Merge the given key => value pairs into .env, preserving any existing
     * lines and values not being set here.
     *
     * @param array<string, string> $values
     */
    public function write(array $values): void
    {
        $path = $this->basePath . '/.env';
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $lines = $lines === false ? [] : $lines;

        $remaining = $values;

        foreach ($lines as $index => $line) {
            foreach ($remaining as $key => $value) {
                if (str_starts_with($line, $key . '=')) {
                    $lines[$index] = $key . '=' . $value;
                    unset($remaining[$key]);
                    break;
                }
            }
        }

        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}
