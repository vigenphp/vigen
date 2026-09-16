# CLI Usage

Everything runs through the root `vigen` script Composer publishes:

```bash
php vigen init      # one-time setup: interface, provider, model
php vigen chat "Create an authentication module"
php vigen migrate   # create the tables your migrations describe
php vigen serve     # run your application
php vigen gui       # run the AI chatbox in a browser
```

If the plugin didn't run, `php vendor/vigenphp/vigen/bin/vigen ...` works identically - see `installation.md`.

## The two servers

`serve` and `gui` are different things, and mixing them up is the usual reason a
generated page 404s:

| Command | Serves | Default URL |
|---|---|---|
| `php vigen serve` | Your application - `public/index.php` | `http://127.0.0.1:8808` |
| `php vigen gui` | The AI chatbox | `http://127.0.0.1:8808` |

Both take `--host` and `--port`, and both default to the same port, so give one
of them a different one to run them at once:

```bash
php vigen serve --port=8000
php vigen gui --port=8808
```

`php vigen serve` already ran the chatbox in earlier versions; it now runs your
app, and `vigen serve --gui` prints a one-line pointer to `vigen gui`.
`vigen gui` is `vigen serve`'s old behaviour, unchanged.

## Generating code

`vigen chat` prints its progress through each workflow stage: analyzing, planning, modifying files, and validating.

```bash
php vigen chat "Create an API endpoint for products"
```

A prompt that describes a page produces **both** the route that renders the form
and the route that handles it - see `prompting.md` for how to ask.

## Migrations

```bash
php vigen migrate                     # run everything not yet applied
php vigen migrate --step=1            # run only the next migration
php vigen migrate status              # what has run, what has not
php vigen migrate rollback --step=1   # undo the last one
php vigen migrate --fresh             # roll everything back, then re-run it all
```

Applied filenames are recorded in a `migrations` table, so re-running `migrate`
does nothing rather than erroring.

## Dry runs

`--dry-run` plans, reports, and syntax-checks the change without writing anything:

```bash
php vigen chat --dry-run "Create an API endpoint for products"
```

The proposed files are linted from a scratch directory, so a preview still catches broken PHP before you commit to it.

## Reading the output

Each file is reported with what actually happened to it:

```
> Modifying files...
  ✓ created  app/Models/User.php
  ✓ created  app/Http/Controllers/AuthController.php
  ✓ modified routes/web.php
  • unchanged app/Http/Middleware/Authenticate.php
```

`✗ rejected` means the model proposed a path Vigen will not write to - an absolute path, or one that resolves outside the project. `✗ failed` means the write itself could not be completed, with the reason in parentheses.

A run that produces no files says so, and explains why:

```
> Modifying files...
  No file changes were produced.
  - No JSON object could be parsed from the model response. First 200 characters: ...
```

### Validation errors

Before anything is written, each proposed file is checked twice: `php -l` must
accept it, and every class it references must be one Vigen actually provides. A
file that imports `Illuminate\Support\Facades\Route` is valid PHP that would
fatal on the first request, so the second check is what catches it:

```
> Validating...
  ✗ app/Http/Controllers/AuthController.php:9 imports
    [Illuminate\Support\Facades\Hash], which is not part of Vigen.
    Vigen has no Hash facade. Use Vigen\Auth\Hash::make() / Hash::check().
```

The model is then given those errors and one chance to correct itself. If the
second attempt still fails, nothing is written and `vigen chat` exits non-zero.

## Backups

Anything Vigen overwrites or deletes is copied to `.vigen/backups/<timestamp>/<path>` first, preserving its original relative path. The directory is added to `.gitignore` by `vigen init`.
