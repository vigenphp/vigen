# Requests and Responses

## The request

Every controller action and route closure can ask for the current request by type-hinting it:

```php
use Vigen\Http\Request;

public function store(Request $request): Response
```

| Method | Returns |
|---|---|
| `input($key, $default)` | A value from the body, falling back to the query string. Supports dot notation: `input('user.email')`. |
| `query($key, $default)` | A value from the query string only |
| `all()` | The query string and the body, merged |
| `only(['a', 'b'])` | Just those keys |
| `has($key)` | Whether the key is present and not blank |
| `method()` | `GET`, `POST`, ... |
| `isMethod('post')` | Whether the verb matches |
| `isPost()` | `method() === 'POST'` |
| `path()` | The path, without the query string |
| `header($name)` | A request header |
| `expectsJson()` | Whether the client asked for JSON |
| `user()` | The authenticated user, or `null` |
| `route($key)` | A captured route parameter |
| `isSecure()` | Whether the request arrived over HTTPS |
| `ip()` | The client address |
| `validate($rules)` | Validated input, or a redirect back on failure |

JSON bodies are decoded automatically when the `Content-Type` is `application/json`, so `input()` reads a JSON post exactly as it reads a form post.

## Validation

```php
$data = $request->validate([
    'name' => 'required|string|max:255',
    'email' => 'required|email|unique:users,email',
    'password' => 'required|string|min:8|confirmed',
]);
```

On success you get back an array containing **only** the validated fields - so `$data` is safe to pass straight to a model without a separate allow-list.

On failure, `Vigen\Http\ValidationException` is thrown and the kernel handles it:

- **A browser form post** - redirects back to the form with the errors and the input flashed into the session (`views.md` shows how to display them).
- **A request expecting JSON** - a `422` response: `{"message": "...", "errors": {"email": ["..."]}}`.

### Rules

| Rule | Passes when |
|---|---|
| `required` | Present and not blank |
| `sometimes` | Skips every other rule when the field was not submitted |
| `nullable` | Accepts `null` and skips the remaining rules |
| `string` | Is a scalar |
| `email` | Passes `FILTER_VALIDATE_EMAIL` |
| `url` | Passes `FILTER_VALIDATE_URL` |
| `numeric` | `is_numeric()` |
| `integer` | A whole number |
| `boolean` | `true`, `false`, `1`, `0`, `'1'`, `'0'` |
| `date` | Anything `strtotime()` understands |
| `alpha_dash` | Letters, numbers, dashes and underscores only |
| `min:n` | Length for text, magnitude for numbers |
| `max:n` | Length for text, magnitude for numbers |
| `size:n` | Exactly that length or magnitude |
| `confirmed` | A matching `<field>_confirmation` was submitted |
| `in:a,b,c` | One of the listed values |
| `not_in:a,b,c` | None of the listed values |
| `unique:table,column` | No other row has that value (column defaults to the field name) |
| `exists:table,column` | Some row has that value |

`unique` and `exists` run a real query, so the table must exist. A third parameter on `unique` excludes a row - useful when editing: `unique:users,email,42`.

Rules are separated by `|`, or given as an array. An unknown rule name raises a `RuntimeException` rather than silently passing, so a typo cannot let bad data through.

A field that is neither `required` nor `nullable` is skipped when blank, so optional fields can be omitted.

## The response

`Vigen\Http\Response` is immutable - every `with*` method returns a new instance.

```php
use Vigen\Http\Response;

return Response::html('<h1>Hello</h1>');
return Response::html('<h1>Not found</h1>', 404);
return Response::json(['user' => $user]);
return Response::json(['message' => 'Unauthorized'], 401);
return Response::redirect('/dashboard');
return Response::noContent();
```

| Method | Purpose |
|---|---|
| `Response::html($body, $status)` | An HTML response |
| `Response::text($body, $status)` | A plain-text response |
| `Response::json($data, $status)` | A JSON response |
| `Response::redirect($path, $status)` | A redirect, 302 by default |
| `Response::noContent()` | A 204 |
| `->withHeader($name, $value)` | Add a header |
| `->withStatus($status)` | Change the status |
| `status()` | The status code |
| `body()` | The body |
| `header($name)` | One header |
| `headers()` | Every header |
| `send()` | Emit it to the client |

Chain them as needed:

```php
return Response::redirect('/login')->withHeader('X-Reason', 'session-expired');
return View::render('auth.login', ['error' => 'Invalid credentials'])->withStatus(422);
```

## The session

`Vigen\Http\Session` is static, which keeps controller and template code short.

```php
use Vigen\Http\Session;

Session::put('cart_id', 42);
Session::get('cart_id');
Session::has('cart_id');
Session::forget('cart_id');
Session::flush();                      // clears values, keeps the CSRF token
```

Flash values survive exactly one further request - which is what makes an error message appear on the page a form redirects *back* to, and not on the one after that.

```php
Session::flash('status', 'Saved.');
Session::getFlash('status');           // readable on the next request only
```

`Session::token()` returns the CSRF token, generating one on first use, and `Session::verifyToken($token)` compares it in constant time. The id is rotated on login and logout.

## Errors

The kernel catches everything and translates it into a page:

| Situation | Result |
|---|---|
| `ValidationException` | Redirect back with errors, or 422 JSON |
| Wrong CSRF token | 419, showing the field the form is missing |
| `NotFoundException` | 404, listing the registered routes |
| Any other exception | 500, logged to `storage/logs/vigen.log` |

An unhandled exception is written to `storage/logs/vigen.log` with its class, message, file and line. Set `'debug' => true` in `config/app.php` (or `APP_DEBUG=true` in `.env`) to also see the trace in the browser - never in production.
