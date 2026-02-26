<?php

namespace CompoStore\Tests\Store;

use CompoStore\Store\GlobalStore;
use CompoStore\Store\PackageDownloader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;

class PackageDownloaderTest extends TestCase
{
    private string $tmpDir;
    private GlobalStore $store;
    private PackageDownloader $downloader;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cstore_test_' . uniqid();
        $this->store = new GlobalStore($this->tmpDir);
        $this->downloader = new PackageDownloader($this->store, new NullOutput());
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

    private function createZip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertNotFalse($result);

        foreach ($entries as $entryPath => $content) {
            $zip->addFromString($entryPath, $content);
        }
        $zip->close();
    }

    private function createTarArchive(string $basePath, array $entries, bool $gzip = false): string
    {
        $tarPath = $basePath . '.tar';
        if (file_exists($tarPath)) {
            unlink($tarPath);
        }

        $tar = new \PharData($tarPath);
        foreach ($entries as $entryPath => $content) {
            $tar->addFromString($entryPath, $content);
        }

        if (!$gzip) {
            return $tarPath;
        }

        $tar->compress(\Phar::GZ);
        return $tarPath . '.gz';
    }

    public function test_ensure_returns_cached_path(): void
    {
        // Pre-populate the store with a fake cached package
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        file_put_contents($pkgPath . '/composer.json', '{}');
        $this->store->markComplete($pkgPath);

        $result = $this->downloader->ensurePackage('psr/log', '3.0.0', 'https://example.com/pkg.zip');
        $this->assertSame($pkgPath, $result);
    }

    public function test_ensure_throws_without_dist_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No dist URL');
        $this->downloader->ensurePackage('psr/log', '3.0.0', null);
    }

    public function test_ensure_array_interface(): void
    {
        // Pre-populate
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        $this->store->markComplete($pkgPath);

        $result = $this->downloader->ensure([
            'name' => 'psr/log',
            'version' => '3.0.0',
            'dist' => ['url' => 'https://example.com/pkg.zip', 'type' => 'zip'],
        ]);
        $this->assertSame($pkgPath, $result);
    }

    public function test_ensure_array_passes_dist_reference(): void
    {
        // Pre-populate so it returns from cache (no actual download)
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        $this->store->markComplete($pkgPath, 'abc123');

        $result = $this->downloader->ensure([
            'name' => 'psr/log',
            'version' => '3.0.0',
            'dist' => [
                'url' => 'https://example.com/pkg.zip',
                'type' => 'zip',
                'reference' => 'abc123',
            ],
        ]);
        $this->assertSame($pkgPath, $result);
    }

    public function test_mark_complete_stores_hash(): void
    {
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        $this->store->markComplete($pkgPath, 'abc123def456');

        $storedHash = $this->store->getStoredHash($pkgPath);
        $this->assertSame('abc123def456', $storedHash);
    }

    public function test_mark_complete_without_hash(): void
    {
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        $this->store->markComplete($pkgPath);

        $storedHash = $this->store->getStoredHash($pkgPath);
        $this->assertNull($storedHash);
    }

    public function test_old_format_marker_returns_null_hash(): void
    {
        // Simulate old-format marker (plain text, not JSON)
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        file_put_contents($pkgPath . '/.cstore-complete', '2025-01-01T00:00:00+00:00');

        $storedHash = $this->store->getStoredHash($pkgPath);
        $this->assertNull($storedHash);
    }

    public function test_ensure_rejects_zip_path_traversal(): void
    {
        $zipPath = $this->tmpDir . '/malicious.zip';
        $this->createZip($zipPath, [
            '../escape.txt' => 'owned',
            'pkg/composer.json' => '{}',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe zip entry path');

        try {
            $this->downloader->ensurePackage(
                'evil/pkg',
                '1.0.0',
                'file://' . $zipPath,
                'zip'
            );
        } finally {
            $pkgPath = $this->store->getPackagePath('evil', 'pkg', '1.0.0');
            $this->assertDirectoryDoesNotExist($pkgPath);
        }
    }

    public function test_ensure_refreshes_cached_package_when_hash_mismatch(): void
    {
        $pkgPath = $this->store->getPackagePath('psr', 'log', '3.0.0');
        mkdir($pkgPath, 0755, true);
        file_put_contents($pkgPath . '/stale.txt', 'old-cache');
        $this->store->markComplete($pkgPath, 'oldhash123');

        $zipPath = $this->tmpDir . '/package.zip';
        $this->createZip($zipPath, [
            'psr-log-123/composer.json' => '{"name":"psr/log"}',
            'psr-log-123/src/LoggerInterface.php' => '<?php interface LoggerInterface {}',
        ]);
        $expectedHash = sha1((string) file_get_contents($zipPath));

        $result = $this->downloader->ensurePackage(
            'psr/log',
            '3.0.0',
            'file://' . $zipPath,
            'zip',
            $expectedHash
        );

        $this->assertSame($pkgPath, $result);
        $this->assertFileDoesNotExist($pkgPath . '/stale.txt');
        $this->assertFileExists($pkgPath . '/composer.json');
        $this->assertSame($expectedHash, $this->store->getStoredHash($pkgPath));
    }

    public function test_ensure_path_package_copies_local_directory(): void
    {
        $sourceDir = $this->tmpDir . '/local-package';
        mkdir($sourceDir . '/src', 0755, true);
        file_put_contents($sourceDir . '/composer.json', '{"name":"vendor/local"}');
        file_put_contents($sourceDir . '/src/Foo.php', '<?php class Foo {}');

        $pkgPath = $this->downloader->ensurePackage(
            'vendor/local',
            '1.0.0',
            $sourceDir,
            'path',
            null,
            'ref-1'
        );

        $this->assertSame(
            '<?php class Foo {}',
            file_get_contents($pkgPath . '/src/Foo.php')
        );
        $this->assertSame('ref-1', $this->store->getStoredHash($pkgPath));
    }

    public function test_ensure_path_package_resolves_relative_path_from_project(): void
    {
        $projectDir = $this->tmpDir . '/project';
        $sourceDir = $projectDir . '/packages/local-pkg';
        mkdir($sourceDir . '/src', 0755, true);
        file_put_contents($sourceDir . '/composer.json', '{"name":"vendor/local"}');
        file_put_contents($sourceDir . '/src/Bar.php', '<?php class Bar {}');

        $downloader = new PackageDownloader($this->store, new NullOutput(), $projectDir);
        $pkgPath = $downloader->ensurePackage(
            'vendor/local',
            '1.1.0',
            './packages/local-pkg',
            'path',
            null,
            'ref-2'
        );

        $this->assertFileExists($pkgPath . '/src/Bar.php');
    }

    public function test_ensure_supports_tar_dist(): void
    {
        $archivePath = $this->createTarArchive($this->tmpDir . '/package', [
            'pkg-1/composer.json' => '{"name":"vendor/tar"}',
            'pkg-1/src/Foo.php' => '<?php class Foo {}',
        ]);

        $pkgPath = $this->downloader->ensurePackage(
            'vendor/tar',
            '1.0.0',
            'file://' . $archivePath,
            'tar'
        );

        $this->assertFileExists($pkgPath . '/composer.json');
        $this->assertFileExists($pkgPath . '/src/Foo.php');
    }

    public function test_ensure_supports_tgz_dist(): void
    {
        $archivePath = $this->createTarArchive($this->tmpDir . '/package-gz', [
            'pkg-2/composer.json' => '{"name":"vendor/tgz"}',
            'pkg-2/src/Bar.php' => '<?php class Bar {}',
        ], true);

        $pkgPath = $this->downloader->ensurePackage(
            'vendor/tgz',
            '1.0.0',
            'file://' . $archivePath,
            'tgz'
        );

        $this->assertFileExists($pkgPath . '/composer.json');
        $this->assertFileExists($pkgPath . '/src/Bar.php');
    }
}
