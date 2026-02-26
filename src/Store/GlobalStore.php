<?php

namespace CompoStore\Store;

use RuntimeException;

class GlobalStore
{
    private string $storePath;

    public function __construct(?string $storePath = null)
    {
        $this->storePath = $storePath ?? $this->defaultStorePath();
        $this->ensureDirectories();
    }

    public function getStorePath(): string
    {
        return $this->storePath;
    }

    /**
     * Get the path where a specific package version is stored
     * e.g. ~/.composer-store/packages/laravel+framework@11.0.0
     */
    public function getPackagePath(string $vendor, string $name, string $version): string
    {
        return $this->storePath . '/packages/' . $this->packageKey($vendor, $name, $version);
    }

    /**
     * Get lock file path for a package operation.
     */
    public function getPackageLockPath(string $vendor, string $name, string $version): string
    {
        $key = $this->packageKey($vendor, $name, $version);
        return $this->storePath . '/metadata/locks/' . sha1($key) . '.lock';
    }

    /**
     * Check if package already exists in store
     */
    public function hasPackage(string $vendor, string $name, string $version): bool
    {
        $path = $this->getPackagePath($vendor, $name, $version);
        return is_dir($path) && $this->isPackageComplete($path);
    }

    /**
     * Mark a package as fully downloaded (write a .cstore-complete marker)
     */
    public function markComplete(string $packagePath, ?string $hash = null): void
    {
        $marker = ['completed_at' => date('c')];
        if ($hash !== null) {
            $marker['sha1'] = $hash;
        }
        file_put_contents($packagePath . '/.cstore-complete', json_encode($marker));
    }

    /**
     * Get the stored SHA1 hash for a package (null if none or old format)
     */
    public function getStoredHash(string $packagePath): ?string
    {
        $markerFile = $packagePath . '/.cstore-complete';
        if (!file_exists($markerFile)) {
            return null;
        }
        $content = file_get_contents($markerFile);
        $data = json_decode($content, true);
        return $data['sha1'] ?? null;
    }

    /**
     * Check if package download was completed (not partial)
     */
    private function isPackageComplete(string $packagePath): bool
    {
        return file_exists($packagePath . '/.cstore-complete');
    }

    /**
     * Get store statistics
     */
    public function getStats(): array
    {
        $packagesDir = $this->storePath . '/packages';
        if (!is_dir($packagesDir)) {
            return ['packages' => 0, 'size' => 0];
        }

        $packages = $this->listPackages();

        $size = $this->dirSize($packagesDir);

        return [
            'packages' => count($packages),
            'size' => $size,
            'path' => $this->storePath,
        ];
    }

    /**
     * Get all stored package keys
     */
    public function listPackages(): array
    {
        $packagesDir = $this->storePath . '/packages';
        if (!is_dir($packagesDir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($packagesDir),
            fn($d) => $d !== '.' && $d !== '..' && is_dir($packagesDir . '/' . $d)
                && $this->isPackageComplete($packagesDir . '/' . $d)
        ));
    }

    /**
     * Remove a package from store
     */
    public function removePackage(string $packageKey): bool
    {
        $path = $this->storePath . '/packages/' . $packageKey;
        if (is_dir($path)) {
            $this->removeDir($path);
            return true;
        }
        return false;
    }

    private function defaultStorePath(): string
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? sys_get_temp_dir();
        return $home . '/.composer-store';
    }

    private function ensureDirectories(): void
    {
        $dirs = [
            $this->storePath,
            $this->storePath . '/packages',
            $this->storePath . '/metadata',
            $this->storePath . '/metadata/locks',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new RuntimeException("Cannot create store directory: {$dir}");
            }
        }
    }

    private function dirSize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
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

    private function packageKey(string $vendor, string $name, string $version): string
    {
        // Encode version to avoid accidental directory nesting (e.g. dev-feature/foo)
        $normalizedVersion = rawurlencode($version);
        return "{$vendor}+{$name}@{$normalizedVersion}";
    }
}
