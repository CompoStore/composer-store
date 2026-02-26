<?php

namespace CompoStore\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use CompoStore\Store\GlobalStore;
use CompoStore\Store\PackageDownloader;
use CompoStore\Store\PackageInspector;
use CompoStore\Linker\VendorLinker;
use CompoStore\Plugin\IOOutputAdapter;

class CompoStoreInstaller extends LibraryInstaller
{
    private GlobalStore $store;
    private PackageDownloader $downloader;
    private IOOutputAdapter $outputAdapter;

    public function __construct(IOInterface $io, Composer $composer, ?GlobalStore $store = null)
    {
        parent::__construct($io, $composer, 'library');

        $this->store = $store ?? new GlobalStore();
        $this->outputAdapter = new IOOutputAdapter($io);
        $projectPath = dirname((string) $this->composer->getConfig()->get('vendor-dir'));
        if (!is_dir($projectPath)) {
            $projectPath = getcwd() ?: null;
        }
        $this->downloader = new PackageDownloader($this->store, $this->outputAdapter, $projectPath ?: null);
    }

    /**
     * Handle library packages only.
     * composer-plugin, metapackage, etc. use Composer's default installer.
     */
    public function supports(string $packageType): bool
    {
        return $packageType === 'library';
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $name = $package->getName();
        $version = $package->getPrettyVersion();
        $distUrl = $package->getDistUrl();
        $distType = $package->getDistType() ?? 'zip';
        $distReference = $package->getDistReference();
        [$vendor, $pkgName] = explode('/', $name);

        // Path repositories may provide URL in source instead of dist.
        if ($distType === 'path' && !$distUrl) {
            $distUrl = $package->getSourceUrl();
        }

        // Skip packages without dist URL (VCS-only or unsupported).
        if (!$distUrl) {
            $this->io->write("  <info>compostore:</info> {$name} has no dist URL, using Composer default", true, IOInterface::VERBOSITY_VERBOSE);
            return parent::install($repo, $package);
        }

        // Skip unsupported dist types
        if (!in_array($distType, ['zip', 'path', 'tar', 'tgz', 'tar.gz'], true)) {
            $this->io->write("  <info>compostore:</info> {$name} has dist type '{$distType}', using Composer default", true, IOInterface::VERBOSITY_VERBOSE);
            return parent::install($repo, $package);
        }

        // Ensure package is in the global store
        try {
            $this->downloader->ensurePackage(
                $name,
                $version,
                $distUrl,
                $distType,
                $package->getDistSha1Checksum(),
                $distReference
            );
        } catch (\Exception $e) {
            $this->io->writeError("  <warning>compostore: store failed for {$name}, using Composer default: {$e->getMessage()}</warning>");
            return parent::install($repo, $package);
        }

        // Hard link from store into vendor/
        $storePkgPath = $this->store->getPackagePath($vendor, $pkgName, $version);
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');

        $linker = new VendorLinker($vendorDir, $this->outputAdapter);
        $hasScripts = PackageInspector::hasScripts($storePkgPath);
        $linker->link($storePkgPath, $vendor, $pkgName, $hasScripts);

        if (!$repo->hasPackage($package)) {
            $repo->addPackage(clone $package);
        }

        $this->binaryInstaller->installBinaries($package, $this->getInstallPath($package));

        return \React\Promise\resolve(null);
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        // Remove old version from vendor, then install new
        $this->removePackageFiles($initial);

        if ($repo->hasPackage($initial)) {
            $repo->removePackage($initial);
        }

        return $this->install($repo, $target);
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->removePackageFiles($package);
        $this->binaryInstaller->removeBinaries($package);

        if ($repo->hasPackage($package)) {
            $repo->removePackage($package);
        }

        return \React\Promise\resolve(null);
    }

    private function removePackageFiles(PackageInterface $package): void
    {
        $installPath = $this->getInstallPath($package);
        $filesystem = new Filesystem();
        if (is_dir($installPath)) {
            $filesystem->removeDirectory($installPath);
        }
    }
}
