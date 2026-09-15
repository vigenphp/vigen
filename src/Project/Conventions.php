<?php

declare(strict_types=1);

namespace Vigen\Project;

/**
 * The conventional locations Vigen's AI engine places generated files in -
 * models, controllers, routes, migrations, etc. These are the defaults;
 * a project can override any of them via the `paths` key in
 * config/app.php, and AIEngine's planner is told about the resolved set
 * so generated code lands where the project actually expects it.
 */
final class Conventions
{
    public const DEFAULTS = [
        'models' => 'app/Models',
        'controllers' => 'app/Http/Controllers',
        'middleware' => 'app/Http/Middleware',
        'routes' => 'routes/web.php',
        'migrations' => 'database/migrations',
        'config' => 'config',
        'views' => 'resources/views',
        'tests' => 'tests',
    ];

    /**
     * @param array<string, string>|null $configured
     * @return array<string, string>
     */
    public static function resolve(?array $configured): array
    {
        return array_merge(self::DEFAULTS, $configured ?? []);
    }
}
