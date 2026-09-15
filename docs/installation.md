# Installation

```bash
composer require vigenphp/vigen
```

Composer will prompt to trust Vigen's Composer plugin:

```
Do you trust "vigenphp/vigen" to execute code and control other plugins?
```

This plugin does exactly one thing: it publishes a `vigen` file into your project root (like Laravel's `artisan`), so you can run:

```bash
php vigen init
php vigen chat "..."
php vigen serve
```

To skip the prompt in CI or non-interactive installs, pre-approve it:

```json
{
    "config": {
        "allow-plugins": {
            "vigenphp/vigen": true
        }
    }
}
```

If the plugin doesn't run (declined, or `composer install --no-plugins`), the standard Composer binary still works:

```bash
vendor/bin/vigen init
```

The root `vigen` file is only published once - it's never overwritten on `composer update`, so it's safe to customize.
