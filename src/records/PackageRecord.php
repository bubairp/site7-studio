<?php

namespace site7\studio\records;

use craft\db\ActiveRecord;

/**
 * PackageRecord
 */
class PackageRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%site7_packages}}';
    }

    private $_manifest = null;

    /**
     * Hydrates and returns the package manifest from the local directory.
     */
    public function getManifest(): ?\site7\studio\models\packages\PackageManifest
    {
        if ($this->_manifest === null) {
            $path = \site7\studio\Site7Studio::getInstance()->packageManager->getPackagePath($this->handle);
            if ($path) {
                try {
                    $reader = new \site7\studio\services\engine\PackageReader();
                    $package = $reader->readPackage($path);
                    $this->_manifest = $package->manifest;
                } catch (\Throwable $e) {
                    \Craft::warning("Could not read manifest for record {$this->handle}: " . $e->getMessage(), 'site7-studio');
                }
            }
        }
        return $this->_manifest;
    }
}
