<?php

namespace site7\studio\services\design;

use Craft;
use craft\events\ConfigEvent;
use site7\studio\models\Settings;
use site7\studio\services\library\LibraryDistribution;
use site7\studio\Site7Studio;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * The Appearance screen's model (docs/60 §4c): which designs this site has
 * or can get, which one is active, and switching between them.
 */
class DesignService
{
    /** Fired after the active design changed and caches were cleared; a host can clear its own caches. */
    public const EVENT_AFTER_ACTIVATE_DESIGN = 'afterActivateDesign';

    /**
     * Every design: the default first, then installed ones, then ones the
     * Library offers.
     *
     * @return array<int, array{handle: ?string, package: ?string, name: string, description: string, installed: bool, version: ?string, latest: ?string, active: bool, entitled: bool, pricingType: string, theme: ?array, themeMatches: bool, changedBases: string[], onDisk: bool}>
     */
    public function designs(bool $withCatalog = true): array
    {
        $plugin = Site7Studio::getInstance();
        $active = DesignInstaller::activeHandle();
        $catalog = $withCatalog ? array_filter((new LibraryDistribution())->catalog(), fn($entry) => ($entry['type'] ?? '') === 'design') : [];
        $theme = null;
        foreach ($plugin->packageManager->getAllPackages() as $record) {
            if ($record->type === 'theme' && in_array($record->status, ['installed', 'enabled'], true)) {
                $theme = ['handle' => $record->handle, 'version' => (string)$record->version];
            }
        }

        $designs = [[
            'handle' => null,
            'package' => null,
            'name' => 'Default',
            'description' => "The site's own templates and frontend.",
            'installed' => true,
            'version' => null,
            'latest' => null,
            'active' => $active === null,
            'entitled' => true,
            'pricingType' => 'free',
            'theme' => null,
            'themeMatches' => true,
            'changedBases' => [],
            'onDisk' => true,
        ]];
        $seen = [];
        foreach ($plugin->packageManager->getAllPackages() as $record) {
            if ($record->type !== 'design') {
                continue;
            }
            $dir = (string)$plugin->packageManager->getPackagePath($record->handle);
            $meta = json_decode((string)@file_get_contents("{$dir}/" . DesignBuilder::META_FILE), true) ?: [];
            $handle = DesignLoader::validHandle($meta['handle'] ?? null);
            if ($handle === null) {
                continue;
            }
            $entry = $catalog[$record->handle] ?? [];
            $installed = $record->status === 'installed';
            $designs[] = [
                'handle' => $handle,
                'package' => $record->handle,
                'name' => (string)($meta['name'] ?? $record->name),
                'description' => (string)($meta['description'] ?? ''),
                'installed' => $installed,
                'version' => (string)$record->version,
                'latest' => $entry['version'] ?? null,
                'active' => $active === $handle,
                'entitled' => (bool)($entry['entitled'] ?? true),
                'pricingType' => (string)($entry['pricingType'] ?? 'free'),
                'theme' => $meta['theme'] ?? null,
                'themeMatches' => !isset($meta['theme']['handle'], $theme) || ($meta['theme']['handle'] === $theme['handle'] && $meta['theme']['version'] === $theme['version']),
                'changedBases' => $installed ? self::changedBases($meta['overrides'] ?? []) : [],
                'onDisk' => is_file(Craft::getAlias("@root/designs/{$handle}/design.json")),
            ];
            $seen[$record->handle] = true;
        }
        foreach ($catalog as $package => $entry) {
            if (isset($seen[$package])) {
                continue;
            }
            $library = $entry['metadata']['library'] ?? [];
            $designs[] = [
                'handle' => DesignLoader::validHandle($library['handle'] ?? null),
                'package' => (string)$package,
                'name' => (string)($entry['name'] ?? $package),
                'description' => (string)($entry['description'] ?? ''),
                'installed' => false,
                'version' => null,
                'latest' => $entry['version'] ?? null,
                'active' => false,
                'entitled' => (bool)($entry['entitled'] ?? true),
                'pricingType' => (string)($entry['pricingType'] ?? 'free'),
                'theme' => $library['theme'] ?? null,
                'themeMatches' => !isset($library['theme']['handle'], $theme) || $library['theme']['handle'] === $theme['handle'],
                'changedBases' => [],
                'onDisk' => false,
            ];
        }

        return $designs;
    }

    /**
     * Makes $handle (null: the default) the design every page renders, then
     * clears the template caches. Development site only; deployed with
     * project config.
     *
     * @throws \Exception
     */
    public function activate(?string $handle): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new \Exception('Activate designs on your development site and deploy: admin changes are off here.');
        }
        if (in_array('activeDesign', Settings::overriddenKeys(), true)) {
            throw new \Exception('SITE7_DESIGN in .env sets the active design on this environment; remove it to switch here.');
        }
        if ($handle !== null) {
            $design = null;
            foreach ($this->designs() as $candidate) {
                if ($candidate['handle'] === $handle) {
                    $design = $candidate;
                }
            }
            // On disk: installed, or authored here (rp-craft builds designs from designs/<handle>).
            if (!$design || !$design['onDisk']) {
                throw new \Exception("The design '{$handle}' isn't installed on this site.");
            }
            // After a downgrade, an active paid design keeps working, but one
            // switched away from can't come back without the plan (docs/60 §4).
            if (!$design['entitled'] && !$design['active']) {
                throw new \Exception("'{$design['name']}' isn't in your plan or purchases.");
            }
        }
        $plugin = Site7Studio::getInstance();
        Craft::$app->getPlugins()->savePluginSettings($plugin, Settings::mergeWithStored(['activeDesign' => $handle]));
        $plugin->getSettings()->activeDesign = $handle;
        $this->clearCaches();
    }

    /**
     * Template caches ({% cache %}, tagged "template") and element caches:
     * without this a switch shows a mix of both designs.
     */
    public function clearCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), 'template');
        Craft::$app->getElements()->invalidateAllCaches();
        Event::trigger(self::class, self::EVENT_AFTER_ACTIVATE_DESIGN);
    }

    /**
     * Clears caches when a deploy (craft up) applies a changed activeDesign
     * from project config, not only when it's switched in this CP.
     */
    public static function registerProjectConfigListener(): void
    {
        Craft::$app->getProjectConfig()->onUpdate('plugins.site7-studio.settings', function(ConfigEvent $event) {
            if (($event->oldValue['activeDesign'] ?? null) !== ($event->newValue['activeDesign'] ?? null)) {
                (new self())->clearCaches();
            }
        });
    }

    /**
     * Base templates the design overrides that changed since it was built
     * (a Theme or block update the design's copy hides).
     *
     * @param array<string, string|null> $overrides
     * @return string[]
     */
    public static function changedBases(array $overrides): array
    {
        $changed = [];
        foreach ($overrides as $path => $checksum) {
            if ($checksum === null) {
                continue;
            }
            $base = Craft::getAlias("@root/templates/{$path}");
            if (!is_file($base) || sha1_file($base) !== $checksum) {
                $changed[] = $path;
            }
        }

        return $changed;
    }
}
