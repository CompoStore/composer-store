<?php

namespace CompoStore\Linker;

use RuntimeException;

/**
 * Generates the vendor/composer/ autoloader files per-project.
 *
 * We shell out to `composer dump-autoload` because reimplementing
 * Composer's autoload generation is out of scope for Phase 1.
 * Phase 2 will implement this natively.
 */
class AutoloaderGenerator
{
    public function __construct(private string $projectPath) {}

    /**
     * Run composer dump-autoload to regenerate autoloader
     * This is safe because vendor/composer/ is always per-project
     */
    public function generate(): bool
    {
        $cmd = sprintf(
            'cd %s && composer dump-autoload --no-interaction 2>&1',
            escapeshellarg($this->projectPath)
        );

        exec($cmd, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Check if composer is available
     */
    public static function isComposerAvailable(): bool
    {
        exec('composer --version 2>&1', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get vendor/autoload.php path
     */
    public function getAutoloadPath(): string
    {
        return $this->projectPath . '/vendor/autoload.php';
    }

    /**
     * Check if autoload.php already exists
     */
    public function exists(): bool
    {
        return file_exists($this->getAutoloadPath());
    }
}
