<?php

namespace site7\studio\services\engine;

use craft\base\Component;
use site7\studio\models\packages\Package;
use site7\studio\models\packages\PackageManifest;
use site7\studio\models\packages\SectionPackage;
use site7\studio\models\packages\TemplatePackage;
use site7\studio\models\packages\PatternPackage;
use site7\studio\models\packages\StarterKitPackage;
use site7\studio\models\packages\ThemePackage;
use Exception;

/**
 * PackageReader is responsible for reading a package from a directory or .s7pkg file
 * and returning a hydrated Package instance.
 */
class PackageReader extends Component
{
    /**
     * Reads a package from the given path.
     *
     * @param string $path
     * @return Package
     * @throws Exception
     */
    public function readPackage(string $path): Package
    {
        $manifestPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';

        if (!file_exists($manifestPath)) {
            throw new Exception("Package manifest not found at: {$manifestPath}");
        }

        $json = file_get_contents($manifestPath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in manifest at: {$manifestPath}");
        }

        $manifest = new PackageManifest($data);

        if (!$manifest->validate()) {
            $errors = implode(', ', $manifest->getFirstErrors());
            throw new Exception("Invalid manifest data: {$errors}");
        }

        $package = $this->createPackageInstance($manifest->type);
        $package->manifest = $manifest;
        $package->path = $path;

        return $package;
    }

    /**
     * Creates the correct Package subclass based on type.
     *
     * @param string $type
     * @return Package
     * @throws Exception
     */
    protected function createPackageInstance(string $type): Package
    {
        switch (strtolower($type)) {
            case 'section':
                return new SectionPackage();
            case 'template':
                return new TemplatePackage();
            case 'pattern':
                return new PatternPackage();
            case 'starter-kit':
                return new StarterKitPackage();
            case 'theme':
                return new ThemePackage();
            default:
                throw new Exception("Unknown package type: {$type}");
        }
    }
}
