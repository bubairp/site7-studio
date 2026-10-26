<?php

namespace site7\studio\services\library;

use site7\studio\records\PackageInstalledFileRecord;
use site7\studio\records\PackageRecord;
use site7\studio\Site7Studio;

/** FileBaselineStore over InstalledFileBaselineService (the one writer of site7_installed_files). */
class InstalledFileBaselineStore implements FileBaselineStore
{
    public function find(int $packageId, string $targetPath): ?array
    {
        return Site7Studio::getInstance()->installedFileBaseline->getBaseline($packageId, $targetPath);
    }

    public function record(int $packageId, string $resourceHandle, string $targetPath, string $version, string $checksum): void
    {
        Site7Studio::getInstance()->installedFileBaseline->record($packageId, $resourceHandle, $targetPath, $version, $checksum);
    }

    public function remove(int $packageId, string $targetPath): void
    {
        Site7Studio::getInstance()->installedFileBaseline->remove($packageId, $targetPath);
    }

    public function otherOwner(int $packageId, string $targetPath): ?string
    {
        $row = PackageInstalledFileRecord::find()
            ->where(['targetPath' => $targetPath])
            ->andWhere(['not', ['packageId' => $packageId]])
            ->one();

        return $row ? (PackageRecord::findOne($row->packageId)?->handle ?? "#{$row->packageId}") : null;
    }
}
