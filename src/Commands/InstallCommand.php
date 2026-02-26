<?php

namespace CStore\Commands;

use CStore\Linker\AutoloaderGenerator;
use CStore\Linker\VendorLinker;
use CStore\Parser\LockFileParser;
use CStore\Store\GlobalStore;
use CStore\Store\PackageDownloader;
use CStore\Store\PackageInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'install', description: 'Install packages using global store (reads composer.lock)')]
class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project path (default: current directory)', '.')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Skip dev dependencies')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Custom store path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectPath = realpath($input->getArgument('path'));
        $includeDev = !$input->getOption('no-dev');
        $storePath = $input->getOption('store') ?: null;

        $io->title('🗄  cstore install');

        // Validate project path
        if (!$projectPath || !is_dir($projectPath)) {
            $io->error("Invalid project path: {$projectPath}");
            return Command::FAILURE;
        }

        $lockFile = $projectPath . '/composer.lock';
        if (!file_exists($lockFile)) {
            $io->error("composer.lock not found. Run `composer install` first to generate it.");
            return Command::FAILURE;
        }

        // Parse lock file
        $io->section('📖 Reading composer.lock');
        try {
            $parser = new LockFileParser($lockFile);
            $packages = $parser->getPackages($includeDev);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln(sprintf('  Found <info>%d</info> packages', count($packages)));

        // Initialize store
        $store = new GlobalStore($storePath);
        $downloader = new PackageDownloader($store, $output, $projectPath);
        $vendorPath = $projectPath . '/vendor';
        $linker = new VendorLinker($vendorPath, $output);

        // Ensure vendor dir exists
        if (!is_dir($vendorPath)) {
            mkdir($vendorPath, 0755, true);
        }

        // Process each package
        $io->section('📦 Installing packages');
        $cached = 0;
        $downloaded = 0;
        $skipped = 0;
        $failed = [];

        foreach ($packages as $package) {
            $pkgName = $package['name'];
            [$vendor, $name] = explode('/', $pkgName);
            $version = $package['version'];
            $distType = $package['dist']['type'] ?? null;
            $distUrl = $package['dist']['url'] ?? null;

            if (!$distUrl) {
                $io->writeln("  <fg=yellow>!</> Skipping {$pkgName} (no dist URL)");
                $skipped++;
                continue;
            }
            if ($distType && !in_array($distType, ['zip', 'path', 'tar', 'tgz', 'tar.gz'], true)) {
                $io->writeln("  <fg=yellow>!</> Skipping {$pkgName} (dist type '{$distType}' not supported)");
                $skipped++;
                continue;
            }

            // Check if already in store (for counting)
            $wasCached = $store->hasPackage($vendor, $name, $version);

            try {
                // 1. Ensure package exists in global store
                $storePkgPath = $downloader->ensure($package);

                // 2. Link from store into project vendor/
                // Use copy instead of hard link for packages with scripts
                $hasScripts = PackageInspector::hasScripts($storePkgPath);
                $linker->link($storePkgPath, $vendor, $name, $hasScripts);
                $linker->installPackageBinaries($vendor, $name);

                $wasCached ? $cached++ : $downloaded++;

            } catch (\Exception $e) {
                $io->writeln("  <fg=red>✗</> {$pkgName}@{$version}: {$e->getMessage()}");
                $failed[] = $pkgName;
            }
        }

        // Regenerate autoloader
        $io->section('⚙️  Generating autoloader');
        $autoloader = new AutoloaderGenerator($projectPath);

        if (AutoloaderGenerator::isComposerAvailable()) {
            if ($autoloader->generate()) {
                $io->writeln('  <fg=green>✓</> Autoloader generated');
            } else {
                $io->warning('Autoloader generation failed. Run `composer dump-autoload` manually.');
            }
        } else {
            $io->warning('composer not found in PATH. Run `composer dump-autoload` to regenerate autoloader.');
        }

        // Summary
        $io->section('✅ Summary');
        $storeStats = $store->getStats();

        $io->definitionList(
            ['Packages installed' => count($packages) - count($failed) - $skipped],
            ['Downloaded' => $downloaded],
            ['From cache' => $cached],
            ['Skipped' => $skipped],
            ['Failed' => count($failed)],
            ['Store location' => $storeStats['path']],
            ['Store packages' => $storeStats['packages']],
            ['Store size' => $this->formatBytes($storeStats['size'])],
        );

        if (!empty($failed)) {
            $io->error('Failed packages: ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        $io->success('Done! vendor/ is ready.');
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
