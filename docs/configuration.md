# Configuration

## AI provider & model

The easiest way is `php vigen init`, which writes `.env` for you interactively. To reconfigure later, re-run `php vigen init` (it updates existing keys in place) or edit `.env` directly:

```env
VIGEN_AI_PROVIDER=ollama
VIGEN_AI_MODEL=qwen2.5-coder:14b
VIGEN_CHAT_INTERFACE=cli
```

See `ai-providers.md` for provider-specific variables.

## File-location conventions

Where Vigen puts generated models, controllers, routes, and migrations is controlled by `config/app.php`, created for you on `php vigen init`:

```php
return [
    'name' => 'Vigen App',

    'paths' => [
        'models' => 'app/Models',
        'controllers' => 'app/Http/Controllers',
        'middleware' => 'app/Http/Middleware',
        'routes' => 'routes/web.php',
        'migrations' => 'database/migrations',
        'config' => 'config',
        'views' => 'resources/views',
        'tests' => 'tests',
    ],
];
```

Change any value to match your project's structure - e.g. a DDD-style project might set `'models' => 'src/Domain/Models'`. Vigen's AI engine reads these on every request and is instructed to follow them when planning file changes. Keys you don't override fall back to the defaults automatically.

## Application settings

The rest of `config/app.php` is read by the HTTP kernel:

```php
return [
    'name' => 'Vigen App',
    'debug' => env('APP_DEBUG', false),   // show exception traces in the browser
    'csrf' => true,                       // verify _token on POST/PUT/PATCH/DELETE

    'paths' => [ /* ... */ ],

    'middleware' => [                     // your own aliases, on top of auth/guest
        // 'admin' => App\Http\Middleware\EnsureUserIsAdmin::class,
    ],
];
```

`debug` should be `false` anywhere but your own machine: it puts file paths and
stack traces into the error page. Unhandled exceptions are logged to
`storage/logs/vigen.log` either way.

## Database

`config/database.php` sets the default connection and the drivers available. The
default is SQLite, which needs no server and writes to
`database/database.sqlite`:

```php
'default' => env('DB_CONNECTION', 'sqlite'),
```

Point `.env` at MySQL to switch - no code change:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vigen
DB_USERNAME=vigen
DB_PASSWORD=secret
```

A `.env` value always overrides the matching config key. See `database.md` for
the migration, model and query-builder APIs, and for the SQLite/MySQL syntax
differences that matter when writing a migration.

## Authentication

`config/auth.php` tells `Vigen\Auth\Auth` which model holds users, which column
is the login identifier, and where to send guests and logged-in users:

```php
return [
    'model' => App\Models\User::class,
    'username' => 'email',
    'login_path' => '/login',
    'home_path' => '/',
];
```

See `authentication.md`.

## Reloading

Configuration is read once per process. After editing anything in `config/`,
restart `php vigen serve` - the running server will not pick the change up.
