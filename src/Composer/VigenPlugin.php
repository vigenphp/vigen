<?php

declare(strict_types=1);

namespace Vigen\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * On `composer require vigenphp/vigen` (and subsequent `composer update`),
 * this plugin publishes a `vigen` executable into the project root -
 * mirroring how Laravel's `artisan` sits at the project root, so the
 * developer runs `php vigen ...` instead of `vendor/bin/vigen ...`.
 *
 * It never overwrites a `vigen` file that already exists, so local
 * customizations are safe across updates.
 */
class VigenPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // No-op: all work happens in the subscribed script events below.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'publishRootScript',
            ScriptEvents::POST_UPDATE_CMD => 'publishRootScript',
        ];
    }

    public function publishRootScript(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();

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
