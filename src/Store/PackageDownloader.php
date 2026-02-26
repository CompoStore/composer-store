<?php

namespace CStore\Store;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class PackageDownloader
{
    public function __construct(
        private GlobalStore $store,
        private OutputInterface $output,
        private ?string $projectPath = null
    ) {}

    /**
     * Download a package to the global store if not already present.
     * Accepts scalar parameters (used by both CLI and Composer plugin).
     * Returns the store path for the package.
     */
    public function ensurePackage(
        string $name,
        string $version,
        ?string $distUrl,
        string $distType = 'zip',
        ?string $distShasum = null,
        ?string $distReference = null
    ): string {
        [$vendor, $pkgName] = explode('/', $name);
        $packagePath = $this->store->getPackagePath($vendor, $pkgName, $version);
        $lockHandle = $this->acquirePackageLock($vendor, $pkgName, $version);
        $cacheToken = $distShasum ?: $distReference;

        try {
            if ($this->store->hasPackage($vendor, $pkgName, $version)) {
                if ($this->shouldRefreshCachedPackage($packagePath, $cacheToken)) {
                    $this->removeDir($packagePath);
                } else {
                    $this->output->writeln(
                        "  <fg=green>✓</> {$name}@{$version} <fg=gray>(cached)</>",
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    return $packagePath;
                }
            }

            if (!$distUrl) {
                throw new RuntimeException(
                    "No dist URL for {$name}@{$version}. Path and VCS-only packages are not supported by cstore."
                );
            }

            if ($distType === 'path') {
                $this->output->writeln("  <fg=yellow>↺</> Syncing path package {$name}@{$version}...");
            } else {
                $this->output->writeln("  <fg=yellow>↓</> Downloading {$name}@{$version}...");
            }

            // Clean up any partial download
            if (is_dir($packagePath)) {
                $this->removeDir($packagePath);
            }
            if (!mkdir($packagePath, 0755, true) && !is_dir($packagePath)) {
                throw new RuntimeException("Failed to create package directory: {$packagePath}");
            }

            try {
                match ($distType) {
                    'zip' => $this->downloadAndExtract($distUrl, $distType, $packagePath, $distShasum),
                    'tar', 'tgz', 'tar.gz' => $this->downloadAndExtract($distUrl, $distType, $packagePath, null),
                    'path' => $this->syncPathPackage($distUrl, $packagePath),
                    default => throw new RuntimeException("Unsupported dist type: {$distType}"),
                };
                $this->store->markComplete($packagePath, $cacheToken);
            } catch (\Throwable $e) {
                // Don't leave partial package directories behind.
                $this->removeDir($packagePath);
                throw $e;
            }

            $this->output->writeln("  <fg=green>✓</> {$name}@{$version}");

            return $packagePath;
        } finally {
            $this->releasePackageLock($lockHandle);
        }
    }

    /**
     * Array-based interface for CLI commands (reads composer.lock format).
     * Delegates to ensurePackage().
     */
    public function ensure(array $package): string
    {
        return $this->ensurePackage(
            $package['name'],
            $package['version'],
            $package['dist']['url'] ?? null,
            $package['dist']['type'] ?? 'zip',
            $package['dist']['shasum'] ?? null,
            $package['dist']['reference'] ?? null
        );
    }

    private function syncPathPackage(string $distUrl, string $targetPath): void
    {
        $sourcePath = $this->resolvePathDistUrl($distUrl);
        $this->copyDirectory($sourcePath, $targetPath);
    }

    private function resolvePathDistUrl(string $distUrl): string
    {
        $candidatePath = $distUrl;
        $scheme = parse_url($distUrl, PHP_URL_SCHEME);

        if ($scheme === 'file') {
            $path = parse_url($distUrl, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                throw new RuntimeException("Invalid path dist URL: {$distUrl}");
            }
            $candidatePath = rawurldecode($path);
        } elseif ($scheme !== null) {
            throw new RuntimeException("Unsupported path dist URL scheme: {$distUrl}");
        }

        if (!$this->isAbsolutePath($candidatePath)) {
            $basePath = $this->projectPath ?? getcwd();
            if (!is_string($basePath) || $basePath === '') {
                throw new RuntimeException("Cannot resolve relative path package URL: {$distUrl}");
            }
            $candidatePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $candidatePath;
        }

        $resolvedPath = realpath($candidatePath);
        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new RuntimeException("Path package source not found: {$distUrl}");
        }

        return $resolvedPath;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function copyDirectory(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathname();
            $destination = $target . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                    throw new RuntimeException("Failed to create directory: {$destination}");
                }
                continue;
            }

            $destinationDir = dirname($destination);
            if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                throw new RuntimeException("Failed to create directory: {$destinationDir}");
            }

            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException("Failed to copy path package file: {$relativePath}");
            }
        }
    }

    private function downloadAndExtract(string $url, string $type, string $targetPath, ?string $expectedHash = null): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cstore_');
        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temp file for download');
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'cstore/0.1.0 (Composer Store)',
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $in = fopen($url, 'rb', false, $context);
        if ($in === false) {
            throw new RuntimeException("Failed to download: {$url}");
        }
        $out = fopen($tmpFile, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Failed to open temporary file for writing');
        }

        $hashCtx = hash_init('sha1');
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 64);
            if ($chunk === false) {
                fclose($in);
                fclose($out);
                throw new RuntimeException("Failed while downloading: {$url}");
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($hashCtx, $chunk);
            if (fwrite($out, $chunk) === false) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Failed writing downloaded package to temp file');
            }
        }
        fclose($in);
        fclose($out);

        // Verify integrity if shasum provided (from composer.lock dist.shasum)
        if ($expectedHash !== null && $expectedHash !== '') {
            $actualHash = hash_final($hashCtx);
            if ($actualHash !== $expectedHash) {
                throw new RuntimeException(
                    "Integrity check failed for {$url}: expected {$expectedHash}, got {$actualHash}"
                );
            }
        }

        try {
            match ($type) {
                'zip' => $this->extractZip($tmpFile, $targetPath),
                'tar', 'tgz', 'tar.gz' => $this->extractTar($tmpFile, $targetPath, $type),
                default => throw new RuntimeException("Unsupported dist type: {$type}"),
            };
        } finally {
            @unlink($tmpFile);
        }
    }

    private function extractZip(string $zipFile, string $targetPath): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipFile);

        if ($result !== true) {
            throw new RuntimeException("Failed to open zip file (error code: {$result})");
        }

        $entries = [];
        $topLevelDirs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }
            $normalized = $this->normalizeArchiveEntryPath($entryName);
            if ($normalized === '') {
                continue;
            }

            $trimmed = rtrim($normalized, '/');
            $firstSegment = explode('/', $trimmed, 2)[0];
            $topLevelDirs[$firstSegment] = true;

            $entries[] = [
                'original' => $entryName,
                'path' => $normalized,
                'is_dir' => str_ends_with($normalized, '/'),
            ];
        }

        $wrapperDir = count($topLevelDirs) === 1 ? array_key_first($topLevelDirs) : null;

        try {
            foreach ($entries as $entry) {
                $relativePath = $entry['path'];
                if ($wrapperDir !== null) {
                    if ($relativePath === $wrapperDir || $relativePath === $wrapperDir . '/') {
                        continue;
                    }
                    if (str_starts_with($relativePath, $wrapperDir . '/')) {
                        $relativePath = substr($relativePath, strlen($wrapperDir) + 1);
                    }
                }

                $relativePath = ltrim($relativePath, '/');
                if ($relativePath === '') {
                    continue;
                }

                $destination = $targetPath . '/' . $relativePath;

                if ($entry['is_dir']) {
                    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                        throw new RuntimeException("Failed to create directory: {$destination}");
                    }
                    continue;
                }

                $destinationDir = dirname($destination);
                if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                    throw new RuntimeException("Failed to create directory: {$destinationDir}");
                }

                $entryStream = $zip->getStream($entry['original']);
                if ($entryStream === false) {
                    throw new RuntimeException("Failed to read zip entry: {$entry['path']}");
                }

                $targetStream = fopen($destination, 'wb');
                if ($targetStream === false) {
                    fclose($entryStream);
                    throw new RuntimeException("Failed to write zip entry to: {$destination}");
                }

                if (stream_copy_to_stream($entryStream, $targetStream) === false) {
                    fclose($entryStream);
                    fclose($targetStream);
                    throw new RuntimeException("Failed to extract zip entry: {$entry['path']}");
                }

                fclose($entryStream);
                fclose($targetStream);
            }
        } finally {
            $zip->close();
        }
    }

    private function extractTar(string $archiveFile, string $targetPath, string $type): void
    {
        $archiveWithExt = $this->createTarArchiveWithExtension($archiveFile, $type);

        try {
            $archive = new \PharData($archiveWithExt);
            $entries = [];
            $topLevelDirs = [];

            $iterator = new \RecursiveIteratorIterator($archive, \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                $entryPath = $iterator->getSubPathName();
                if (!is_string($entryPath) || $entryPath === '') {
                    continue;
                }

                $normalized = $this->normalizeArchiveEntryPath($entryPath);
                if ($normalized === '') {
                    continue;
                }

                $trimmed = rtrim($normalized, '/');
                $firstSegment = explode('/', $trimmed, 2)[0];
                $topLevelDirs[$firstSegment] = true;

                $entries[] = [
                    'path' => $normalized,
                    'is_dir' => $entry->isDir(),
                    'source' => $entry->getPathname(),
                ];
            }

            $wrapperDir = count($topLevelDirs) === 1 ? array_key_first($topLevelDirs) : null;

            foreach ($entries as $entry) {
                $relativePath = $entry['path'];
                if ($wrapperDir !== null) {
                    if ($relativePath === $wrapperDir || $relativePath === $wrapperDir . '/') {
                        continue;
                    }
                    if (str_starts_with($relativePath, $wrapperDir . '/')) {
                        $relativePath = substr($relativePath, strlen($wrapperDir) + 1);
                    }
                }

                $relativePath = ltrim($relativePath, '/');
                if ($relativePath === '') {
                    continue;
                }

                $destination = $targetPath . '/' . $relativePath;
                if ($entry['is_dir']) {
                    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                        throw new RuntimeException("Failed to create directory: {$destination}");
                    }
                    continue;
                }

                $destinationDir = dirname($destination);
                if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                    throw new RuntimeException("Failed to create directory: {$destinationDir}");
                }

                $input = fopen($entry['source'], 'rb');
                if ($input === false) {
                    throw new RuntimeException("Failed to read tar entry: {$entry['path']}");
                }
                $output = fopen($destination, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException("Failed to write tar entry to: {$destination}");
                }

                if (stream_copy_to_stream($input, $output) === false) {
                    fclose($input);
                    fclose($output);
                    throw new RuntimeException("Failed to extract tar entry: {$entry['path']}");
                }

                fclose($input);
                fclose($output);
            }
        } finally {
            @unlink($archiveWithExt);
        }
    }

    private function createTarArchiveWithExtension(string $archiveFile, string $type): string
    {
        $ext = in_array($type, ['tgz', 'tar.gz'], true) ? '.tar.gz' : '.tar';
        $withExt = sys_get_temp_dir() . '/cstore_tar_' . uniqid('', true) . $ext;

        if (!copy($archiveFile, $withExt)) {
            throw new RuntimeException("Failed to prepare tar archive: {$archiveFile}");
        }

        return $withExt;
    }

    private function normalizeArchiveEntryPath(string $entryPath): string
    {
        if (str_contains($entryPath, "\0")) {
            throw new RuntimeException('Invalid zip entry path: contains null byte');
        }

        $entryPath = str_replace('\\', '/', $entryPath);
        if (
            str_starts_with($entryPath, '/')
            || preg_match('/^[A-Za-z]:\//', $entryPath) === 1
        ) {
            throw new RuntimeException("Invalid zip entry path: {$entryPath}");
        }

        $isDir = str_ends_with($entryPath, '/');
        $parts = explode('/', $entryPath);
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException("Unsafe zip entry path: {$entryPath}");
            }
            $safe[] = $part;
        }

        if (empty($safe)) {
            return '';
        }

        return implode('/', $safe) . ($isDir ? '/' : '');
    }

    /**
     * @return resource
     */
    private function acquirePackageLock(string $vendor, string $name, string $version)
    {
        $lockPath = $this->store->getPackageLockPath($vendor, $name, $version);
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new RuntimeException("Failed to create lock directory: {$lockDir}");
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Failed to open lock file: {$lockPath}");
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Failed to acquire package lock: {$lockPath}");
        }

        return $handle;
    }

    /**
     * @param resource $lockHandle
     */
    private function releasePackageLock($lockHandle): void
    {
        if (!is_resource($lockHandle)) {
            return;
        }
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    private function shouldRefreshCachedPackage(string $packagePath, ?string $expectedHash): bool
    {
        if ($expectedHash === null || $expectedHash === '') {
            return false;
        }

        $storedHash = $this->store->getStoredHash($packagePath);
        if ($storedHash === null || $storedHash === '') {
            return false;
        }

        if (!hash_equals($storedHash, $expectedHash)) {
            $this->output->writeln(
                "  <fg=yellow>!</> Cached package hash mismatch, refreshing",
                OutputInterface::VERBOSITY_VERBOSE
            );
            return true;
        }

        return false;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) return;
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
