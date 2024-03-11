<?php

namespace Yceruto\SelfUpdatePlugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Yceruto\SelfUpdatePlugin\Command\SelfUpdateCommand;

class ComposerPlugin implements PluginInterface, Capable, CommandProvider
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
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
