<?php

namespace site7\studio\repositories\marketplace;

use Craft;
use craft\helpers\FileHelper;
use site7\studio\interfaces\PackagePublishTargetInterface;
use site7\studio\models\marketplace\PackageBundleManifest;

/**
 * Publishes into the exact same folder LocalMarketplaceRepository already
 * reads from (storage/site7-studio/marketplace-repo/), making "Local
 * Repository" a full publish->install round trip: publish a package here,
 * then it immediately shows up on the Marketplace's own Repository/Import
 * tabs with zero additional wiring.
 */
class LocalPublishTarget implements PackagePublishTargetInterface
{
    /**
     * @inheritdoc
     */
    public function getHandle(): string
    {
        return 'local';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Local Repository';
    }

    /**
     * @inheritdoc
     */
    public function supportsPublish(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function publishPackage(string $s7pkgPath, PackageBundleManifest $bundle, array $metadata = []): string
    {
        $destinationDir = $this->getRepositoryPath();
        $destination = $destinationDir . '/' . basename($s7pkgPath);

        if (!copy($s7pkgPath, $destination)) {
            throw new \Exception("Could not copy the built package into the Local Repository at {$destination}.");
        }

        return $destination;
    }

    private function getRepositoryPath(): string
    {
        $path = Craft::getAlias('@storage') . '/site7-studio/marketplace-repo';
        FileHelper::createDirectory($path);
        return $path;
    }
}
