<?php

namespace site7\studio\services\publishing;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use site7\studio\events\publishing\AfterPublishEvent;
use site7\studio\events\publishing\BeforePublishEvent;
use site7\studio\events\publishing\PublishFailedEvent;
use site7\studio\events\publishing\RepositorySelectedEvent;
use site7\studio\interfaces\PackagePublisherInterface;
use site7\studio\models\marketplace\PackageBundleManifest;
use site7\studio\models\publishing\PublishResult;
use site7\studio\records\PackagePublicationRecord;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\Site7Studio;

/**
 * Orchestrates a full publish - see PackagePublisherInterface's docblock.
 * Composes (never duplicates) PublishValidatorService, PackageBuilderService,
 * RepositoryManagerService, VersionManagerService, PublishHistoryService,
 * and NullPackageSigner.
 */
class PackagePublisherService extends Component implements PackagePublisherInterface
{
    /**
     * @inheritdoc
     */
    public function publish(string $handle, array $options): PublishResult
    {
        $plugin = Site7Studio::getInstance();
        $dispatcher = $plugin->getService('eventDispatcher');

        $repositoryHandle = $options['repositoryHandle'] ?? null;
        if (!$repositoryHandle) {
            throw new \Exception('A repository must be selected before publishing.');
        }

        $target = $plugin->repositoryManager->getTarget($repositoryHandle);
        if (!$target || !$target->supportsPublish()) {
            return $this->fail($handle, "'{$repositoryHandle}' is not available to publish to right now.", $dispatcher);
        }

        // Apply any requested version bump before validating/building, so
        // both operate on the version that will actually ship.
        if (!empty($options['bumpType'])) {
            $plugin->versionManager->createVersion($handle, $options['bumpType'], $options['releaseNotes'] ?? null);
        }

        // Save any metadata edited on the wizard's Metadata step - reuses
        // PackageAuthoringService via VersionManagerService's own sibling
        // path (PackageAuthoringService::updatePackage()), same as every
        // other metadata edit in this plugin.
        if (!empty($options['metadata'])) {
            (new \site7\studio\services\PackageAuthoringService())->updatePackage($handle, $options['metadata']);
        }

        $readiness = $plugin->publishValidator->validatePackage($handle);
        if (!$readiness->valid) {
            return $this->fail($handle, 'Package failed validation: ' . implode(' ', $readiness->errors), $dispatcher);
        }

        try {
            $s7pkgPath = $plugin->packageBuilder->build($handle, $options);
        } catch (\Throwable $e) {
            return $this->fail($handle, 'Could not build package: ' . $e->getMessage(), $dispatcher);
        }

        $bundle = $this->readBundleManifest($s7pkgPath);

        $dispatcher->dispatch(new RepositorySelectedEvent(['handle' => $handle, 'repositoryHandle' => $repositoryHandle]));
        $dispatcher->dispatch(new BeforePublishEvent(['handle' => $handle, 'repositoryHandle' => $repositoryHandle, 'packagePath' => $s7pkgPath]));

        $record = $plugin->packageManager->getPackageByHandle($handle);
        $version = $record->version ?? ($bundle->getRootEntry()['version'] ?? '0.0.0');

        try {
            $target->publishPackage($s7pkgPath, $bundle, $options['metadata'] ?? []);
        } catch (\Throwable $e) {
            $plugin->publishHistory->recordPublish($handle, $repositoryHandle, $version, 'failed');
            return $this->fail($handle, "Publishing to '{$target->getName()}' failed: " . $e->getMessage(), $dispatcher);
        }

        // Extension point only - see PackageSignerInterface's docblock;
        // NullPackageSigner always returns null, so nothing is persisted.
        $plugin->packageSigner->sign($s7pkgPath);

        $publication = $plugin->publishHistory->recordPublish(
            $handle,
            $repositoryHandle,
            $version,
            'published',
            $options['releaseNotes'] ?? null
        );

        $result = new PublishResult([
            'success' => true,
            'handle' => $handle,
            'repositoryHandle' => $repositoryHandle,
            'version' => $version,
            'publishedAt' => $publication->publishedAt,
            'message' => "Published to {$target->getName()}.",
            'packagePath' => $s7pkgPath,
        ]);

        $dispatcher->dispatch(new AfterPublishEvent(['handle' => $handle, 'result' => $result]));

        return $result;
    }

    private function fail(string $handle, string $reason, $dispatcher): PublishResult
    {
        $dispatcher->dispatch(new PublishFailedEvent(['handle' => $handle, 'reason' => $reason]));

        return new PublishResult([
            'success' => false,
            'handle' => $handle,
            'repositoryHandle' => '',
            'version' => '',
            'message' => $reason,
        ]);
    }

    private function readBundleManifest(string $s7pkgPath): PackageBundleManifest
    {
        $tempDir = Craft::getAlias('@storage') . '/runtime/site7-studio/publish-scan/' . StringHelper::UUID();

        try {
            PackageArchiveHelper::extractZip($s7pkgPath, $tempDir, ['bundle-manifest.json']);
            $data = json_decode((string)file_get_contents($tempDir . '/bundle-manifest.json'), true) ?: [];
        } finally {
            if (is_dir($tempDir)) {
                FileHelper::removeDirectory($tempDir);
            }
        }

        return new PackageBundleManifest($data);
    }
}
