<?php

namespace CStore\Commands;

use CStore\Store\GlobalStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'status', description: 'Show global store statistics and space usage')]
class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Custom store path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storePath = $input->getOption('store') ?: null;

        $io->title('🗄  cstore status');

        $store = new GlobalStore($storePath);
        $stats = $store->getStats();
        $packages = $store->listPackages();

        $io->section('Store Info');
        $io->definitionList(
            ['Location' => $stats['path']],
            ['Total packages' => $stats['packages']],
            ['Total size' => $this->formatBytes($stats['size'])],
        );

        if (!empty($packages)) {
            $io->section('Stored Packages');
            foreach ($packages as $pkg) {
                $io->writeln("  <fg=green>✓</> {$pkg}");
            }
        } else {
            $io->writeln('<fg=gray>No packages in store yet. Run `cstore install` in a project.</>');
        }

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
