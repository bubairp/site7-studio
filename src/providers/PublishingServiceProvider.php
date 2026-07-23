<?php

namespace site7\studio\providers;

use site7\studio\services\publishing\NullPackageSigner;
use site7\studio\services\publishing\PackageBuilderService;
use site7\studio\services\publishing\PackagePublisherService;
use site7\studio\services\publishing\PublishHistoryService;
use site7\studio\services\publishing\PublishValidatorService;
use site7\studio\services\publishing\RepositoryManagerService;
use site7\studio\services\publishing\VersionManagerService;
use site7\studio\Site7Studio;

/**
 * Registers the Package Publishing Platform's services, following the same
 * ServiceProviderInterface pattern as CoreServiceProvider/CommerceServiceProvider.
 */
class PublishingServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('packageBuilder', ['class' => PackageBuilderService::class]);
        $plugin->set('publishValidator', ['class' => PublishValidatorService::class]);
        $plugin->set('repositoryManager', ['class' => RepositoryManagerService::class]);
        $plugin->set('versionManager', ['class' => VersionManagerService::class]);
        $plugin->set('publishHistory', ['class' => PublishHistoryService::class]);
        $plugin->set('packageSigner', ['class' => NullPackageSigner::class]);
        $plugin->set('publisher', ['class' => PackagePublisherService::class]);
    }
}
