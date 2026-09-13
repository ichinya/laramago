<?php

declare(strict_types=1);

namespace Ichinya\Laramago;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'configure',
            ScriptEvents::POST_UPDATE_CMD => 'configure',
        ];
    }

    public function configure(Event $event): void
    {
        $root = getcwd();
        if ($root === false || $event->getComposer()->getPackage()->getName() === 'ichinya/laramago') {
            return;
        }

        $message = new ConfigInstaller()->install(
            $root,
            dirname(__DIR__).'/presets/laravel.toml',
            (string) $event->getComposer()->getConfig()->get('vendor-dir'),
        );
        $event->getIO()->writeError('<info>ichinya/laramago:</info> '.$message);
    }
}
