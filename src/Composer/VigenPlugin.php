<?php

declare(strict_types=1);

namespace Vigen\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * On `composer require vigenphp/vigen` (and every subsequent Composer run
 * in that project), this plugin publishes a `vigen` executable into the
 * project root - mirroring how Laravel's `artisan` sits at the project
 * root, so the developer runs `php vigen ...` instead of a `vendor/bin`
 * proxy (this package deliberately registers no "bin" of its own, to
 * avoid colliding with vigenphp/installer's global `vigen` command).
 *
 * It never overwrites a `vigen` file that already exists, so local
 * customizations are safe across updates.
 *
 * The publish happens directly in activate() rather than via a subscribed
 * script event (post-install-cmd/post-update-cmd). Composer has a known
 * "chicken and egg" limitation: a plugin installed for the first time in
 * the same command that triggers post-install-cmd/post-update-cmd does
 * not receive that event, because its listeners aren't registered until
 * after the event has already been dispatched - so the very first
 * `composer require vigenphp/vigen` would silently fail to publish
 * anything, and the developer's next command (`php vigen init`) would
 * find no `./vigen` to run. activate() has no such gap: Composer calls it
 * as soon as the plugin's own package is installed, on every command,
 * including the first.
 */
class VigenPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->publishRootScript($composer, $io);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    private function publishRootScript(Composer $composer, IOInterface $io): void
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectRoot = dirname($vendorDir);

        // Don't publish into Vigen's own repo while developing Vigen itself.
        if (basename($projectRoot) === 'vigen' && is_file($projectRoot . '/src/Composer/VigenPlugin.php')) {
            return;
        }

        $target = $projectRoot . '/vigen';
        if (is_file($target)) {
            return;
        }

        $stub = __DIR__ . '/../../stubs/vigen';
        if (! is_file($stub)) {
            return;
        }

        copy($stub, $target);
        @chmod($target, 0755);

        $io->write('<info>Vigen:</info> created ./vigen - run <comment>php vigen init</comment> to get started.');
    }
}
