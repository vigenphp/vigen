<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Vigen\Core\Application as CoreApplication;
use Vigen\GUI\Server;

$basePath = $_SERVER['VIGEN_BASE_PATH'] ?? getcwd();
$core = new CoreApplication($basePath);
$server = new Server($core);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api/chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $prompt = (string) ($body['prompt'] ?? '');

    header('Content-Type: application/json');
    echo json_encode($server->handleChatRequest($prompt));

    return true;
}

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html');
    readfile($server->entryPointPath());

    return true;
}

// Let the built-in server handle any other static asset requests normally.
return false;
