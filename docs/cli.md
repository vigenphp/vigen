# CLI Usage

Just like `php artisan` in Laravel, everything runs through the root `vigen` script Composer publishes:

```bash
php vigen init      # one-time setup: interface, provider, model
php vigen chat "Create an API endpoint for products"
php vigen serve     # only needed if you chose the GUI interface
```

`vendor/bin/vigen` works identically if you'd rather not use the root script.

`vigen chat` prints its progress through each workflow stage: analyzing, planning, modifying files, and validating.
