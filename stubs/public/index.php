<?php

declare(strict_types=1);

/**
 * Vigen's front controller - the single entry point for your application,
 * the equivalent of Laravel's public/index.php.
 *
 * `php vigen serve` starts PHP's built-in server with this file as its router
 * script. In production, point your web server's document root at this
 * directory and route everything that is not a real file to this file.
 */

use Vigen\Core\Application;

// Let the built-in server serve a real file (CSS, JS, an image) untouched
// instead of routing it through the application.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $path);
    $root = realpath(__DIR__);

    if ($file !== false && is_file($file) && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$autoload = __DIR__ . '/../vendor/autoload.php';

if (! is_file($autoload)) {
    http_response_code(500);
    exit('Vigen: vendor/autoload.php is missing. Run `composer install` in your project root.');
}

require $autoload;

$app = new Application(dirname(__DIR__));

$app->http()->handleFromGlobals()->send();
