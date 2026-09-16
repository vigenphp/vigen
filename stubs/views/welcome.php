<?php
/**
 * The page you get at "/" in a fresh Vigen project.
 *
 * Views are plain PHP. The array passed to View::render() is extracted into
 * local variables, and e() escapes output for HTML.
 *
 * @var string $title
 * @var list<string> $routes
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Vigen') ?></title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0b0d12;
            color: #e7e9ee;
        }
        main { width: min(640px, calc(100% - 3rem)); padding: 2rem 0; }
        h1 { margin: 0 0 .25rem; font-size: 2rem; letter-spacing: -.02em; }
        p.lead { margin: 0 0 2rem; color: #9aa3b2; }
        h2 { font-size: .75rem; text-transform: uppercase; letter-spacing: .1em; color: #9aa3b2; margin: 0 0 .75rem; }
        ul { list-style: none; margin: 0 0 2rem; padding: 0; }
        li { border: 1px solid #232733; border-radius: 8px; padding: .6rem .9rem; margin-bottom: .5rem; background: #12151c; }
        a { color: #7dd3fc; text-decoration: none; }
        a:hover { text-decoration: underline; }
        code { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .9em; }
        .ok { color: #4ade80; }
    </style>
</head>
<body>
<main>
    <h1><?= e($title ?? 'Vigen') ?></h1>
    <p class="lead">
        <span class="ok">&#10003;</span>
        The framework is serving this page, which means routing, views and the
        HTTP kernel are all working.
    </p>

    <h2>What next</h2>
    <ul>
        <li>Generate a feature: <code>php vigen gui</code>, then ask for one.</li>
        <li>Add routes in <code>routes/web.php</code>.</li>
        <li>Add views in <code>resources/views/</code>.</li>
        <li>Create tables with a migration, then <code>php vigen migrate</code>.</li>
    </ul>

    <?php if (! empty($routes)): ?>
        <h2>Registered routes</h2>
        <ul>
            <?php foreach ($routes as $route): ?>
                <li><code><?= e($route) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
