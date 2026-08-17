<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\services\ConfigService;
use site7\studio\services\LogService;
use site7\studio\services\CacheService;
use site7\studio\services\PackageManagerService;
use site7\studio\services\CraftResourceService;
use site7\studio\services\CraftResourceScanner;
use site7\studio\services\CraftResourceRegistry;
use site7\studio\services\PlatformConfigService;
use site7\studio\services\FrontendToolingScanner;
use site7\studio\services\ComposerDependencyScanner;
use site7\studio\services\ProjectBuilder;
use site7\studio\services\DependencyAnalyzer;
use site7\studio\services\BlueprintBuilder;
use site7\studio\services\StarterKitBuilder;
use site7\studio\services\installation\InstallationPlanner;
use site7\studio\services\installation\InstallationValidator;
use site7\studio\services\installation\InstallationExecutor;
use site7\studio\services\installation\InstallationSessionService;
use site7\studio\services\installation\InstallationStageRunner;
use site7\studio\services\installation\InstallationOrchestratorService;
use site7\studio\services\installation\StarterKitCatalogService;
use site7\studio\services\synchronization\InstalledFileBaselineService;
use site7\studio\services\synchronization\PackageUpdatePlanner;
use site7\studio\services\synchronization\InstalledStarterKitTrackingService;
use site7\studio\services\synchronization\SynchronizationHistoryService;
use site7\studio\services\synchronization\SynchronizationPlanner;
use site7\studio\services\synchronization\SynchronizationValidator;
use site7\studio\services\synchronization\SynchronizationOrchestratorService;
use site7\studio\services\synchronization\SynchronizationSessionService;
use site7\studio\services\synchronization\UpdateCatalogService;
use site7\studio\services\MarketplaceService;
use site7\studio\services\SharedResourceRegistryService;
use site7\studio\services\SharedResourceUsageService;
use site7\studio\services\DependencyResolverService;
use site7\studio\services\import\ResourceClassifierService;

/**
 * Class CoreServiceProvider
 *
 * Registers the Core Infrastructure (Config, Logging, Cache).
 */
class CoreServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('configService', [
            'class' => ConfigService::class,
        ]);
        
        $plugin->set('log', [
            'class' => LogService::class,
        ]);
        
        $plugin->set('cache', [
            'class' => CacheService::class,
        ]);
        
        $plugin->set('packageManager', [
            'class' => PackageManagerService::class,
        ]);
        
        $plugin->set('craftResourceGenerator', [
            'class' => CraftResourceService::class,
        ]);
        
        $plugin->set('packageUsage', [
            'class' => \site7\studio\services\PackageUsageService::class,
        ]);

        $plugin->set('marketplace', [
            'class' => MarketplaceService::class,
        ]);

        $plugin->set('sharedResourceRegistry', [
            'class' => SharedResourceRegistryService::class,
        ]);

        $plugin->set('sharedResourceUsage', [
            'class' => SharedResourceUsageService::class,
        ]);

        $plugin->set('dependencyResolver', [
            'class' => DependencyResolverService::class,
        ]);

        $plugin->set('resourceClassifier', [
            'class' => ResourceClassifierService::class,
        ]);

        $plugin->set('craftResourceScanner', [
            'class' => CraftResourceScanner::class,
        ]);

        $plugin->set('craftResourceRegistry', [
            'class' => CraftResourceRegistry::class,
        ]);

        $plugin->set('platformConfig', [
            'class' => PlatformConfigService::class,
        ]);

        $plugin->set('frontendToolingScanner', [
            'class' => FrontendToolingScanner::class,
        ]);

        $plugin->set('composerDependencyScanner', [
            'class' => ComposerDependencyScanner::class,
        ]);

        $plugin->set('projectBuilder', [
            'class' => ProjectBuilder::class,
        ]);

        $plugin->set('dependencyAnalyzer', [
            'class' => DependencyAnalyzer::class,
        ]);

        $plugin->set('blueprintBuilder', [
            'class' => BlueprintBuilder::class,
        ]);

        $plugin->set('starterKitBuilder', [
            'class' => StarterKitBuilder::class,
        ]);

        $plugin->set('installationPlanner', [
            'class' => InstallationPlanner::class,
        ]);

        $plugin->set('installationValidator', [
            'class' => InstallationValidator::class,
        ]);

        $plugin->set('installationExecutor', [
            'class' => InstallationExecutor::class,
        ]);

        $plugin->set('installationSessions', [
            'class' => InstallationSessionService::class,
        ]);

        $plugin->set('installationStageRunner', [
            'class' => InstallationStageRunner::class,
        ]);

        $plugin->set('installationOrchestrator', [
            'class' => InstallationOrchestratorService::class,
        ]);

        $plugin->set('starterKitCatalog', [
            'class' => StarterKitCatalogService::class,
        ]);

        $plugin->set('installedStarterKits', [
            'class' => InstalledStarterKitTrackingService::class,
        ]);

        $plugin->set('installedFileBaseline', [
            'class' => InstalledFileBaselineService::class,
        ]);

        $plugin->set('packageUpdatePlanner', [
            'class' => PackageUpdatePlanner::class,
        ]);

        $plugin->set('synchronizationHistory', [
            'class' => SynchronizationHistoryService::class,
        ]);

        $plugin->set('synchronizationPlanner', [
            'class' => SynchronizationPlanner::class,
        ]);

        $plugin->set('synchronizationValidator', [
            'class' => SynchronizationValidator::class,
        ]);

        $plugin->set('synchronizationOrchestrator', [
            'class' => SynchronizationOrchestratorService::class,
        ]);

        $plugin->set('synchronizationSessions', [
            'class' => SynchronizationSessionService::class,
        ]);

        $plugin->set('updateCatalog', [
            'class' => UpdateCatalogService::class,
        ]);
    }
}
