<?php

namespace CompoStore\Parser;

use RuntimeException;

class LockFileParser
{
    private array $data;

    public function __construct(private string $lockFilePath)
    {
        if (!file_exists($lockFilePath)) {
            throw new RuntimeException("composer.lock not found at: {$lockFilePath}");
        }

        $content = file_get_contents($lockFilePath);
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid composer.lock JSON: " . json_last_error_msg());
        }

        $this->data = $decoded;
    }

    /**
     * Get all packages (runtime + dev)
     */
    public function getPackages(bool $includeDev = true): array
    {
        $packages = $this->data['packages'] ?? [];

        if ($includeDev) {
            $packages = array_merge($packages, $this->data['packages-dev'] ?? []);
        }

        return $packages;
    }

    /**
     * Get a normalized package key for store lookup
     * e.g. "laravel/framework@11.0.0"
     */
    public static function packageKey(array $package): string
    {
        $name = $package['name'];
        $version = $package['version'];
        return "{$name}@{$version}";
    }

    /**
     * Get download URL for a package
     */
    public static function getDownloadUrl(array $package): ?string
    {
        return $package['dist']['url'] ?? null;
    }

    /**
     * Get dist type (zip, tar, etc.)
     */
    public static function getDistType(array $package): string
    {
        return $package['dist']['type'] ?? 'zip';
    }

    /**
     * Get dist reference (sha hash)
     */
    public static function getDistReference(array $package): ?string
    {
        return $package['dist']['reference'] ?? null;
    }
}
