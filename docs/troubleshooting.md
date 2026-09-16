# Troubleshooting

**Vigen can't reach my provider.** Check the relevant `.env` variable (`OLLAMA_HOST`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, or `GEMINI_API_KEY`) and that the provider is configured (`AIProviderInterface::isConfigured()`).

**A generated route returns 404.** Work through these in order - the 404 page itself does most of the work, because it **lists every route that is registered**:

1. **Are you running the app or the chatbox?** `php vigen serve` runs your application; `php vigen gui` runs the AI chatbox. The chatbox answers `/` and `/api/chat` and 404s everything else. If the page you see mentions chatting, you are on the wrong server.
2. **Is the route registered at all?** Read the 404 page's route list. If your route is missing, the model edited a different file, or `routes/web.php` is not the file being loaded (`config('app.paths.routes')`).
3. **Is there a `GET` route?** A model asked for "a login" often writes only `POST /login`. A browser issuing `GET /login` then has nothing to match - the POST route exists, but it is not what the browser asked for. Every page needs both halves.
4. **Wrong verb?** If the path exists under a different verb you get a **405** naming the verbs that do work, rather than a 404.
5. **Is the controller class autoloadable?** Check the namespace matches the directory (`App\Http\Controllers` in `app/Http/Controllers/`) and that `app/` is in `composer.json`'s autoload map. Run `composer dump-autoload` after adding a directory.
6. **Does the view exist?** A controller that renders a missing view throws, which is a **500** with the searched path in the message, not a 404 - but the page is still blank where you expected a form.

**Vigen generated Laravel code.** Earlier versions gave the model Laravel's directory conventions and never stated Vigen's own API, so a model trained on Laravel wrote Laravel - `Illuminate\Support\Facades\Route`, `Eloquent`, `class AuthController extends Controller`. That code passes `php -l` and fatals on the first request.

Current versions fix this on both ends: the planner prompt carries the whole Vigen API plus a "Vigen is not Laravel" rule, and every generated file is parsed before it is written. An import of `Illuminate\` (or `Laravel\`, `Symfony\`, `Doctrine\`, `Carbon\`) is now a **validation error**, which sends the model back for one correction:

```
✗ app/Http/Controllers/AuthController.php:9 imports
  [Illuminate\Support\Facades\Hash], which is not part of Vigen.
  Vigen has no Hash facade. Use Vigen\Auth\Hash::make() / Hash::check().
```

If files from an older run are still on disk, the model will keep seeing them as
context and building on them. Delete them and re-prompt:

```
del app\Http\Controllers\AuthController.php   # or remove app\ entirely
php vigen chat "create an authentication module"
```

Then confirm nothing foreign survived:

```
findstr /s /i "illuminate" app routes
```

**Vigen made no changes at all.** The command tells you why instead of failing silently. The usual causes, in order:

- *"No JSON object could be parsed from the model response"* - the model answered in prose. Vigen asks for JSON explicitly and switches the provider into its native JSON mode where one exists, but small local models still drift. The message quotes the first 200 characters of what came back so you can see what it did instead. Re-running usually helps; a larger model helps more.
- *"Entry ... had no contents - refused to write an empty file"* - the model described the right files but truncated their bodies. Often a context or output-token limit; try a more specific prompt.
- *"Path is absolute, escapes the project directory..."* - the model proposed a path outside your project. Vigen will not write it. Rephrase the request to be explicit about where the files should go.
- *"No file changes were produced"* with no further detail - the model decided the request needed no changes, and returned `files: []`.

**Validation keeps failing.** Vigen retries once through its self-correction loop, then prints the errors so you can fix them manually. The files it wrote are still on disk; the pre-edit versions are in `.vigen/backups/`.

The errors are one of two kinds, and the message says which:

- a **syntax** error from `php -l`, with the offending line;
- a **foreign reference** - the file parses, but names a class Vigen does not have. The message names the file, the line, the class and its Vigen replacement. This is the more common one, and it means the model was writing against another framework.

**`Call to undefined function e()` / `config()` / `base_path()`.** Composer has not loaded `src/Support/helpers.php`. Run `composer dump-autoload`; if you publish the package yourself, confirm `"files": ["src/Support/helpers.php"]` is present in its `autoload` block.

**419 after submitting a form.** The CSRF token is missing or stale. Every `POST` form needs:

```php
<input type="hidden" name="_token" value="<?= e(Vigen\Http\Session::token()) ?>">
```

An expired session also produces this - restart `vigen serve` and reload the form.

**"Base table or view not found" / "no such table".** The migration has not run: `php vigen migrate`. Then check it actually applied with `php vigen migrate status`.

**A change to `config/*.php` or `.env` had no effect.** Configuration is read once per process. Restart `vigen serve`.

**Vigen overwrote something it shouldn't have.** Every `modify` and `delete` is backed up first. Look in `.vigen/backups/<timestamp>/`, which mirrors your project's directory layout. Use `--dry-run` to inspect a plan before letting it touch anything.

**A file I expected to be included wasn't sent to the model.** Context is chosen by matching keywords from your prompt against project-relative file paths, so a file only travels with the request if its path resembles your wording. Naming the file in the prompt is the most reliable fix.

**A 500 with no detail.** Unhandled exceptions are written to `storage/logs/vigen.log` with the class, message, file and line. Set `'debug' => true` in `config/app.php` (or `APP_DEBUG=true` in `.env`) to see the trace in the browser as well - never leave that on in production.
