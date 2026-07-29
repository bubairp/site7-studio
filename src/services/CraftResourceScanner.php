<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\services\scanning\AssetVolumeScanner;
use site7\studio\services\scanning\CategoryGroupScanner;
use site7\studio\services\scanning\EntryTypeScanner;
use site7\studio\services\scanning\FieldScanner;
use site7\studio\services\scanning\GlobalSetScanner;
use site7\studio\services\scanning\MatrixFieldScanner;
use site7\studio\services\scanning\NavigationScanner;
use site7\studio\services\scanning\PluginScanner;
use site7\studio\services\scanning\SectionScanner;
use site7\studio\services\scanning\TagGroupScanner;

/**
 * The single discovery layer for every native Craft CMS resource kind the
 * Website Starter Kit System needs to see (Website Starter Kit System
 * architecture refactor, ahead of Phase 2). Every import/analysis/future
 * builder service (Project Builder, Dependency Analyzer, Blueprint Builder,
 * Starter Kit Builder, Starter Kit Installer) should ask this facade for a
 * resource kind rather than calling Craft::$app->get*() itself - that
 * scattering is exactly what left volumes/categories/tags/sections
 * discovery duplicated (or simply missing) across WebsiteImportService,
 * CraftResourceDiscoveryService, and ResourceImportController before this
 * refactor.
 *
 * This class is a thin facade only: it holds one dedicated scanner per
 * resource kind (each implementing ResourceScannerInterface) and forwards to
 * it. It never classifies, transforms, or shapes a resource into manifest
 * data itself - that responsibility stays with the existing dedicated
 * services (ResourceClassifierService, CraftResourceService, the
 * *ImportService classes), which consume this facade's output instead of
 * querying Craft directly.
 *
 * Every sub-scanner property is public and constructible via the standard
 * Yii component config array (`new CraftResourceScanner(['sectionScanner' =>
 * $fake])`), so tests can substitute a fake scanner per resource kind
 * without needing a live Craft app.
 */
class CraftResourceScanner extends Component
{
    public ?SectionScanner $sectionScanner = null;
    public ?EntryTypeScanner $entryTypeScanner = null;
    public ?FieldScanner $fieldScanner = null;
    public ?MatrixFieldScanner $matrixFieldScanner = null;
    public ?AssetVolumeScanner $assetVolumeScanner = null;
    public ?CategoryGroupScanner $categoryGroupScanner = null;
    public ?TagGroupScanner $tagGroupScanner = null;
    public ?GlobalSetScanner $globalSetScanner = null;
    public ?NavigationScanner $navigationScanner = null;
    public ?PluginScanner $pluginScanner = null;

    public function init(): void
    {
        parent::init();
        $this->sectionScanner ??= new SectionScanner();
        $this->entryTypeScanner ??= new EntryTypeScanner();
        $this->fieldScanner ??= new FieldScanner();
        $this->matrixFieldScanner ??= new MatrixFieldScanner();
        $this->assetVolumeScanner ??= new AssetVolumeScanner();
        $this->categoryGroupScanner ??= new CategoryGroupScanner();
        $this->tagGroupScanner ??= new TagGroupScanner();
        $this->globalSetScanner ??= new GlobalSetScanner();
        $this->navigationScanner ??= new NavigationScanner();
        $this->pluginScanner ??= new PluginScanner();
    }

    /** @return \craft\models\Section[] */
    public function scanSections(): array
    {
        return $this->sectionScanner->scan();
    }

    /** @return \craft\models\EntryType[] */
    public function scanEntryTypes(): array
    {
        return $this->entryTypeScanner->scan();
    }

    /** @return \craft\base\FieldInterface[] */
    public function scanFields(): array
    {
        return $this->fieldScanner->scan();
    }

    /** @return \craft\fields\Matrix[] */
    public function scanMatrixFields(): array
    {
        return $this->matrixFieldScanner->scan();
    }

    /** @return \craft\models\Volume[] */
    public function scanAssetVolumes(): array
    {
        return $this->assetVolumeScanner->scan();
    }

    /** @return \craft\models\CategoryGroup[] */
    public function scanCategoryGroups(): array
    {
        return $this->categoryGroupScanner->scan();
    }

    /** @return \craft\models\TagGroup[] */
    public function scanTagGroups(): array
    {
        return $this->tagGroupScanner->scan();
    }

    /** @return \craft\elements\GlobalSet[] */
    public function scanGlobalSets(): array
    {
        return $this->globalSetScanner->scan();
    }

    /** @return \craft\base\FieldInterface[] */
    public function scanNavigation(): array
    {
        return $this->navigationScanner->scan();
    }

    /** @return \craft\base\PluginInterface[] */
    public function scanPlugins(): array
    {
        return $this->pluginScanner->scan();
    }
}
