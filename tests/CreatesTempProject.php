<?php

declare(strict_types=1);

namespace Vigen\Tests;

/**
 * Gives a test a real scratch project directory, so the file-writing side of
 * the engine is exercised against an actual filesystem rather than a mock.
 */
trait CreatesTempProject
{
    protected string $tempProject = '';

    protected function createTempProject(): string
    {
        $this->tempProject = sys_get_temp_dir() . '/vigen-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempProject, 0755, true);

        return $this->tempProject;
    }

    protected function removeTempProject(): void
    {
        if ($this->tempProject !== '' && is_dir($this->tempProject)) {
            self::deleteDirectory($this->tempProject);
        }
    }

    protected function writeFixture(string $relativePath, string $contents): string
    {
        $path = $this->tempProject . '/' . $relativePath;
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private static function deleteDirectory(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
