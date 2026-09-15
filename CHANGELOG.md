# Changelog

All notable changes to Vigen will be documented in this file.

## [Unreleased]

### Added
- Companion package `vigenphp/installer` (separate repo/zip): global `composer global require vigenphp/installer` gives a `vigen create <name>` command, like `laravel/installer`'s `laravel new`. Reuses `Scaffolder`, `EnvWriter`, and `ProviderModels` from this package rather than duplicating the wizard logic.
- File-location conventions (`Vigen\Project\Conventions`, default: `app/Models`, `app/Http/Controllers`, `routes/web.php`, `database/migrations`, ...) are now generated into `config/app.php` on init and read by `AIEngine`, which tells the planner where to place generated files. Overridable per-project via `config('app.paths')`.
- `Vigen\Core\Config` - a small dot-notation loader for `config/*.php`.
- Composer plugin (`Vigen\Composer\VigenPlugin`) that auto-publishes a root-level `vigen` script on `composer require`/`update` - the Vigen equivalent of Laravel's `artisan`, so the primary CLI entry point is `php vigen ...` instead of `vendor/bin/vigen ...`. Never overwrites an existing `./vigen`.
- `vigen init` interactive setup wizard: choose CLI or GUI, default AI provider, and model; writes `.env` and scaffolds the project (`ProviderModels`, `Scaffolder`, `EnvWriter`).
- `vigen serve` command launching the built-in GUI chatbox via PHP's built-in server (`src/GUI/router.php`).
- Initial repository scaffold: `src/Core`, `src/AI`, `src/CLI`, `src/GUI`, `src/Providers`, `src/Project`.
- Provider abstraction (`AIProviderInterface`) with Ollama, OpenAI, Claude, and Gemini implementations.
- `ProviderFactory` for resolving the configured provider from `.env`.
- `AIEngine` implementing the context → plan → generate → validate → fix workflow.
- `ProjectContext` for collecting project state relevant to a task.
- `vigen chat "<prompt>"` CLI command.
- Minimal GUI chatbox sharing the same `AIEngine` via `GUI\Server`.
- Composer package skeleton (`vigenphp/vigen`).
