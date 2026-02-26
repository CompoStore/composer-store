<?php

namespace CompoStore\Tests\Parser;

use CompoStore\Parser\LockFileParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LockFileParserTest extends TestCase
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

    private function writeLock(array $data): string
    {
        $path = $this->tmpDir . '/composer.lock';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    public function test_parses_packages(): void
    {
        $path = $this->writeLock([
            'packages' => [
                ['name' => 'psr/log', 'version' => '3.0.2', 'dist' => ['url' => 'https://example.com/psr-log.zip', 'type' => 'zip']],
                ['name' => 'psr/container', 'version' => '2.0.2', 'dist' => ['url' => 'https://example.com/psr-container.zip', 'type' => 'zip']],
            ],
            'packages-dev' => [
                ['name' => 'psr/clock', 'version' => '1.0.0', 'dist' => ['url' => 'https://example.com/psr-clock.zip', 'type' => 'zip']],
            ],
        ]);

        $parser = new LockFileParser($path);
        $packages = $parser->getPackages(true);
        $this->assertCount(3, $packages);
    }

    public function test_excludes_dev_when_no_dev(): void
    {
        $path = $this->writeLock([
            'packages' => [
                ['name' => 'psr/log', 'version' => '3.0.2'],
            ],
            'packages-dev' => [
                ['name' => 'psr/clock', 'version' => '1.0.0'],
            ],
        ]);

        $parser = new LockFileParser($path);
        $packages = $parser->getPackages(false);
        $this->assertCount(1, $packages);
        $this->assertSame('psr/log', $packages[0]['name']);
    }

    public function test_package_key_format(): void
    {
        $key = LockFileParser::packageKey(['name' => 'laravel/framework', 'version' => '11.0.0']);
        $this->assertSame('laravel/framework@11.0.0', $key);
    }

    public function test_get_download_url(): void
    {
        $url = LockFileParser::getDownloadUrl(['dist' => ['url' => 'https://example.com/pkg.zip']]);
        $this->assertSame('https://example.com/pkg.zip', $url);
    }

    public function test_get_download_url_returns_null_when_missing(): void
    {
        $url = LockFileParser::getDownloadUrl([]);
        $this->assertNull($url);
    }

    public function test_get_dist_reference(): void
    {
        $ref = LockFileParser::getDistReference(['dist' => ['reference' => 'abc123def456']]);
        $this->assertSame('abc123def456', $ref);
    }

    public function test_get_dist_type(): void
    {
        $type = LockFileParser::getDistType(['dist' => ['type' => 'tar']]);
        $this->assertSame('tar', $type);
    }

    public function test_get_dist_type_defaults_to_zip(): void
    {
        $type = LockFileParser::getDistType([]);
        $this->assertSame('zip', $type);
    }

    public function test_throws_on_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        new LockFileParser('/nonexistent/composer.lock');
    }

    public function test_throws_on_invalid_json(): void
    {
        $path = $this->tmpDir . '/composer.lock';
        file_put_contents($path, 'not valid json {{{');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid composer.lock JSON');
        new LockFileParser($path);
    }

    public function test_handles_empty_packages(): void
    {
        $path = $this->writeLock([]);
        $parser = new LockFileParser($path);
        $this->assertSame([], $parser->getPackages());
    }
}
