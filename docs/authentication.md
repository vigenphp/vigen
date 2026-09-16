# Authentication

Vigen's auth is a session-backed guard in `Vigen\Auth`, plus two middleware
aliases. There is no `Auth` facade - it is a class you call statically.

## Configuration

`config/auth.php`:

```php
return [
    'model' => App\Models\User::class,
    'username' => 'email',
    'login_path' => '/login',
    'home_path' => '/',
];
```

| Key | Meaning |
|---|---|
| `model` | The class `Auth` loads users from. It **must** extend `Vigen\Database\Model`; anything else throws a `RuntimeException` naming the class, rather than failing later in a confusing way. |
| `username` | The column matched against `Auth::attempt()`'s first argument. Set it to `username` if you log in with a handle instead of an email. |
| `login_path` | Where the `auth` middleware sends a guest. |
| `home_path` | Where the `guest` middleware sends an authenticated user. |

## Password hashing

```php
use Vigen\Auth\Hash;

$hash = Hash::make($password);          // password_hash(..., PASSWORD_DEFAULT)
Hash::check($password, $stored);        // timing-safe verify
Hash::needsRehash($stored);             // true when the cost has moved on
```

`Hash::make()` produces a bcrypt hash by default - a 60-character string. Store it
in a `TEXT`/`VARCHAR(255)` column.

**Never** store a plain password, and never flash one back into a form. Vigen's
validation already omits password fields from flashed input for that reason.

`Hash::check()` uses `password_verify()`, which is constant-time with respect to
the hash, so it cannot be used to guess a hash character by character.

## The guard

```php
use Vigen\Auth\Auth;

Auth::attempt($email, $password);       // verify + log in; true on success
Auth::login($user);                     // log in a model directly
Auth::user();                           // the model, or null
Auth::check();                          // is anyone logged in
Auth::guest();                          // the inverse
Auth::id();                             // the primary key, or null
Auth::logout();
```

The session holds only the user's **primary key**, and `Auth::user()` re-reads
the row on each request. So a deleted account stops working immediately instead
of living on inside a stale session, and a renamed one shows its new name without
a re-login.

Two details worth knowing:

- `Auth::attempt()` re-reads the user by `username` and then verifies the hash.
  When no row matches, it still runs a hash comparison against a dummy value, so
  a missing account and a wrong password take comparable time - otherwise
  response timing would reveal which addresses are registered.
- Both `login()` and `logout()` call `Session::regenerate()`, which rotates the
  session id. That is what prevents session fixation: an attacker who plants a
  known session id before login ends up with an id that is useless afterwards.

`Auth::attempt()` returns `false` for both "no such user" and "wrong password",
and deliberately does not say which. Report it to the user as one message:

```php
if (! Auth::attempt($data['email'], $data['password'])) {
    return View::render('auth.login', ['error' => 'Invalid credentials']);
}
```

## Protecting routes

```php
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
```

| Alias | Effect |
|---|---|
| `auth` | A guest is redirected to `login_path`, with the page they wanted remembered. A request expecting JSON gets `401` instead of a redirect. |
| `guest` | An authenticated user is redirected to `home_path`. |

The `auth` middleware stores the blocked path under `url.intended` before
redirecting, so a login handler can return the user to where they were headed:

```php
use Vigen\Http\Session;

$intended = Session::get('url.intended') ?? '/dashboard';
Session::forget('url.intended');

return Response::redirect($intended);
```

Only the path is stored, never a full URL, so the value cannot be turned into an
open redirect.

## A complete module

Four pieces: a migration, a model, a controller, and two views. `vigen chat
"create an authentication module"` generates all of them - this is what the
output should look like.

### 1. The migration

```php
<?php
// database/migrations/2026_09_16_000000_create_users_table.php

use Vigen\Database\Connection;

return [
    'up' => function (Connection $db): void {
        $db->statement('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');
    },

    'down' => function (Connection $db): void {
        $db->statement('DROP TABLE users');
    },
];
```

### 2. The model

```php
<?php
// app/Models/User.php

namespace App\Models;

use Vigen\Database\Model;

class User extends Model
{
    protected static string $table = 'users';

    protected array $fillable = ['name', 'email', 'password'];

    /** Never serialised into a response. */
    protected array $hidden = ['password'];
}
```

### 3. The controller

```php
<?php
// app/Http/Controllers/AuthController.php

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

    public function showRegister(Request $request): Response
    {
        return View::render('auth.register');
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

    public function logout(Request $request): Response
    {
        Auth::logout();

        return Response::redirect('/');
    }
}
```

Note `class AuthController` extends **nothing**. Constructor dependencies are
autowired by the container, so a repository can be injected by type-hinting it.

### 4. The views

`resources/views/auth/login.php` - `views.md` explains each part:

```php
<!doctype html>
<html>
<head><title>Log in</title></head>
<body>
    <h1>Log in</h1>

    <?php if (! empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/login">
        <input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">

        <label>Email <input name="email" type="email" value="<?= e(View::old('email')) ?>"></label>
        <?php if (View::error('email')): ?>
            <span class="error"><?= e(View::error('email')) ?></span>
        <?php endif; ?>

        <label>Password <input name="password" type="password"></label>

        <button type="submit">Log in</button>
    </form>

    <p><a href="/register">Create an account</a></p>
</body>
</html>
```

`resources/views/auth/register.php` follows the same shape, with a
`password_confirmation` field - the `confirmed` rule requires it:

```php
<label>Password <input name="password" type="password"></label>
<label>Confirm password <input name="password_confirmation" type="password"></label>
```

### 5. The routes

```php
<?php
// routes/web.php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Vigen\View\View;

$router->get('/', fn () => View::render('welcome'));

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
```

Every page is a **pair**: a `GET` that renders the form, and a `POST` that
handles it. A module with only the `POST` routes returns 404 to a browser - the
single most common way generated code looks finished but does not run.

## CSRF

Every `POST`, `PUT`, `PATCH` and `DELETE` needs a token, or the request is
rejected with a **419** page:

```php
<input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
```

Requests sending `Accept: application/json` are exempt, on the assumption that an
API authenticates with a token rather than a cookie. Turn the check off entirely
with `'csrf' => false` in `config/app.php` - only for an API-only app.

## What this deliberately does not include

No remember-me cookie, no password-reset flow, no email verification, no roles or
permissions, no OAuth. Each is a feature you can build on top of this: a
`password_resets` table and a token column is enough for resets, and a `role`
column plus a middleware is enough for permissions.

Until you add them, do not assume they exist - and if you ask a model to add one,
say which table and columns it should use.
