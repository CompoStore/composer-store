<?php

namespace CompoStore\Tests\Store;

use CompoStore\Store\PackageInspector;
use PHPUnit\Framework\TestCase;

class PackageInspectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cstore_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    public function test_returns_true_when_scripts_present(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'name' => 'some/package',
            'scripts' => [
                'post-install-cmd' => ['echo hello'],
            ],
        ]));

        $this->assertTrue(PackageInspector::hasScripts($this->tmpDir));
    }

    public function test_returns_false_when_no_scripts(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'name' => 'psr/log',
        ]));

        $this->assertFalse(PackageInspector::hasScripts($this->tmpDir));
    }

    public function test_returns_false_when_empty_scripts(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'name' => 'psr/log',
            'scripts' => [],
        ]));

        $this->assertFalse(PackageInspector::hasScripts($this->tmpDir));
    }

    public function test_returns_false_when_no_composer_json(): void
    {
        $this->assertFalse(PackageInspector::hasScripts($this->tmpDir));
    }

    public function test_returns_false_for_invalid_json(): void
    {
        file_put_contents($this->tmpDir . '/composer.json', 'not valid json');
        $this->assertFalse(PackageInspector::hasScripts($this->tmpDir));
    }
}
