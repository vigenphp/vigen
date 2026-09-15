# GUI Usage

If you chose **GUI** during `php vigen init`, start it with:

```bash
php vigen serve
```

This launches PHP's built-in server with a small router (`src/GUI/router.php`) that serves the chatbox (`resources/gui/index.html`) and handles `POST /api/chat`, backed by the same `AIEngine` the CLI uses via `Vigen\GUI\Server`.

Change the host/port with `php vigen serve --host=0.0.0.0 --port=8000`.
