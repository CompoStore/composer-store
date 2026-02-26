<?php

namespace CStore;

use CompoStore\Commands\InstallCommand;
use CompoStore\Commands\StatusCommand;
use CompoStore\Commands\PruneCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application
{
    private ConsoleApplication $console;

    public function __construct()
    {
        $this->console = new ConsoleApplication('cstore', '0.1.0');
        $this->console->addCommands([
            new InstallCommand(),
            new StatusCommand(),
            new PruneCommand(),
        ]);
        $this->console->setDefaultCommand('install');
    }

    public function run(): int
    {
        return $this->console->run();
    }
}
