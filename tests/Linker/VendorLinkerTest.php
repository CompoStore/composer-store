<?php

namespace CompoStore\Tests\Linker;

use CompoStore\Linker\VendorLinker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

class VendorLinkerTest extends TestCase
{
    private string $storeDir;
    private string $vendorDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cstore_test_' . uniqid();
        $this->storeDir = $base . '/store';
        $this->vendorDir = $base . '/vendor';
        mkdir($this->storeDir, 0755, true);
        mkdir($this->vendorDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname($this->storeDir));
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

    private function createStorePackage(): string
    {
        $pkgPath = $this->storeDir . '/psr+log@3.0.0';
        mkdir($pkgPath . '/src', 0755, true);
        file_put_contents($pkgPath . '/composer.json', '{"name":"psr/log"}');
        file_put_contents($pkgPath . '/src/LoggerInterface.php', '<?php interface LoggerInterface {}');
        file_put_contents($pkgPath . '/.cstore-complete', '{"completed_at":"2025-01-01"}');
        return $pkgPath;
    }

    private function createStorePackageWithBinary(): string
    {
        $pkgPath = $this->storeDir . '/vendor+tool@1.0.0';
        mkdir($pkgPath . '/bin', 0755, true);
        file_put_contents(
            $pkgPath . '/composer.json',
            '{"name":"vendor/tool","bin":["bin/tool"]}'
        );
        file_put_contents($pkgPath . '/bin/tool', "#!/usr/bin/env php\n<?php echo 'ok';\n");
        file_put_contents($pkgPath . '/.cstore-complete', '{"completed_at":"2025-01-01"}');
        return $pkgPath;
    }

    public function test_link_creates_hard_links(): void
    {
        $storePkg = $this->createStorePackage();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'psr', 'log');

        $vendorFile = $this->vendorDir . '/psr/log/src/LoggerInterface.php';
        $storeFile = $storePkg . '/src/LoggerInterface.php';

        $this->assertFileExists($vendorFile);

        // Same inode = hard link
        $this->assertSame(
            stat($storeFile)['ino'],
            stat($vendorFile)['ino'],
            'Files should be hard linked (same inode)'
        );
    }

    public function test_link_writes_cstore_link_marker(): void
    {
        $storePkg = $this->createStorePackage();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'psr', 'log');

        $markerFile = $this->vendorDir . '/psr/log/.cstore-link';
        $this->assertFileExists($markerFile);
        $this->assertSame($storePkg, trim(file_get_contents($markerFile)));
    }

    public function test_link_skips_already_linked(): void
    {
        $storePkg = $this->createStorePackage();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'psr', 'log');

        // Modify the vendor file to prove second link is a no-op
        $vendorFile = $this->vendorDir . '/psr/log/composer.json';
        $originalContent = file_get_contents($vendorFile);
        file_put_contents($vendorFile, 'modified');

        $linker->link($storePkg, 'psr', 'log');

        // If it was skipped, the modified content should remain
        $this->assertSame('modified', file_get_contents($vendorFile));
    }

    public function test_force_copy_creates_separate_files(): void
    {
        $storePkg = $this->createStorePackage();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'psr', 'log', true); // forceCopy = true

        $vendorFile = $this->vendorDir . '/psr/log/src/LoggerInterface.php';
        $storeFile = $storePkg . '/src/LoggerInterface.php';

        $this->assertFileExists($vendorFile);

        // Different inodes = separate copies
        $this->assertNotSame(
            stat($storeFile)['ino'],
            stat($vendorFile)['ino'],
            'Files should be copied (different inodes) when forceCopy is true'
        );

        // But same content
        $this->assertSame(
            file_get_contents($storeFile),
            file_get_contents($vendorFile)
        );
    }

    public function test_skips_cstore_complete_marker(): void
    {
        $storePkg = $this->createStorePackage();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'psr', 'log');

        // .cstore-complete should NOT be linked into vendor
        $this->assertFileDoesNotExist($this->vendorDir . '/psr/log/.cstore-complete');
    }

    public function test_ensure_composer_dir(): void
    {
        $linker = new VendorLinker($this->vendorDir, new NullOutput());
        $linker->ensureComposerDir();

        $this->assertDirectoryExists($this->vendorDir . '/composer');
    }

    public function test_get_vendor_path(): void
    {
        $linker = new VendorLinker($this->vendorDir, new NullOutput());
        $this->assertSame($this->vendorDir, $linker->getVendorPath());
    }

    public function test_install_package_binaries_creates_vendor_bin_link(): void
    {
        $storePkg = $this->createStorePackageWithBinary();
        $linker = new VendorLinker($this->vendorDir, new NullOutput());

        $linker->link($storePkg, 'vendor', 'tool');
        $linker->installPackageBinaries('vendor', 'tool');

        $binPath = $this->vendorDir . '/bin/tool';
        $sourcePath = $this->vendorDir . '/vendor/tool/bin/tool';

        $this->assertFileExists($binPath);
        $this->assertSame(
            file_get_contents($sourcePath),
            file_get_contents($binPath)
        );
    }
}
