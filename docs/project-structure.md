# Project Structure

```
your-project/
├── app/
│   ├── Models/               <- config('app.paths.models')
│   └── Http/
│       ├── Controllers/      <- config('app.paths.controllers')
│       └── Middleware/       <- config('app.paths.middleware')
├── database/
│   └── migrations/           <- config('app.paths.migrations')
├── routes/
│   └── web.php                <- config('app.paths.routes')
├── config/
│   └── app.php                <- config('app.paths.config')
├── resources/views/          <- config('app.paths.views')
├── tests/                     <- config('app.paths.tests')
├── .vigen/
├── .env
└── vigen                      <- your entry point, like `artisan`
```

These are created by `php vigen init` (via `Scaffolder`). Vigen's package source itself is laid out differently:

```
vendor/vigenphp/vigen/
├── src/
│   ├── Core/       Application bootstrap, Config, shared container
│   ├── AI/          AIEngine and workflow value objects
│   ├── CLI/         Console application and commands
│   ├── GUI/         Minimal GUI server + router
│   ├── Providers/   AIProviderInterface + Ollama/OpenAI/Claude/Gemini
│   ├── Project/     ProjectContext, Scaffolder, Conventions, EnvWriter
│   └── Composer/    Plugin that publishes the root `vigen` script
├── stubs/vigen      Template for the published root script
└── docs/
```
