# Database and Models

Vigen ships a small data layer: a PDO connection, a fluent query builder, and a
model that maps one class to one table. There is no schema builder, no migration
DSL, and no relation system - migrations are plain SQL.

The default driver is **SQLite**, which needs no server and writes a single file
to `database/database.sqlite`. Switching to MySQL is a `.env` change.

## Configuration

`config/database.php`:

```php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'vigen'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],
    ],
];
```

A `.env` value always wins over the config file, so a deployment can point at a
different database without editing code:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vigen
DB_USERNAME=vigen
DB_PASSWORD=secret
```

The SQLite file and its directory are created on first use, and foreign keys are
switched on for the connection.

## Migrations

A migration is a plain PHP file in `database/migrations/` that **returns an
array of two closures**. There is no base class to extend.

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

The filename should start with a timestamp - `YYYY_MM_DD_HHMMSS_` - because
files run in filename order.

```
php vigen migrate                  # run everything not yet applied
php vigen migrate --step=1         # run only the next migration
php vigen migrate status           # what has run, what has not
php vigen migrate rollback --step=1
php vigen migrate --fresh          # roll everything back, then run it all again
```

Applied filenames are recorded in a `migrations` table, so re-running `migrate`
is a no-op rather than an error.

### Portability

SQLite and MySQL disagree about auto-increment columns and some types. If you
plan to deploy on MySQL, write MySQL SQL from the start rather than SQLite SQL
that happens to work locally:

| | SQLite | MySQL |
|---|---|---|
| Auto id | `id INTEGER PRIMARY KEY AUTOINCREMENT` | `id INT AUTO_INCREMENT PRIMARY KEY` |
| Text | `TEXT` | `VARCHAR(255)` / `TEXT` |
| Timestamp | `TEXT` | `DATETIME` |

## Models

```php
<?php

namespace App\Models;

use Vigen\Database\Model;

class User extends Model
{
    protected static string $table = 'users';

    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
    protected bool $timestamps = true;
}
```

`$table` is optional - without it the class name is pluralised (`User` →
`users`, `Category` → `categories`, `Address` → `addresses`).

Two properties are worth understanding:

- **`$fillable`** is an allow-list for `create()` and `fill()`. A key that is not
  listed is **rejected with an `InvalidArgumentException`** rather than silently
  dropped, so a typo surfaces immediately. An empty `$fillable` accepts nothing -
  that is the safe default, not "allow everything".
- **`$hidden`** is stripped by `toArray()` and `toJson()`, for things like a
  password hash. The value is still readable through `$user->password`, so
  `Hash::check()` works; it just never reaches a response body.

### Reading and writing

```php
$user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);

$user->id;                              // set by the insert
$user->name = 'Ada Lovelace';
$user->save();

$user->update(['name' => 'A. Lovelace']);
$user->delete();
$user->fresh();                         // re-read from the database
$user->toArray();                       // password omitted
```

`created_at` and `updated_at` are filled in automatically when `$timestamps` is
true.

### Finding rows

```php
User::find(1);                          // a model, or null
User::all();
User::where('email', 'ada@example.com')->first();
User::where('active', true)->orderBy('name')->limit(10)->get();
User::where('role', 'admin')->count();
User::where('email', $email)->exists();
```

`find()`, `first()` and `get()` return models; `get()` returns a plain array of
them, so a loop needs no conversion.

### The query builder

`Model::query()` starts a builder directly, which is what you want when the
conditions are dynamic:

```php
use Vigen\Database\QueryBuilder;

$query = User::query()->where('active', true);

if ($search !== null) {
    $query->where('name', 'like', "%{$search}%");
}

$users = $query->orderBy('name')->get();
```

| Method | Notes |
|---|---|
| `where($column, $value)` | Shorthand for `= ` |
| `where($column, $operator, $value)` | Operators: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `like`, `not like` |
| `orWhere(...)` | Same forms, joined with `OR` |
| `whereIn($column, array $values)` | An empty array matches nothing, rather than everything |
| `orderBy($column, 'desc')` | Ascending by default |
| `limit($n)` | |
| `get()` | Array of models |
| `first()` | One model, or `null` |
| `count()` | Integer |
| `exists()` | Boolean |
| `insert(array $attributes)` | |
| `update(array $values)` | Affected row count |
| `delete()` | Affected row count |

Every **value** is passed as a bound parameter - never interpolated into the
SQL. Column and table names cannot be bound, so they are validated against
`/^[A-Za-z_][A-Za-z0-9_]*$/` and quoted for the driver; anything else throws
rather than reaching the database.

## Using the connection directly

For a report query, or anything the builder does not cover:

```php
use Vigen\Database\Connection;

$db = Connection::current();

$rows = $db->select('SELECT status, COUNT(*) AS total FROM orders GROUP BY status');

$db->statement('UPDATE users SET active = ? WHERE last_seen < ?', [false, $cutoff]);
```

| Method | Returns |
|---|---|
| `select($sql, $bindings)` | Every row as an associative array |
| `statement($sql, $bindings)` | Affected row count |
| `lastInsertId()` | The id from the last insert |
| `transaction(callable $callback)` | Commits on return, rolls back on any exception |
| `driver()` | `sqlite` or `mysql` |

```php
$db->transaction(function (Connection $db): void {
    $db->statement('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $db->statement('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
});
```

If the callback throws, both statements are rolled back and the exception
propagates.

## Testing a data layer

`Connection::swap()` replaces the live connection, and SQLite's `:memory:` driver
gives each test a database that is discarded when the connection closes:

```php
protected function setUp(): void
{
    parent::setUp();

    Connection::swap(new Connection(['driver' => 'sqlite', 'database' => ':memory:']));

    Connection::current()->statement('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at TEXT,
        updated_at TEXT
    )');
}
```

Call `Connection::swap(null)` to go back to the configured connection.
