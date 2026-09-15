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
