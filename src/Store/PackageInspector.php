<?php

namespace CStore\Store;

class PackageInspector
{
    /**
     * Check if a stored package has scripts that may modify its own files.
     * Packages with scripts should be copied (not hard-linked) to avoid
     * cross-project corruption through shared inodes.
     */
    public static function hasScripts(string $storePkgPath): bool
    {
        $composerJson = $storePkgPath . '/composer.json';
        if (!file_exists($composerJson)) {
            return false;
        }

        $data = json_decode(file_get_contents($composerJson), true);
        if (!is_array($data) || empty($data['scripts'])) {
            return false;
        }

        return true;
    }
}
