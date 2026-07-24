<?php

namespace site7\studio\providers;

use site7\studio\Site7Studio;
use site7\studio\services\import\CraftResourceDiscoveryService;
use site7\studio\services\import\CraftSectionImportService;
use site7\studio\services\import\MatrixEntryTypeImportService;
use site7\studio\services\import\PageImportService;
use site7\studio\services\import\ResourceAnalyzerService;
use site7\studio\services\import\ResourceImportValidator;
use site7\studio\services\import\WebsiteImportService;

/**
 * Registers the Craft Resource Import & Package Generator (Phase 15)
 * services on the plugin's service locator, for discoverability/testability
 * consistency with the other providers. Controllers still `new` these
 * services directly (the prevailing convention for the generator-style
 * services this feature extends - see TemplateGeneratorService/
 * StarterKitGeneratorService/PackageAuthoringService, all controller-`new`'d
 * rather than service-located), so this registration is not load-bearing for
 * the feature to function.
 */
class ImportServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        $plugin->set('resourceAnalyzer', ResourceAnalyzerService::class);
        $plugin->set('resourceImportValidator', ResourceImportValidator::class);
        $plugin->set('matrixEntryTypeImporter', MatrixEntryTypeImportService::class);
        $plugin->set('craftSectionImporter', CraftSectionImportService::class);
        $plugin->set('pageImporter', PageImportService::class);
        $plugin->set('websiteImporter', WebsiteImportService::class);
        $plugin->set('craftResourceDiscovery', CraftResourceDiscoveryService::class);
    }
}
