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
    public const DIRECTORIES = [
        'app/Models',
        'app/Http/Controllers',
        'app/Http/Middleware',
        'database/migrations',
        'public',
        'resources/views/errors',
        'routes',
        'config',
        'storage/logs',
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

        foreach ($this->stubFiles() as $relative => $stub) {
            $this->publish($relative, $stub);
        }

        $this->putIfMissing('.gitignore', $this->gitignoreStub());

        return $created;
    }

    /**
     * Re-publish config files only, and report what changed. Called by
     * `vigen migrate`, so a project scaffolded before a config file existed
     * picks it up without a full re-scaffold.
     *
     * @return list<string> files that were written
     */
    public function publishConfig(): array
    {
        $written = [];

        foreach (['config/database.php' => 'config/database.php', 'config/auth.php' => 'config/auth.php'] as $relative => $stub) {
            if ($this->publish($relative, $stub)) {
                $written[] = $relative;
            }
        }

        return $written;
    }

    /**
     * Map of project-relative path => path under stubs/.
     *
     * @return array<string, string>
     */
    private function stubFiles(): array
    {
        return [
            'public/index.php' => 'public/index.php',
            'routes/web.php' => 'routes/web.php',
            'config/app.php' => '',                 // generated, not a file on disk
            'config/database.php' => 'config/database.php',
            'config/auth.php' => 'config/auth.php',
            'resources/views/welcome.php' => 'views/welcome.php',
            'resources/views/errors/404.php' => 'views/errors/404.php',
        ];
    }

    /**
     * Copy a stub into the project if the destination is absent.
     *
     * @return bool whether the file was written
     */
    private function publish(string $relative, string $stub): bool
    {
        $target = $this->basePath . '/' . $relative;

        if (is_file($target)) {
            return false;
        }

        $contents = $stub === ''
            ? $this->appConfigStub()
            : $this->readStub($stub);

        if ($contents === null) {
            return false;
        }

        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // .env defaults, written once, so a fresh clone has something to copy.
        file_put_contents($target, $contents);

        if ($relative === 'config/database.php') {
            $this->putIfMissing('.env.example', $this->envExampleStub());
        }

        return true;
    }

    private function readStub(string $stub): ?string
    {
        $path = dirname(__DIR__, 2) . '/stubs/' . $stub;

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
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
                'name' => env('APP_NAME', 'Vigen App'),

                // Show exception details in the browser. Always false in
                // production.
                'debug' => env('APP_DEBUG', false),

                // Verify the CSRF token on POST/PUT/PATCH/DELETE requests.
                'csrf' => true,

                // Middleware aliases usable as the third argument of a route.
                // "auth" and "guest" are built in.
                'middleware' => [
                    // 'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
                ],

                // File-location conventions Vigen's AI engine follows when
                // generating or modifying code. Override any of these if your
                // project uses a different structure - Vigen will follow it.
                'paths' => [
            {$paths}
                ],
            ];

            PHP;
    }

    private function envExampleStub(): string
    {
        return <<<ENV
            APP_NAME="Vigen App"
            APP_DEBUG=true

            # Which database to use: sqlite, mysql or pgsql.
            DB_CONNECTION=sqlite

            # For mysql / pgsql you must change DB_CONNECTION above as well as
            # uncommenting these. Setting the credentials alone leaves the
            # driver on sqlite, and they are then ignored silently.
            # DB_HOST=127.0.0.1
            # DB_PORT=3306
            # DB_DATABASE=vigen
            # DB_USERNAME=root
            # DB_PASSWORD=

            # AI provider used by `vigen chat` and `vigen gui`:
            # ollama, openai, claude or gemini
            VIGEN_AI_PROVIDER=ollama
            VIGEN_AI_MODEL=qwen2.5-coder:14b

            ENV;
    }

    private function gitignoreStub(): string
    {
        return "/vendor/\n"
            . "/.env\n"
            . "/storage/logs/\n"
            . "/database/*.sqlite\n"
            . "/database/*.sqlite-journal\n"
            . "/.vigen/backups/\n";
    }

    private function putIfMissing(string $relativePath, string $contents): void
    {
        $path = $this->basePath . '/' . $relativePath;
        if (! is_file($path)) {
            file_put_contents($path, $contents);
        }
    }
}
