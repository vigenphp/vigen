<?php
/**
 * @var string $method
 * @var string $path
 * @var list<string> $routes
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 Not Found</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0b0d12; color: #e7e9ee;
        }
        main { width: min(640px, calc(100% - 3rem)); padding: 2rem 0; }
        h1 { font-size: 2rem; margin: 0 0 .25rem; }
        p { color: #9aa3b2; }
        ul { list-style: none; padding: 0; }
        li { border: 1px solid #232733; border-radius: 8px; padding: .5rem .8rem; margin-bottom: .5rem; background: #12151c; }
        code { font-family: ui-monospace, Consolas, monospace; }
    </style>
</head>
<body>
<main>
    <h1>404 Not Found</h1>
    <p>No route matches <code><?= e($method ?? '') ?> <?= e($path ?? '') ?></code>.</p>
    <?php if (! empty($routes)): ?>
        <p>These routes are registered:</p>
        <ul>
            <?php foreach ($routes as $route): ?>
                <li><code><?= e($route) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>No routes are registered at all. Add one to <code>routes/web.php</code>.</p>
    <?php endif; ?>
</main>
</body>
</html>
