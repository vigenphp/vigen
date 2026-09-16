# Views

Vigen has no template language. A view is a plain PHP file, which means there is nothing to learn, nothing to compile, and nothing for a code generator to get subtly wrong.

Views live in `resources/views/` (`config('app.paths.views')`).

```
resources/views/
├── welcome.php
├── dashboard.php
├── auth/
│   ├── login.php
│   └── register.php
└── partials/
    └── header.php
```

## Rendering

`View::render()` returns a `Vigen\Http\Response`, so a controller returns it directly:

```php
use Vigen\View\View;

return View::render('auth.login');
```

Names use dots for directories: `'auth.login'` loads `resources/views/auth/login.php`.

The second argument is the data the template receives. Each key becomes a local variable:

```php
return View::render('dashboard', ['user' => $user, 'posts' => $posts]);
```

```php
<h1>Welcome back, <?= e($user->name) ?></h1>
```

## Escaping

**Always** pass request-derived values through `e()`. It escapes `&`, `<`, `>`, `"` and `'` for HTML:

```php
<p><?= e($comment) ?></p>
<input name="email" value="<?= e($email) ?>">
```

`View::escape()` does the same thing if you prefer it explicit.

## Including another view

`View::partial()` renders to a string, for use inside another template:

```php
<?= View::partial('partials.header') ?>
<main>...</main>
```

## Forms and CSRF

Every `POST`, `PUT`, `PATCH` and `DELETE` request is checked for a CSRF token. A form without one is rejected with a **419** page, so include the hidden field in every form:

```php
<form method="POST" action="/login">
    <input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
    <input name="email">
    <input name="password" type="password">
    <button type="submit">Log in</button>
</form>
```

JSON clients that send `Accept: application/json` are exempt, on the assumption that an API authenticates with a token rather than a session cookie. Turn the check off entirely with `'csrf' => false` in `config/app.php`.

## Showing validation errors

When validation fails on a form post, Vigen redirects back to the form with the errors and the input flashed into the session. Three helpers read them:

```php
<?php if (View::error('email')): ?>
    <p class="error"><?= e(View::error('email')) ?></p>
<?php endif; ?>

<input name="email" value="<?= e(View::old('email')) ?>">
```

| Helper | Returns |
|---|---|
| `View::error($field)` | The first error message for that field, or `null` |
| `View::errors()` | Every error, keyed by field |
| `View::old($field, $default)` | The value submitted last time |

All three are empty on a normal page load, so they are safe to use unconditionally.

Password fields are **never** flashed back, so `View::old('password')` is always empty. That is deliberate - flashed values are written to the session store on disk.

## A complete example

```php
<?php
/** @var string|null $error */
?>
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

        <label>
            Email
            <input name="email" type="email" value="<?= e(View::old('email')) ?>">
        </label>
        <?php if (View::error('email')): ?>
            <span class="error"><?= e(View::error('email')) ?></span>
        <?php endif; ?>

        <label>
            Password
            <input name="password" type="password">
        </label>

        <button type="submit">Log in</button>
    </form>
</body>
</html>
```

## What about layout inheritance?

There is none, and that is on purpose. Use `View::partial()` for a shared header and footer:

```php
<?= View::partial('partials.header', ['title' => 'Log in']) ?>
<h1>Log in</h1>
<?= View::partial('partials.footer') ?>
```

A runtime small enough for a local model to hold in context is worth more than template inheritance.
