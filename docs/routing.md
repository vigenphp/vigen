# Routing

Every request enters through `public/index.php`, is handed to `Vigen\Http\Kernel`, and is matched against the routes in `routes/web.php`.

```
your-project/
├── public/index.php     <- the front controller; point your web server here
└── routes/web.php       <- config('app.paths.routes')
```

## Defining routes

`$router` is already in scope inside the route file - Vigen puts it there. Do not create it yourself.

```php
<?php

use App\Http\Controllers\PostController;
use Vigen\View\View;

$router->get('/', fn () => View::render('welcome'));

$router->get('/posts', [PostController::class, 'index']);
$router->get('/posts/{id}', [PostController::class, 'show']);
$router->post('/posts', [PostController::class, 'store']);
$router->put('/posts/{id}', [PostController::class, 'update']);
$router->patch('/posts/{id}', [PostController::class, 'update']);
$router->delete('/posts/{id}', [PostController::class, 'destroy']);
```

The available methods are `get`, `post`, `put`, `patch` and `delete`. There is no `Route::` facade - if you have seen that in Laravel, it does not exist here.

An action is either a `[Controller::class, 'method']` pair or a closure.

## Route parameters

`{name}` captures one URL segment:

```php
$router->get('/posts/{id}', [PostController::class, 'show']);   // /posts/42
$router->get('/users/{userId}/posts/{postId}', [PostController::class, 'byUser']);
```

Arguments are matched to action parameters **by name**, not by position, so you can order them however reads best:

```php
public function byUser(string $postId, string $userId): Response
```

A trailing `?` makes a parameter optional, and it will be `null` when absent:

```php
$router->get('/blog/{page?}', [BlogController::class, 'index']);
```

Any parameter typed as `Vigen\Http\Request` receives the request object instead:

```php
$router->get('/search', function (Request $request): Response {
    return Response::json(['q' => $request->input('q')]);
});
```

## What an action returns

| You return | The client gets |
|---|---|
| `Vigen\Http\Response` | That response, unchanged |
| a `string` | A 200 HTML response with that body |
| an `array` | A 200 JSON response |
| `null` | A 204 No Content response |

## Middleware

The optional third argument is a list of middleware aliases:

```php
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
```

Two aliases are built in:

| Alias | Effect |
|---|---|
| `auth` | Redirects a guest to `config('auth.login_path')` (default `/login`), remembering where they were headed. Returns 401 JSON if the request expects JSON. |
| `guest` | Redirects an authenticated user to `config('auth.home_path')`. |

Register your own in `config/app.php`:

```php
'middleware' => [
    'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
],
```

A middleware is any class implementing `Vigen\Http\Middleware`:

```php
<?php

namespace App\Http\Middleware;

use Vigen\Http\Middleware;
use Vigen\Http\Request;
use Vigen\Http\Response;

class EnsureUserIsAdmin implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (! $request->user()?->is_admin) {
            return Response::redirect('/');
        }

        return $next($request);
    }
}
```

Returning a response without calling `$next()` short-circuits the request - the action never runs. You can also name a middleware class directly instead of an alias.

## When a route does not match

- **A path with no route at all** - a 404. Vigen's default 404 page **lists every registered route**, which is usually enough to see that the route you expected was never registered. Override it by creating `resources/views/errors/404.php`; it receives `$method`, `$path` and `$routes`.
- **A path that exists under a different verb** - a 405 naming the verbs that do work, with an `Allow` header.

Both are far more useful than a bare "Not Found", so read the page rather than assuming the server is misconfigured.

## A note on trailing slashes

`/posts` and `/posts/` are the same route. A leading slash is optional when registering: `'posts'` and `'/posts'` behave identically.
