<?php

declare(strict_types=1);

use Vigen\Core\Application as CoreApplication;
use Vigen\GUI\Server;

/*
 * Locate the Composer autoloader. When Vigen is installed as a project
 * dependency the autoloader lives at the project root (which `vigen gui`
 * passes in as VIGEN_BASE_PATH and also sets as this process's cwd). When
 * Vigen is a standalone package checkout it lives inside the package itself.
 */
$basePath = getenv('VIGEN_BASE_PATH') ?: getcwd();

$autoload = null;
foreach ([
    $basePath . '/vendor/autoload.php',      // Vigen installed as a project dependency
    __DIR__ . '/../../vendor/autoload.php',  // Vigen checked out as a standalone package
] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Vigen could not locate vendor/autoload.php. Run `composer install` in your project.";

    return true;
}

require_once $autoload;

$core = new CoreApplication($basePath);
$server = new Server($core);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api/chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $prompt = (string) ($body['prompt'] ?? '');
    $dryRun = (bool) ($body['dry_run'] ?? false);

    header('Content-Type: application/json');
    echo json_encode($server->handleChatRequest($prompt, $dryRun));

    return true;
}

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html');
    readfile($server->entryPointPath());

    return true;
}

// Let the built-in server handle any other static asset requests normally.
return false;
