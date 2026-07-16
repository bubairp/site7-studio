<?php

namespace site7\studio\providers;

use Craft;
use site7\studio\Site7Studio;
use site7\studio\interfaces\ManifestReaderInterface;
use site7\studio\interfaces\ComponentRegistryInterface;
use site7\studio\interfaces\TemplateRegistryInterface;
use site7\studio\interfaces\SearchServiceInterface;
use site7\studio\interfaces\LibraryServiceInterface;
use site7\studio\services\ManifestReader;
use site7\studio\services\ComponentRegistry;
use site7\studio\services\TemplateRegistry;
use site7\studio\services\SearchService;
use site7\studio\services\LibraryService;
use site7\studio\services\sources\BuiltInLibrarySource;

/**
 * Class LibraryServiceProvider
 *
 * Bootstraps and registers Library specific services into the Service Locator.
 */
class LibraryServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function register(Site7Studio $plugin): void
    {
        // Set plugin components
        $plugin->set('manifestReader', ManifestReader::class);
        $plugin->set('componentRegistry', ComponentRegistry::class);
        $plugin->set('templateRegistry', TemplateRegistry::class);
        $plugin->set('searchService', SearchService::class);
        
        // Setup LibraryService with BuiltInSource
        $plugin->set('libraryService', function() {
            $library = new LibraryService();
            $library->registerSource(new BuiltInLibrarySource());
            return $library;
        });
        
        // Bind interfaces in Craft's main DI container
        Craft::$container->set(ManifestReaderInterface::class, ManifestReader::class);
        Craft::$container->set(ComponentRegistryInterface::class, ComponentRegistry::class);
        Craft::$container->set(TemplateRegistryInterface::class, TemplateRegistry::class);
        Craft::$container->set(SearchServiceInterface::class, SearchService::class);
        Craft::$container->set(LibraryServiceInterface::class, function() use ($plugin) {
            return $plugin->get('libraryService');
        });
    }
}
