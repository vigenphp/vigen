# GUI Usage

The chatbox is its own command:

```bash
php vigen gui
```

Then open `http://127.0.0.1:8808`. It launches PHP's built-in server with a small router (`src/GUI/router.php`) that serves the chat UI (`resources/gui/index.html`) and handles `POST /api/chat`, backed by the same `AIEngine` the CLI uses via `Vigen\GUI\Server`.

Change the host/port with `php vigen gui --host=0.0.0.0 --port=8080`.

`php vigen gui` is what `php vigen serve` used to do. `php vigen serve` now serves
your *application* (`public/index.php`) rather than the chat UI - see
`getting-started.md` for the difference. Both default to port `8808`, so pass
`--port` if you want them running at the same time:

```bash
php vigen serve --port=8000
php vigen gui --port=8808
```

If you run `php vigen serve --gui` out of habit, it prints a pointer to
`php vigen gui` rather than starting something unexpected.
