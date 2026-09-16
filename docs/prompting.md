# Prompting Vigen

Describe the outcome, not the implementation:

> Create a product management system.

Vigen determines this requires a model, migration, controller, routes, views, and validation - you don't need to name each file.

## What happens to your prompt

```
prompt -> context -> plan -> file changes -> apply -> validation -> (fix loop) -> done
```

1. **Context.** Vigen sends the model the project's PHP version, its dependencies, and the *contents* of the files whose paths look relevant to your request. Sending the bodies - not just the paths - is what lets it edit `routes/web.php` instead of overwriting it.
2. **Plan + generate.** One model call returns a JSON plan carrying the complete body of every file it wants to create, modify, or delete.
3. **Apply.** The plan is written to disk. Anything overwritten or deleted is backed up first under `.vigen/backups/`.
4. **Validate.** Every generated `.php` file is checked with `php -l`, **and** every class it references is checked against the list of classes Vigen actually provides.
5. **Fix loop.** If either check fails, the model is given the specific errors and asked once to correct the files, which are then re-applied and re-checked.

## The API the model is given

Step 2 is only reliable if the model knows what it is writing against, so Vigen's
planner prompt carries the complete runtime API: the router, `Request`,
`Response`, `Session`, `View`, `Auth`, `Hash`, `Model` and the migration format,
plus the directory conventions.

You never need to state any of this yourself. What matters is the rule that goes
with it:

> **Vigen is not Laravel.** `Illuminate\`, `Laravel\` and `Symfony\` do not
> exist here. There is no `Route` facade, no `Hash` facade, no `Auth` facade, no
> `DB` facade, no `Schema` builder, no Eloquent, no Blade, no `response()`, no
> `redirect()`, no `view()`, and controllers extend nothing.

That rule is enforced rather than merely requested. Every generated file is
parsed before it is written, and an import of a foreign class is a validation
error - which sends the model back for a correction instead of writing code that
would fatal on the first request.

If you see Vigen produce Laravel code anyway (an older run, or a model ignoring
the instructions), the error message names the file, the line and the Vigen
replacement. See `troubleshooting.md`.

## Browser-facing features need pages

An LLM's instinct for "create an authentication module" is a JSON API: a
`POST /login` endpoint and nothing a browser can open. Vigen's prompt states the
rule directly:

> Every browser-facing feature needs a **GET route that renders a view**, and a
> **POST route per form**, plus the view file itself.

So a well-formed response to *"create an authentication module"* contains a
migration, a model, a controller, `resources/views/auth/login.php`,
`resources/views/auth/register.php`, and a `routes/web.php` with both the `GET`
and the `POST` for each page. If a generated route 404s in a browser, check that
its `GET` half exists.

## The response contract

The model is instructed to reply with a single JSON object and nothing else:

```json
{
  "summary": "one line describing the change",
  "files": [
    {
      "path": "app/Models/User.php",
      "action": "create",
      "contents": "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n",
      "reason": "why this file is needed"
    }
  ]
}
```

- `action` is `create`, `modify`, or `delete`.
- `path` is always relative to the project root. Absolute paths, and anything that resolves outside the project, are refused.
- `contents` must be the **complete** final body of the file - not a diff and not a fragment. A `create` or `modify` with an empty body is rejected rather than written, because it almost always means the model truncated its answer.
- Every file the response *refers to* must be in the response. If a controller calls `View::render('auth.login')`, then `resources/views/auth/login.php` has to be one of the entries - there is no later step that fills it in.

The parser is deliberately forgiving about *packaging* - JSON inside a ```json fence, JSON surrounded by prose, and a stray trailing comma are all accepted - and strict about *content*. If a response genuinely cannot be used, `vigen chat` says so and quotes the beginning of what the model returned, rather than reporting success for an empty change set.

## Providers and JSON mode

Vigen asks each provider for structured output in that provider's own way: Ollama's `format: json`, OpenAI's `response_format`. Claude and Gemini have no equivalent, so they rely on the prompt and the tolerant parser instead.

## Previewing a change

`vigen chat --dry-run "<prompt>"` runs the whole pipeline - including both validation passes, which lint and parse the proposed files from a scratch directory - and writes nothing to disk.

## Prompts that work well

Being specific about the *shape* of the feature gets a more complete answer than
being specific about the code:

| Instead of | Try |
|---|---|
| "add a login route" | "add a login page with a form and a POST handler" |
| "user API" | "a JSON API for users: GET /users, POST /users, GET /users/{id}" |
| "blog" | "a blog: a list page, a detail page, and a form to create a post" |

Then run it:

```
php vigen migrate      # create any tables the generated migrations describe
php vigen serve        # then browse to the route
```
