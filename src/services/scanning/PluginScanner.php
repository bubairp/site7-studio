<?php

namespace site7\studio\services\scanning;

use Craft;
use craft\base\PluginInterface;
use site7\studio\interfaces\ResourceScannerInterface;

/**
 * Discovers installed Craft plugins - the read side needed by later phases
 * (dependency capture, install orchestration) that need to know what's
 * actually on the source/target site, as opposed to
 * ResourceClassifierService's field-level "this field looks like it's
 * provided by a plugin" guesswork, which stays where it is.
 */
class PluginScanner implements ResourceScannerInterface
{
    /** @return PluginInterface[] handle => plugin, installed (enabled or disabled) */
    public function scan(): array
    {
        return Craft::$app->getPlugins()->getAllPlugins();
    }

    public function findByHandle(string $handle): ?PluginInterface
    {
        return Craft::$app->getPlugins()->getPlugin($handle);
    }

    public function isInstalled(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled($handle);
    }

    public function isEnabled(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled($handle);
    }
}
