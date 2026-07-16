<?php

namespace site7\studio\repositories;

use craft\base\Component;
use site7\studio\records\PackageRecord;
use site7\studio\models\packages\Package;

/**
 * PackageRepository handles database interactions for packages.
 */
class PackageRepository extends Component
{
    /**
     * Finds a package record by its handle.
     *
     * @param string $handle
     * @return PackageRecord|null
     */
    public function findByHandle(string $handle): ?PackageRecord
    {
        return PackageRecord::find()->where(['handle' => $handle])->one();
    }

    /**
     * Saves a package to the database.
     *
     * @param Package $package
     * @return bool
     */
    public function save(Package $package): bool
    {
        $record = $this->findByHandle($package->manifest->handle);

        if (!$record) {
            $record = new PackageRecord();
            $record->status = 'available';
        }

        $record->name = $package->manifest->name;
        $record->handle = $package->manifest->handle;
        $record->type = $package->getPackageType();
        $record->version = $package->manifest->version;
        $record->author = $package->manifest->author;
        $record->description = $package->manifest->description;
        
        // This is a simplified save logic. Complex relationships (Components, Templates)
        // would be handled by a more advanced PackageService in subsequent milestones.

        return $record->save();
    }

    /**
     * Retrieves all package records.
     *
     * @return PackageRecord[]
     */
    public function findAll(): array
    {
        return PackageRecord::find()->all();
    }
}
