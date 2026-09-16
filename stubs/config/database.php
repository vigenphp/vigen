<?php

/**
 * Database configuration.
 *
 * Vigen uses SQLite out of the box, so a fresh project has a working database
 * with no setup at all. To switch to MySQL, set these in your .env file:
 *
 *     DB_CONNECTION=mysql
 *     DB_HOST=127.0.0.1
 *     DB_PORT=3306
 *     DB_DATABASE=vigen
 *     DB_USERNAME=root
 *     DB_PASSWORD=
 *
 * Values in .env override anything written here.
 */

return [

    // Which entry in "connections" below to use.
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            // Relative paths are resolved from the project root. The file is
            // created automatically the first time it is used.
            'database' => env('DB_DATABASE', 'database/database.sqlite'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'vigen'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'vigen'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
        ],

    ],

];
