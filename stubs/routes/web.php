<?php

/**
 * Application routes.
 *
 * Vigen reads this file on every request. The $router variable is already in
 * scope - do not create it yourself.
 *
 *   $router->get('/posts/{id}', [PostController::class, 'show']);
 *   $router->post('/posts', [PostController::class, 'store'], ['auth']);
 *
 * {id} is passed to the action as an argument. The optional third argument is
 * a list of middleware aliases ("auth", "guest", or one you register in
 * config/app.php).
 */

use Vigen\View\View;

$router->get('/', static function () use ($router) {
    return View::render('welcome', [
        'title' => (string) config('app.name', 'Vigen'),
        'routes' => array_map(
            static fn ($route): string => $route->method . ' ' . $route->uri,
            $router->routes()
        ),
    ]);
});
