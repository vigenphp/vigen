<?php

declare(strict_types=1);

namespace Vigen\Project;

/**
 * Collects a snapshot of the host PHP project so the AI Engine can reason
 * about it before generating or modifying anything. This class only
 * *gathers* facts - it never edits files.
 */
class ProjectContext
{
    public function __construct(
        private readonly string $basePath
    ) {
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * @return array<string, string> package name => version
     */
    public function dependencies(): array
    {
        $lockPath = $this->basePath . '/composer.lock';
        if (! is_file($lockPath)) {
            return [];
        }

        $lock = json_decode((string) file_get_contents($lockPath), true) ?? [];
        $deps = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $deps[$package['name']] = $package['version'];
        }

        return $deps;
    }

    /**
     * Return relevant file paths for a task, filtered by keyword so the AI
     * only inspects what the task actually touches (per the "only inspect
     * files relevant to the requested task" rule).
     *
     * @return list<string>
     */
    public function relevantFiles(string $taskDescription, array $searchPaths = ['app', 'src', 'routes', 'database', 'config']): array
    {
        $keywords = $this->extractKeywords($taskDescription);
        $matches = [];

        foreach ($searchPaths as $relativePath) {
            $fullPath = $this->basePath . '/' . $relativePath;
            if (! is_dir($fullPath)) {
                continue;
            }

            foreach ($this->walk($fullPath) as $file) {
                $haystack = strtolower($file);
                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($haystack, $keyword)) {
                        $matches[] = $file;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($matches));
    }

    public function summary(): array
    {
        return [
            'base_path' => $this->basePath,
            'php_version' => $this->phpVersion(),
            'dependencies' => $this->dependencies(),
            'has_composer_json' => is_file($this->basePath . '/composer.json'),
            'has_env' => is_file($this->basePath . '/.env'),
        ];
    }

    /**
     * @return list<string>
     */
    private function walk(string $dir): array
    {
        $results = [];
        $items = @scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $results = array_merge($results, $this->walk($path));
                continue;
            }

            if (str_ends_with($item, '.php')) {
                $results[] = $path;
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $taskDescription): array
    {
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($taskDescription)) ?: [];
        $stopWords = ['a', 'an', 'the', 'to', 'for', 'with', 'and', 'add', 'create', 'make', 'of', 'in', 'on'];

        return array_values(array_filter(
            $words,
            static fn (string $w) => strlen($w) > 2 && ! in_array($w, $stopWords, true)
        ));
    }
}
