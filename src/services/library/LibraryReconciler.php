<?php

namespace site7\studio\services\library;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\fields\Matrix;
use site7\studio\models\Settings;
use site7\studio\records\PackageInstalledFileRecord;
use site7\studio\records\PackageRecord;
use site7\studio\services\PackageManagerService;
use site7\studio\services\starterkit\KitBuilder;
use site7\studio\services\starterkit\KitInstaller;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\template\TemplateBuilder;
use site7\studio\services\template\TemplateInstaller;
use site7\studio\services\theme\ThemeSchemaService;
use site7\studio\Site7Studio;

/**
 * Matches what a site already has to its Library packages, when there is
 * no tracking for it - the plugin was reinstalled without an uninstall
 * snapshot (docs/58_PLUGIN_UNINSTALL_AND_REINSTALL.md). Without it, those
 * packages show as 'available' and installing one again collides with its
 * own block type, fields and template.
 *
 * Only ever adds tracking, never removes or changes Craft resources:
 * - Section packages whose block type is on the site, found through
 *   PackageManagerService::sectionPackageForEntryType() (the same matching
 *   Import uses so a block is never imported twice): 'available' becomes
 *   'enabled' when the block is on the page builder, 'installed' otherwise.
 *   Other statuses are left alone.
 * - Installed-file baselines (InstalledFileBaselineService) for a block's
 *   _blocks template and for owned files, only where no package has one.
 *   A template gets the Library copy's checksum, so a template that differs
 *   from it counts as edited on this site and Library updates keep it;
 *   an owned file only when it is identical to the Library's.
 * - The page builder setting, when it's empty and exactly one Matrix field
 *   holds the most Library blocks.
 *
 * Themes, Templates and Starter Kits only with $siteContent (opt-in,
 * `reconcile --site-content`): a Template whose page (its template.json
 * entryUid) is on the site, a Theme whose page builder field (schema.json
 * pageBuilderField UID) is, and a Library Starter Kit whose Theme and
 * Templates all matched become 'enabled', as their installers leave them.
 * Never automatic: on the author site those UIDs exist because the packages
 * were built from it, and marking them installed there would expose the
 * author's own pages to Library updates.
 */
class LibraryReconciler extends Component
{
    /**
     * @param bool $siteContent also match Themes, Templates and Starter Kits (customer sites only)
     * @return array{dryRun: bool, changes: int, matrixField: ?string, packages: array<string, array{from: string, to: string, blocks: string[]}>, baselines: array<string, string>, notes: string[]}
     */
    public function reconcile(bool $dryRun = true, bool $siteContent = false): array
    {
        $plugin = Site7Studio::getInstance();
        $packageManager = $plugin->packageManager;
        $report = ['dryRun' => $dryRun, 'changes' => 0, 'matrixField' => null, 'packages' => [], 'baselines' => [], 'notes' => []];

        // Section packages whose block types are on this site.
        $found = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $record = $packageManager->sectionPackageForEntryType($entryType->handle);
            if ($record !== null) {
                $found[$record->handle][] = $entryType->handle;
            }
        }

        $matrixField = $this->pageBuilderField(array_merge([], ...array_values($found)), $report);
        $linked = $matrixField ? array_map(fn($entryType) => $entryType->handle, $matrixField->getEntryTypes()) : [];

        $statuses = [];
        foreach ($found as $handle => $blocks) {
            $record = $packageManager->getPackageByHandle($handle);
            if ($record === null || $record->status !== 'available') {
                continue;
            }
            $to = array_intersect($blocks, $linked) ? 'enabled' : 'installed';
            $statuses[$handle] = $to;
            $report['packages'][$handle] = ['from' => 'available', 'to' => $to, 'blocks' => $blocks];
        }

        if ($siteContent) {
            foreach ($this->siteContentPackages($packageManager) as $handle => $evidence) {
                $statuses[$handle] = 'enabled';
                $report['packages'][$handle] = ['from' => 'available', 'to' => 'enabled', 'blocks' => [$evidence]];
            }
        }

        $baselines = $this->missingBaselines($packageManager, $statuses);
        foreach ($baselines as $baseline) {
            $report['baselines'][$baseline['targetPath']] = "{$baseline['handle']}: {$baseline['note']}";
        }

        // Imported here (Import Existing Section) is only known from the
        // snapshot: a Library package shipped to another site carries the
        // same manifest importedFrom and block UID, so it can't be told apart.
        $imported = [];
        foreach (array_keys($found) as $handle) {
            $sourceUid = $packageManager->getPackageByHandle($handle)?->getManifest()?->importedFrom['sourceUid'] ?? null;
            if ($sourceUid && Craft::$app->getEntries()->getEntryTypeByUid((string)$sourceUid)
                && !(new \site7\studio\repositories\SectionImportSourceRepository())->findBySourceUid((string)$sourceUid)) {
                $imported[] = $handle;
            }
        }
        if ($imported) {
            $report['notes'][] = count($imported) . ' Section packages were made by importing a block type that is on this site (' . implode(', ', array_slice($imported, 0, 5)) . (count($imported) > 5 ? ', ...' : '') . '). Only matters on the site that imported them (the author site): their import links come back only from an uninstall snapshot, and without one Remove treats the block as generated (usage-checked delete). Elsewhere - a site that installed them from the Library - nothing is needed.';
        }

        if (!$siteContent) {
            $others = PackageRecord::find()->where(['status' => 'available'])->andWhere(['in', 'type', ['theme', 'template', 'starter-kit']])->count();
            if ($others > 0) {
                $report['notes'][] = "{$others} Theme/Template/Starter Kit packages are 'available' and were not checked. On a site that installed them from the Library (not the author site), run reconcile --site-content to match them.";
            }
        }

        $report['changes'] = count($statuses) + count($baselines) + ($report['matrixField'] !== null ? 1 : 0);
        if ($dryRun || $report['changes'] === 0) {
            return $report;
        }

        if ($report['matrixField'] !== null && $matrixField) {
            Craft::$app->getPlugins()->savePluginSettings($plugin, Settings::mergeWithStored(['matrixFieldUid' => $matrixField->uid]));
        }
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($statuses as $handle => $status) {
                PackageRecord::updateAll(['status' => $status], ['handle' => $handle, 'status' => 'available']);
            }
            foreach ($baselines as $baseline) {
                $plugin->installedFileBaseline->record($baseline['packageId'], $baseline['resourceHandle'], $baseline['targetPath'], $baseline['version'], $baseline['checksum']);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $report;
    }

    /**
     * 'available' Themes, Templates (format v2) and Library Starter Kits
     * whose content is on this site - see the class docblock.
     *
     * @return array<string, string> handle => what matched
     */
    private function siteContentPackages(PackageManagerService $packageManager): array
    {
        $records = [];
        foreach ($packageManager->getAllPackages() as $record) {
            $records[$record->handle] = $record;
        }
        $read = fn(string $handle, string $file) => json_decode((string)@file_get_contents($packageManager->getPackagePath($handle) . "/{$file}"), true) ?: [];
        $onSite = [];

        foreach ($records as $handle => $record) {
            if ($record->type === 'template' && TemplateInstaller::isFormatV2($packageManager->getPackagePath($handle))) {
                $uid = (string)($read($handle, TemplateBuilder::META_FILE)['entryUid'] ?? '');
                if ($uid !== '' && Entry::find()->uid($uid)->status(null)->site('*')->exists()) {
                    $onSite[$handle] = "page {$uid}";
                }
            } elseif ($record->type === 'theme') {
                $uid = (string)($read($handle, ThemeSchemaService::FILE)['pageBuilderField'] ?? '');
                if ($uid !== '' && Craft::$app->getFields()->getFieldByUid($uid) !== null) {
                    $onSite[$handle] = "page builder field {$uid}";
                }
            }
        }
        foreach ($records as $handle => $record) {
            if ($record->type !== 'starter-kit' || !KitInstaller::isFormatV2($packageManager->getPackagePath($handle))) {
                continue;
            }
            $meta = $read($handle, KitBuilder::META_FILE);
            $templates = (array)($record->getManifest()?->requires['templates'] ?? []);
            $themeOk = !empty($meta['pack']) || (isset($meta['theme']) && (isset($onSite[$meta['theme']]) || ($records[$meta['theme']] ?? null)?->status === 'enabled'));
            $templatesOk = $templates && !array_filter($templates, fn($t) => !isset($onSite[$t]) && ($records[$t] ?? null)?->status !== 'enabled');
            if ($themeOk && $templatesOk) {
                $onSite[$handle] = 'its Theme and ' . count($templates) . ' Templates';
            }
        }

        return array_filter($onSite, fn($handle) => $records[$handle]->status === 'available', ARRAY_FILTER_USE_KEY);
    }

    /**
     * The page builder: the configured one, or - when none is set - the one
     * Matrix field holding the most Library blocks (none on a tie).
     *
     * @param string[] $blocks block handles that belong to Library packages
     */
    private function pageBuilderField(array $blocks, array &$report): ?Matrix
    {
        $fields = Craft::$app->getFields();
        $matrixFieldId = Site7Studio::getInstance()->getSettings()->getMatrixFieldId();
        $configured = $matrixFieldId ? $fields->getFieldById($matrixFieldId) : null;
        if ($configured instanceof Matrix || !$blocks) {
            return $configured instanceof Matrix ? $configured : null;
        }

        $scores = [];
        $candidates = [];
        foreach ($fields->getAllFields() as $field) {
            if ($field instanceof Matrix) {
                $handles = array_map(fn($entryType) => $entryType->handle, $field->getEntryTypes());
                $scores[$field->uid] = count(array_intersect($handles, $blocks));
                $candidates[$field->uid] = $field;
            }
        }
        arsort($scores);
        $top = array_slice($scores, 0, 2, true);
        $uids = array_keys($top);
        $values = array_values($top);
        if (!$values || $values[0] === 0 || (isset($values[1]) && $values[1] === $values[0])) {
            $report['notes'][] = 'No page builder is set and no single Matrix field holds the Library blocks - set it in Settings → General.';
            return null;
        }

        $field = $candidates[$uids[0]];
        $report['matrixField'] = "{$field->handle} ({$values[0]} Library blocks)";

        return $field;
    }

    /**
     * Baselines to add: for Section packages on the site (already, or by
     * $statuses) the first block's _blocks template - where
     * CraftResourceService installs template.twig - and for every package on
     * the site its owned files identical to the Library's.
     *
     * @param array<string, string> $statuses handle => status this run gives
     * @return array<int, array{handle: string, packageId: int, resourceHandle: string, targetPath: string, version: string, checksum: string, note: string}>
     */
    private function missingBaselines(PackageManagerService $packageManager, array $statuses): array
    {
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $owned = array_flip(PackageInstalledFileRecord::find()->select('targetPath')->column());
        $baselines = [];

        foreach ($packageManager->getAllPackages() as $record) {
            $status = $statuses[$record->handle] ?? $record->status;
            $path = $packageManager->getPackagePath($record->handle);
            if (!in_array($status, ['installed', 'enabled', 'disabled'], true) || $path === null) {
                continue;
            }

            $candidates = [];
            $blocks = $record->type === 'section' ? $packageManager->sectionBlockHandles($record->handle) : [];
            if ($blocks && is_file("{$path}/template.twig")) {
                $candidates[] = ['resourceHandle' => $blocks[0], 'targetPath' => "templates/_blocks/{$blocks[0]}.twig", 'source' => "{$path}/template.twig", 'mustMatch' => false];
            }
            foreach ((array)($record->getManifest()?->ownedFiles ?? []) as $file) {
                $target = (string)($file['targetPath'] ?? '');
                $source = (string)($file['sourcePath'] ?? '');
                if (PackageManagerService::isAllowedOwnedFileTarget($target) && PackageManagerService::isRelativeSafePath($source)) {
                    $candidates[] = ['resourceHandle' => $record->handle, 'targetPath' => $target, 'source' => "{$path}/{$source}", 'mustMatch' => true];
                }
            }

            foreach ($candidates as $candidate) {
                $live = "{$root}/{$candidate['targetPath']}";
                if (isset($owned[$candidate['targetPath']]) || !is_file($live) || !is_file($candidate['source'])) {
                    continue;
                }
                $checksum = PackageArchiveHelper::computeFileChecksum($candidate['source']);
                $matches = $checksum !== null && $checksum === PackageArchiveHelper::computeFileChecksum($live);
                if ($checksum === null || ($candidate['mustMatch'] && !$matches)) {
                    continue;
                }
                $owned[$candidate['targetPath']] = true;
                $baselines[] = [
                    'handle' => $record->handle,
                    'packageId' => (int)$record->id,
                    'resourceHandle' => $candidate['resourceHandle'],
                    'targetPath' => $candidate['targetPath'],
                    'version' => (string)$record->version,
                    'checksum' => $checksum,
                    'note' => $matches ? 'same as the Library' : 'differs from the Library - kept as edited on this site',
                ];
            }
        }

        return $baselines;
    }
}
