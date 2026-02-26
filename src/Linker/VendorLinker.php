<?php

namespace CStore\Linker;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class VendorLinker
{
    public function __construct(
        private string $vendorPath,
        private OutputInterface $output
    ) {}

    /**
     * Link a package from store into vendor/
     * Strategy: hard link individual files (most compatible)
     * Fallback: copy if hard link fails (e.g. cross-filesystem)
     */
    /**
     * @param bool $forceCopy When true, always copy files instead of hard linking.
     *                        Used for packages with post-install scripts that may
     *                        modify their own files (avoids cross-project corruption).
     */
    public function link(string $storePkgPath, string $vendorName, string $packageName, bool $forceCopy = false): void
    {
        $targetDir = $this->vendorPath . '/' . $vendorName . '/' . $packageName;

        // If already linked/exists, skip
        if (is_dir($targetDir) && $this->isLinkedFromStore($targetDir, $storePkgPath)) {
            return;
        }

        // Clean up if exists but stale
        if (is_dir($targetDir)) {
            $this->removeDir($targetDir);
        }

        mkdir($targetDir, 0755, true);

        // Write a marker so we know this is managed by cstore
        file_put_contents($targetDir . '/.cstore-link', $storePkgPath);

        $this->linkDirectory($storePkgPath, $targetDir, $forceCopy);
    }

    /**
     * Recursively hard link (or copy) files from store to vendor
     */
    private function linkDirectory(string $source, string $target, bool $forceCopy = false): void
    {
        $items = scandir($source);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.cstore-complete') {
                continue;
            }

            $srcPath = $source . '/' . $item;
            $dstPath = $target . '/' . $item;

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    mkdir($dstPath, 0755, true);
                }
                $this->linkDirectory($srcPath, $dstPath, $forceCopy);
            } else {
                $this->linkFile($srcPath, $dstPath, $forceCopy);
            }
        }
    }

    /**
     * Hard link a single file, fallback to copy if cross-filesystem
     */
    private function linkFile(string $source, string $target, bool $forceCopy = false): void
    {
        if (file_exists($target)) {
            return;
        }

        // Force copy for packages with scripts (avoids cross-project corruption)
        if (!$forceCopy) {
            // Try hard link first
            if (@link($source, $target)) {
                return;
            }
        }

        // Copy (forced or cross-filesystem fallback)
        if (!copy($source, $target)) {
            throw new RuntimeException("Failed to link or copy: {$source} → {$target}");
        }
    }

    /**
     * Check if vendor package was linked from expected store path
     */
    private function isLinkedFromStore(string $vendorPkgDir, string $storePkgPath): bool
    {
        $markerFile = $vendorPkgDir . '/.cstore-link';
        if (!file_exists($markerFile)) {
            return false;
        }
        return trim(file_get_contents($markerFile)) === $storePkgPath;
    }

    /**
     * Ensure vendor/composer/ exists for per-project autoloader
     * This directory is NEVER managed by cstore
     */
    public function ensureComposerDir(): void
    {
        $composerDir = $this->vendorPath . '/composer';
        if (!is_dir($composerDir)) {
            mkdir($composerDir, 0755, true);
        }
    }

    public function getVendorPath(): string
    {
        return $this->vendorPath;
    }

    /**
     * Install package binaries into vendor/bin for CLI-mode installs.
     */
    public function installPackageBinaries(string $vendorName, string $packageName): void
    {
        $packageDir = $this->vendorPath . '/' . $vendorName . '/' . $packageName;
        $composerJson = $packageDir . '/composer.json';
        if (!file_exists($composerJson)) {
            return;
        }

        $composerData = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($composerData)) {
            return;
        }

        $bins = $composerData['bin'] ?? [];
        if (is_string($bins)) {
            $bins = [$bins];
        }
        if (!is_array($bins) || empty($bins)) {
            return;
        }

        $binDir = $this->vendorPath . '/bin';
        if (!is_dir($binDir) && !mkdir($binDir, 0755, true) && !is_dir($binDir)) {
            throw new RuntimeException("Failed to create vendor bin directory: {$binDir}");
        }

        foreach ($bins as $binRelativePath) {
            if (!is_string($binRelativePath) || $binRelativePath === '') {
                continue;
            }

            $sourcePath = $packageDir . '/' . ltrim($binRelativePath, '/');
            if (!is_file($sourcePath)) {
                $this->output->writeln(
                    "  <fg=yellow>!</> Missing binary {$binRelativePath} in {$vendorName}/{$packageName}",
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            $targetPath = $binDir . '/' . basename($binRelativePath);

            // Avoid clobbering binaries from other packages.
            if (file_exists($targetPath) || is_link($targetPath)) {
                $existingRealPath = realpath($targetPath);
                $sourceRealPath = realpath($sourcePath);
                if ($existingRealPath !== false && $sourceRealPath !== false && $existingRealPath === $sourceRealPath) {
                    continue;
                }

                $this->output->writeln(
                    "  <fg=yellow>!</> Binary " . basename($binRelativePath) . ' already exists, skipping',
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            $this->linkFile($sourcePath, $targetPath);
            @chmod($targetPath, 0755);
        }
    }

    private function removeDir(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
