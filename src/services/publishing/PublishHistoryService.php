<?php

namespace site7\studio\services\publishing;

use craft\base\Component;
use site7\studio\records\PackagePublicationRecord;
use site7\studio\Site7Studio;

/**
 * CRUD over site7_package_publications - see that migration's docblock for
 * why publish history is its own table rather than reusing
 * site7_package_versions (one version can be published to more than one
 * repository target).
 */
class PublishHistoryService extends Component
{
    /**
     * Records a publish attempt (success or failure) for $handle.
     */
    public function recordPublish(string $handle, string $repositoryHandle, string $version, string $status, ?string $releaseNotes = null): PackagePublicationRecord
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        $publication = new PackagePublicationRecord();
        $publication->packageId = $record->id;
        $publication->repositoryHandle = $repositoryHandle;
        $publication->version = $version;
        $publication->status = $status;
        $publication->publishedAt = (new \DateTime())->format('Y-m-d H:i:s');
        $publication->releaseNotes = $releaseNotes;
        $publication->downloadCount = 0;
        $publication->save();

        return $publication;
    }

    /**
     * @return PackagePublicationRecord[] newest first
     */
    public function getHistory(string $handle): array
    {
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$record) {
            return [];
        }

        return PackagePublicationRecord::find()
            ->where(['packageId' => $record->id])
            ->orderBy(['publishedAt' => SORT_DESC])
            ->all();
    }

    /**
     * @return PackagePublicationRecord[] newest first, across every package - the Publishing landing page's own history view.
     */
    public function getAllHistory(int $limit = 100): array
    {
        return PackagePublicationRecord::find()
            ->orderBy(['publishedAt' => SORT_DESC])
            ->limit($limit)
            ->all();
    }
}
