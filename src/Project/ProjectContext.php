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
    /**
     * Directories never worth showing the model, either because they are
     * third-party code or Vigen's own bookkeeping.
     */
    private const SKIP_DIRECTORIES = ['.git', '.vigen', 'node_modules', 'storage', 'vendor'];

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
                // Match against the project-relative path, never the absolute
                // one. The base directory's own name would otherwise match
                // every file: a project checked out under a path containing
                // "user" would return the whole tree for the keyword "user",
                // and on Windows the inevitable \Users\ segment means that
                // happens on essentially every machine.
                $haystack = strtolower($this->relativePath($file) ?? $file);

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
     * The contents of the files relevant to a task, keyed by project-relative
     * path.
     *
     * relevantFiles() alone only tells the model which paths exist, so it
     * would rewrite a file like routes/web.php from scratch and destroy
     * whatever was already in it. Sending the bodies lets it make a surgical
     * edit instead.
     *
     * Reading stops once $maxBytes is spent, so a large tree cannot blow the
     * model's context window; the model is told when it is seeing a truncated
     * file rather than being left to guess.
     *
     * @return array<string, string>
     */
    public function relevantFileContents(string $taskDescription, int $maxBytes = 60000): array
    {
        $contents = [];
        $budget = $maxBytes;

        foreach ($this->relevantFiles($taskDescription) as $file) {
            if ($budget <= 0) {
                break;
            }

            $relative = $this->relativePath($file);
            if ($relative === null) {
                continue;
            }

            $body = @file_get_contents($file, false, null, 0, $budget);
            if ($body === false) {
                continue;
            }

            if (strlen($body) >= $budget && (int) @filesize($file) > $budget) {
                $body .= "\n// ... [truncated by Vigen]";
            }

            $contents[$relative] = $body;
            $budget -= strlen($body);
        }

        return $contents;
    }

    /**
     * Convert an absolute path inside the project into a project-relative
     * one, or null if it falls outside.
     */
    private function relativePath(string $absolute): ?string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
        $normalised = str_replace('\\', '/', $absolute);

        if (! str_starts_with($normalised, $base . '/')) {
            return null;
        }

        return substr($normalised, strlen($base) + 1);
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
                if (in_array($item, self::SKIP_DIRECTORIES, true)) {
                    continue;
                }

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
