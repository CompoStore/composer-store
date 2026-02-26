<?php

namespace CompoStore\Commands;

use CompoStore\Store\GlobalStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'prune', description: 'Remove packages from store that are no longer referenced by any project')]
class PruneCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Custom store path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be removed without removing')
            ->addOption('scan', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Scan these directories for projects using the store', []);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storePath = $input->getOption('store') ?: null;
        $isDryRun = $input->getOption('dry-run');
        $scanDirs = $input->getOption('scan');

        $io->title('🗄  compostore prune');

        if ($isDryRun) {
            $io->note('Dry run mode — nothing will be deleted');
        }

        $store = new GlobalStore($storePath);
        $storedPackages = $store->listPackages();

        if (empty($storedPackages)) {
            $io->writeln('Store is empty, nothing to prune.');
            return Command::SUCCESS;
        }

        // Find all packages referenced by scanned projects
        $referencedPackages = [];
        if (!empty($scanDirs)) {
            $io->section('Scanning projects...');
            foreach ($scanDirs as $scanDir) {
                $refs = $this->scanProjectsInDir($scanDir, $io);
                $referencedPackages = array_merge($referencedPackages, $refs);
            }
            $referencedPackages = array_unique($referencedPackages);
            $io->writeln(sprintf('  Found <info>%d</info> referenced packages across scanned projects', count($referencedPackages)));
        }

        // Determine what to prune
        $toPrune = empty($scanDirs)
            ? $storedPackages  // No scan dirs = prune everything (like `pnpm store prune` with no active projects)
            : array_diff($storedPackages, $referencedPackages);

        if (empty($toPrune)) {
            $io->success('Nothing to prune — all stored packages are in use.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Packages to remove (%d)', count($toPrune)));
        foreach ($toPrune as $pkg) {
            $io->writeln("  <fg=red>✗</> {$pkg}");
        }

        if (!$isDryRun) {
            if (!$io->confirm(sprintf('Remove %d packages from store?', count($toPrune)), false)) {
                $io->writeln('Cancelled.');
                return Command::SUCCESS;
            }

            $removed = 0;
            foreach ($toPrune as $pkg) {
                if ($store->removePackage($pkg)) {
                    $removed++;
                }
            }
            $io->success("Removed {$removed} packages from store.");
        } else {
            $io->writeln(sprintf('<fg=gray>Would remove %d packages (dry run)</>',  count($toPrune)));
        }

        return Command::SUCCESS;
    }

    /**
     * Scan a directory for projects that have composer.lock files
     * and collect which store packages they reference
     */
    private function scanProjectsInDir(string $dir, SymfonyStyle $io): array
    {
        $referenced = [];

        if (!is_dir($dir)) {
            $io->warning("Scan directory not found: {$dir}");
            return $referenced;
        }

        // Find all .cstore-link markers in vendor/ subdirectories
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === '.cstore-link' && $file->isFile()) {
                $storePath = trim(file_get_contents($file->getPathname()));
                // Extract package key from store path
                $key = basename($storePath);
                $referenced[] = $key;
            }
        }

        return $referenced;
    }
}
