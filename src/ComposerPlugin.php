<?php

namespace Yceruto\SelfUpdatePlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Yceruto\SelfUpdatePlugin\Command\SelfUpdateCommand;

class ComposerPlugin implements PluginInterface, EventSubscriberInterface, Capable, CommandProvider
{
    private Composer $composer;
    private IOInterface $io;

    public static function getSubscribedEvents(): array
    {
        return [];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement deactivate() method.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement uninstall() method.
    }

    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => self::class,
        ];
    }

    public function getCommands(): array
    {
        return [
            new SelfUpdateCommand(),
        ];
    }
}
