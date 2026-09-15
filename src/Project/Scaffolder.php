<?php

declare(strict_types=1);

namespace Vigen\Project;

/**
 * Lays down the minimal directory structure a Vigen project needs before
 * the AI engine starts generating application code into it. Only creates
 * what's missing - safe to run against an existing project.
 */
class Scaffolder
{
    private const DIRECTORIES = [
        'app/Models',
        'app/Http/Controllers',
        'app/Http/Middleware',
        'database/migrations',
        'routes',
        'config',
        'storage',
        'tests',
        '.vigen',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return list<string> directories that were created
     */
    public function scaffold(): array
    {
        $created = [];

        foreach (self::DIRECTORIES as $dir) {
            $path = $this->basePath . '/' . $dir;
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
                $created[] = $dir;
            }
        }

        $this->putIfMissing('routes/web.php', "<?php\n\n// Routes generated and managed by Vigen live here.\n");
        $this->putIfMissing('config/app.php', $this->appConfigStub());
        $this->putIfMissing('.gitignore', "/vendor/\n/.env\n/storage/*.log\n");

        return $created;
    }

    private function appConfigStub(): string
    {
        $lines = [];
        foreach (Conventions::DEFAULTS as $type => $path) {
            $lines[] = "        '{$type}' => '{$path}',";
        }
        $paths = implode("\n", $lines);

        return <<<PHP
            <?php

            return [
                'name' => 'Vigen App',

                // File-location conventions Vigen's AI engine follows when
                // generating or modifying code. Override any of these if your
                // project uses a different structure - Vigen will follow it.
                'paths' => [
            {$paths}
                ],
            ];

            PHP;
    }

    private function putIfMissing(string $relativePath, string $contents): void
    {
        $path = $this->basePath . '/' . $relativePath;
        if (! is_file($path)) {
            file_put_contents($path, $contents);
        }
    }
}
