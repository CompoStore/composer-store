<?php

namespace CStore\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use CStore\Installer\CStoreInstaller;

class CStorePlugin implements PluginInterface
{
    private ?CStoreInstaller $installer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write('<info>cstore:</info> Global store plugin active');

        $this->installer = new CStoreInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        if ($this->installer !== null) {
            $composer->getInstallationManager()->removeInstaller($this->installer);
            $this->installer = null;
        }
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Store packages remain in ~/.composer-store for other projects.
        // Users can run `cstore prune` to clean up.
    }
}
