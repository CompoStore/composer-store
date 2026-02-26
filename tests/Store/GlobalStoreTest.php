<?php

namespace CompoStore\Tests\Store;

use CompoStore\Store\GlobalStore;
use PHPUnit\Framework\TestCase;

class GlobalStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cstore_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
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

    public function test_creates_store_directories(): void
    {
        $store = new GlobalStore($this->tmpDir);

        $this->assertDirectoryExists($this->tmpDir . '/packages');
        $this->assertDirectoryExists($this->tmpDir . '/metadata');
    }

    public function test_get_store_path(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $this->assertSame($this->tmpDir, $store->getStorePath());
    }

    public function test_get_package_path_format(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $path = $store->getPackagePath('laravel', 'framework', '11.0.0');
        $this->assertSame($this->tmpDir . '/packages/laravel+framework@11.0.0', $path);
    }

    public function test_get_package_path_encodes_branch_versions(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $path = $store->getPackagePath('vendor', 'pkg', 'dev-feature/foo');
        $this->assertSame($this->tmpDir . '/packages/vendor+pkg@dev-feature%2Ffoo', $path);
    }

    public function test_has_package_returns_false_for_missing(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $this->assertFalse($store->hasPackage('psr', 'log', '3.0.0'));
    }

    public function test_has_package_returns_false_for_incomplete(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $pkgPath = $store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        // No .cstore-complete marker
        $this->assertFalse($store->hasPackage('psr', 'log', '3.0.0'));
    }

    public function test_mark_complete_and_has_package(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $pkgPath = $store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);

        $store->markComplete($pkgPath);
        $this->assertTrue($store->hasPackage('psr', 'log', '3.0.0'));
    }

    public function test_list_packages(): void
    {
        $store = new GlobalStore($this->tmpDir);

        // Create 3 packages, mark 2 complete
        foreach (['psr+log@3.0.0', 'psr+container@2.0.0', 'psr+clock@1.0.0'] as $key) {
            $path = $this->tmpDir . '/packages/' . $key;
            mkdir($path, 0755, true);
        }
        $store->markComplete($this->tmpDir . '/packages/psr+log@3.0.0');
        $store->markComplete($this->tmpDir . '/packages/psr+container@2.0.0');
        // psr+clock not marked complete

        $packages = $store->listPackages();
        $this->assertCount(2, $packages);
        $this->assertContains('psr+log@3.0.0', $packages);
        $this->assertContains('psr+container@2.0.0', $packages);
        $this->assertNotContains('psr+clock@1.0.0', $packages);
    }

    public function test_remove_package(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $pkgPath = $store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        file_put_contents($pkgPath . '/test.txt', 'hello');
        $store->markComplete($pkgPath);

        $this->assertTrue($store->hasPackage('psr', 'log', '3.0.0'));

        $result = $store->removePackage('psr+log@3.0.0');
        $this->assertTrue($result);
        $this->assertFalse($store->hasPackage('psr', 'log', '3.0.0'));
    }

    public function test_remove_nonexistent_package_returns_false(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $this->assertFalse($store->removePackage('nonexistent+pkg@1.0.0'));
    }

    public function test_get_stats(): void
    {
        $store = new GlobalStore($this->tmpDir);

        // Create a package with a file
        $pkgPath = $store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        file_put_contents($pkgPath . '/test.txt', str_repeat('x', 100));
        $store->markComplete($pkgPath);

        $stats = $store->getStats();
        $this->assertSame(1, $stats['packages']);
        $this->assertGreaterThan(0, $stats['size']);
        $this->assertSame($this->tmpDir, $stats['path']);
    }

    public function test_get_stats_empty_store(): void
    {
        $store = new GlobalStore($this->tmpDir);
        $stats = $store->getStats();
        $this->assertSame(0, $stats['packages']);
        $this->assertSame(0, $stats['size']);
    }
}
