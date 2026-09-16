# Project Structure

```
your-project/
├── public/
│   └── index.php              <- front controller; point your web server here
├── app/
│   ├── Models/                <- config('app.paths.models')
│   └── Http/
│       ├── Controllers/       <- config('app.paths.controllers')
│       └── Middleware/        <- config('app.paths.middleware')
├── database/
│   ├── migrations/            <- config('app.paths.migrations')
│   └── database.sqlite        <- created on first use (SQLite default)
├── routes/
│   └── web.php                <- config('app.paths.routes')
├── resources/
│   └── views/                 <- config('app.paths.views')
├── storage/
│   └── logs/vigen.log         <- unhandled exceptions
├── config/
│   ├── app.php                <- config('app.paths.config')
│   ├── auth.php
│   └── database.php
├── tests/                     <- config('app.paths.tests')
├── .vigen/
├── .env
└── vigen                      <- your entry point, like `artisan`
```

These are created by `php vigen init` (via `Scaffolder`), and every file it
writes is written only if missing - re-running `init` on an existing project
never clobbers your work.

Two directories are worth calling out:

- **`public/`** is the only directory a web server should expose. Every request
  enters through `public/index.php`, which boots the application and hands the
  request to `Vigen\Http\Kernel`. Nothing outside `public/` is reachable from a
  browser, so `config/`, `.env` and the SQLite file stay private.
- **`resources/views/`** holds plain PHP templates. There is no compiled
  template cache, so there is no `storage/framework/views` to clear.

## How a request flows

```
browser -> public/index.php -> Kernel::handle()
                                 |-- Session::startRequest()
                                 |-- require routes/web.php  ($router in scope)
                                 |-- Router: match method + path
                                 |-- middleware pipeline (auth, guest, ...)
                                 |-- instantiate the controller (autowired)
                                 |-- call the action
                                 `-- Response::send()
```

An unhandled exception is logged to `storage/logs/vigen.log` and rendered as a
500 page.

## Vigen's own source

The package is laid out differently from the project it scaffolds:

```
vendor/vigenphp/vigen/
├── src/
│   ├── Core/        Application bootstrap, Config, Container
│   ├── AI/          AIEngine, ApiReference, workflow value objects
│   ├── Auth/        Hash, Auth guard, Authenticate / RedirectIfAuthenticated
│   ├── CLI/         Console application and commands
│   ├── Database/    Connection, QueryBuilder, Model, Migrator
│   ├── GUI/         Chatbox server + router
│   ├── Http/        Request, Response, Router, Kernel, Session, Validator
│   ├── Providers/   AIProviderInterface + Ollama/OpenAI/Claude/Gemini
│   ├── Project/     ProjectContext, Scaffolder, Conventions, FileWriter,
│   │                SyntaxValidator, CodeValidator, EnvWriter
│   ├── Support/     Global helpers: e(), env(), config(), base_path()
│   ├── View/        View
│   └── Composer/    Plugin that publishes the root `vigen` script
├── stubs/           Files `init` publishes: config, routes, views, public/index.php
└── docs/
```

`src/Support/helpers.php` is loaded through Composer's `autoload.files`, which is
why changing that array requires a `composer dump-autoload`.
