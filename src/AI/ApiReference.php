<?php

declare(strict_types=1);

namespace Vigen\AI;

/**
 * The Vigen application API, as text for the planner and fixer prompts.
 *
 * This exists because the runtime alone does not fix Laravel-flavoured output:
 * a model that is told only the directory layout writes Laravel, since that is
 * what its training data associates with those directory names. Naming the
 * actual classes is what makes generated code run.
 *
 * Kept out of AIEngine so it can be asserted on directly in tests, and so the
 * contract has exactly one home when the runtime changes.
 */
final class ApiReference
{
    /**
     * The rule that matters most. Stated once, used by both prompts.
     */
    public const NOT_LARAVEL = <<<'TEXT'
        Vigen is NOT Laravel. It shares some directory names, and nothing else.

        These do not exist in Vigen and must never appear in generated code:
        - Any "Illuminate\" or "Laravel\" or "Symfony\" namespace or import.
        - The facades: Route, Hash, Auth, DB, Schema, Cache, Blade, Validator.
        - Eloquent. Vigen's model base class is Vigen\Database\Model.
        - Blade templates. Vigen views are plain PHP files.
        - The global helpers: response(), redirect(), view(), route(), abort(),
          old(), csrf_field(), asset(), config() is available but takes a key.
        - A base Controller class. Vigen controllers extend nothing.
        - Artisan-style commands, service providers, form requests.

        Generated controllers import only "App\..." and "Vigen\..." classes.
        TEXT;

    /**
     * The full contract, as it should be shown to a model.
     *
     * @param array<string, string> $conventions resolved file-location conventions
     * @param string                $driver      the project's database driver, so the
     *                                           migration example is written in the SQL
     *                                           that database actually accepts
     */
    public static function forPrompt(array $conventions = [], string $driver = 'sqlite'): string
    {
        return self::NOT_LARAVEL
            . "\n\n"
            . self::routes()
            . "\n\n"
            . self::controllers()
            . "\n\n"
            . self::models()
            . "\n\n"
            . self::migrations($driver)
            . "\n\n"
            . self::migrationRule()
            . "\n\n"
            . self::views()
            . "\n\n"
            . self::support()
            . "\n\n"
            . self::webApplicationRule()
            . (($conventions === []) ? '' : "\n\n" . self::conventions($conventions));
    }

    public static function routes(): string
    {
        return <<<'TEXT'
        ROUTES - routes/web.php

        The $router variable is already in scope. Do not instantiate it.

            <?php

            use App\Http\Controllers\AuthController;
            use Vigen\View\View;

            $router->get('/', fn () => View::render('welcome'));

            $router->get('/login', [AuthController::class, 'showLogin']);
            $router->post('/login', [AuthController::class, 'login']);
            $router->get('/register', [AuthController::class, 'showRegister']);
            $router->post('/register', [AuthController::class, 'register']);
            $router->post('/logout', [AuthController::class, 'logout']);

            $router->get('/posts/{id}', [PostController::class, 'show']);
            $router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);

        Methods: get, post, put, patch, delete.
        "{id}" is passed to the action as an argument, matched by parameter name.
        The optional third argument is a list of middleware aliases. "auth" and
        "guest" are built in; more can be registered in config/app.php.
        An action may be [Controller::class, 'method'] or a closure.
        TEXT;
    }

    public static function controllers(): string
    {
        return <<<'TEXT'
        CONTROLLERS - app/Http/Controllers

        No base class. Constructor dependencies are autowired, so type-hinted
        constructor parameters are resolved from the container automatically.

            <?php

            namespace App\Http\Controllers;

            use App\Models\User;
            use Vigen\Auth\Auth;
            use Vigen\Auth\Hash;
            use Vigen\Http\Request;
            use Vigen\Http\Response;
            use Vigen\View\View;

            class AuthController
            {
                public function showLogin(Request $request): Response
                {
                    return View::render('auth.login');
                }

                public function login(Request $request): Response
                {
                    $data = $request->validate([
                        'email' => 'required|email',
                        'password' => 'required|string',
                    ]);

                    if (! Auth::attempt($data['email'], $data['password'])) {
                        return View::render('auth.login', ['error' => 'Invalid credentials']);
                    }

                    return Response::redirect('/dashboard');
                }

                public function register(Request $request): Response
                {
                    $data = $request->validate([
                        'name' => 'required|string|max:255',
                        'email' => 'required|email|unique:users,email',
                        'password' => 'required|string|min:8|confirmed',
                    ]);

                    $user = User::create([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => Hash::make($data['password']),
                    ]);

                    Auth::login($user);

                    return Response::redirect('/dashboard');
                }
            }

        An action returns a Vigen\Http\Response. Returning a string is also
        accepted and becomes an HTML response.
        TEXT;
    }

    public static function models(): string
    {
        return <<<'TEXT'
        MODELS - app/Models

            <?php

            namespace App\Models;

            use Vigen\Database\Model;

            class User extends Model
            {
                protected static string $table = 'users';

                protected array $fillable = ['name', 'email', 'password'];
                protected array $hidden = ['password'];
            }

        Every column you pass to create()/fill() MUST be listed in $fillable,
        or Vigen throws. Set protected static bool $timestamps = false; on a
        model whose table has no created_at/updated_at columns.

        Available on a model:
          User::query()  User::find($id)  User::findOrFail($id)  User::all()
          User::where('email', $email)  User::create([...])  User::count()
          $user->save()  $user->update([...])  $user->delete()
          $user->toArray()  $user->fresh()  $user->column

        On the builder returned by query()/where():
          ->where($col, $val)  ->orWhere(...)  ->whereIn($col, [...])
          ->whereNull($col)  ->orderBy($col, 'desc')  ->limit($n)
          ->get()  ->first()  ->count()  ->exists()  ->update([...])  ->delete()
        TEXT;
    }

    /**
     * The migration section, written in the SQL the project's database accepts.
     *
     * The three drivers disagree on auto-increment columns and on text types,
     * and a model shown SQLite DDL will happily emit it for a MySQL project -
     * where `INTEGER PRIMARY KEY AUTOINCREMENT` is a syntax error. The driver
     * is stated in the prompt so the first migration is the right one.
     *
     * The column list is assembled as a string rather than nested heredocs so
     * the indentation of the finished example is explicit.
     */
    public static function migrations(string $driver = 'sqlite'): string
    {
        $driver = strtolower($driver);

        $database = match ($driver) {
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
            default => 'SQLite',
        };

        // The auto-increment primary key is the column the three drivers
        // spell most differently, so it is the one worth stating outright.
        $id = match ($driver) {
            'mysql' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'id BIGSERIAL PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        };

        $text = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
        $timestamp = $driver === 'sqlite' ? 'TEXT' : 'TIMESTAMP NULL';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $columns = [
            $id,
            "name {$text} NOT NULL",
            "email {$text} NOT NULL UNIQUE",
            "password {$text} NOT NULL",
            "created_at {$timestamp}",
            "updated_at {$timestamp}",
        ];

        $create = "CREATE TABLE users (\n                "
            . implode(",\n                ", $columns)
            . "\n            ){$suffix}";

        return <<<TEXT
        MIGRATIONS - database/migrations/YYYY_MM_DD_HHMMSS_create_users_table.php

        A migration file returns an array of closures. There is no schema
        builder - write real SQL. `php vigen migrate` runs these.

            <?php

            return [
                'up' => function (Vigen\\Database\\Connection \$db): void {
                    \$db->statement('{$create}');
                },
                'down' => function (Vigen\\Database\\Connection \$db): void {
                    \$db->statement('DROP TABLE users');
                },
            ];

        This project's database is {$database}. Write {$database}-compatible SQL and use
        the column types above - do not use another database's syntax. The
        auto-increment primary key shown above is the one that differs most;
        taking it from another database is a syntax error here.
        TEXT;
    }

    /**
     * The rule that gives a model a table to read and write.
     *
     * The migration section above says how to *write* a migration; nothing
     * said *when*, and a model does not reference its migration the way a
     * controller references a view - so the "every file you reference must be
     * in this response" rule cannot catch a missing one. The planner wrote
     * models, controllers, routes and views and no migration at all, which
     * leaves `php vigen migrate` with nothing to run and every query failing
     * with "no such table" the moment the app is used. The pairing has to be
     * stated outright, exactly as the view/route pairing above is.
     */
    public static function migrationRule(): string
    {
        return <<<'TEXT'
        EVERY MODEL NEEDS A TABLE, AND ONLY A MIGRATION CREATES ONE

        A model is a class in front of a table. Writing app/Models/User.php
        does not create the users table, so whenever your response creates a
        model - or adds a column to one - it MUST also include the migration
        for that table, in the same response:

            app/Models/User.php
            database/migrations/2026_09_17_120000_create_users_table.php

        - Name it YYYY_MM_DD_HHMMSS_create_<table>_table.php, timestamped
          later than every migration already in the project.
        - Its CREATE TABLE must carry every column the model's $fillable
          lists, plus the primary key, and created_at / updated_at unless the
          model sets $timestamps = false.
        - A new column on an existing table gets its own migration using
          ALTER TABLE. Never edit the original migration file.

        `php vigen migrate` runs only the files in database/migrations. A
        model shipped without one has no table, and every query against it
        fails with "no such table".
        TEXT;
    }

    public static function views(): string
    {
        return <<<'TEXT'
        VIEWS - resources/views/*.php

        Plain PHP. The array given to View::render() is extracted into local
        variables. e() escapes a value for HTML - always use it for anything
        that came from a request.

            <form method="POST" action="/login">
                <input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
                <input name="email" value="<?= e($email ?? '') ?>">
                <input name="password" type="password">
                <button type="submit">Log in</button>
            </form>

        View::render('auth.login') loads resources/views/auth/login.php and
        returns a Response. View::render('auth.login', ['error' => '...'])
        passes data.

        To include one template inside another, the class name MUST be fully
        qualified. A template is a plain PHP file with no namespace, so a bare
        View:: there resolves to the global \View and the page fails to load:

            <?= \Vigen\View\View::partial('partials.header') ?>

        Every POST form MUST include the hidden _token field above, or Vigen
        rejects the submission with a 419 page.

        For validation errors and old input:
            View::error('email')     first error for that field, or null
            View::errors()           all errors, keyed by field
            View::old('email', '')   the previous submission's value
        TEXT;
    }

    public static function support(): string
    {
        return <<<'TEXT'
        SUPPORT CLASSES

        Vigen\Http\Request
          input($key, $default)  all()  only([...])  has($key)  query($key)
          method()  path()  isMethod('post')  header($name)  validate([...])
          user()  expectsJson()

        Vigen\Http\Response
          Response::html($body, $status)  Response::json($data, $status)
          Response::redirect($path, $status)  Response::noContent()
          ->withHeader($name, $value)  ->withStatus($status)

        Vigen\Http\Session
          get($k, $d)  put($k, $v)  has($k)  forget($k)  flush()
          flash($k, $v)  getFlash($k, $d)  token()  verifyToken($t)  regenerate()

        Vigen\Auth\Auth      attempt($u, $p)  login($user)  user()  check()
                             guest()  id()  logout()
        Vigen\Auth\Hash      make($password)  check($password, $hash)
        Vigen\View\View      render($name, $data)  exists($name)  error($f)  old($k, $d)
        Vigen\Database\Connection  select($sql, $bindings)  statement($sql, $bindings)
                                   transaction($fn)
        Vigen\Http\Middleware  interface: handle(Request $request, callable $next): Response

        Helpers: e($value)  env($key, $default)  config($key, $default)  base_path($path)

        Validation rules: required, sometimes, nullable, string, email, url,
        numeric, integer, boolean, date, alpha_dash, min:n, max:n, size:n,
        confirmed, in:a,b,c, not_in:a,b,c, unique:table,column, exists:table,column
        TEXT;
    }

    /**
     * The rule that turns an API into a browsable site. Without it a model
     * generates POST-only JSON endpoints, and a browser asking for GET /login
     * gets a 404.
     */
    public static function webApplicationRule(): string
    {
        return <<<'TEXT'
        BROWSER-FACING FEATURES NEED PAGES, NOT JUST ENDPOINTS

        When a request describes something a person uses in a browser -
        authentication, a dashboard, a CRUD screen, a profile page - generate
        BOTH halves:

        1. A GET route per page, returning a rendered view.
           For authentication that is GET /login and GET /register.
        2. A POST route per form submission, plus the view containing the form.

        Do not generate a JSON-only API and call it done. A GET route that
        returns a view is required for every page the user is expected to
        visit. JSON responses are only for requests that explicitly ask for an
        API.

        Write the view files. A route pointing at a view that does not exist
        fails at runtime, so every View::render('x.y') in a controller must
        have a matching resources/views/x/y.php file in the same response.
        TEXT;
    }

    /**
     * @param array<string, string> $conventions
     */
    public static function conventions(array $conventions): string
    {
        $lines = ['WHERE FILES GO'];

        foreach ($conventions as $type => $path) {
            $lines[] = '- ' . ucfirst((string) $type) . ": {$path}";
        }

        return implode("\n", $lines);
    }
}
