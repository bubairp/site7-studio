<?php

namespace site7\studio\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\synchronization\BaselinePathRepair;

/**
 * Library updates saved block-template tracking rows as "_blocks/<handle>.twig"
 * instead of "templates/_blocks/<handle>.twig" (docs/43 #67). Merges them into
 * the right row without letting a stale row overwrite a correct one
 * (BaselinePathRepair). Changes only site7_installed_files; safe to run again.
 */
class m261026_120000_fix_block_template_baseline_paths extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%site7_installed_files}}';
        if (!$this->db->tableExists($table)) {
            return true;
        }
        $rows = (new Query())
            ->select(['id', 'packageId', 'targetPath', 'checksum', 'installedVersion', 'resourceHandle', 'dateUpdated'])
            ->from($table)
            ->all();
        $root = dirname(rtrim(Craft::$app->getPath()->getSiteTemplatesPath(), '/'));
        $ops = BaselinePathRepair::plan($rows, fn(string $path) => PackageArchiveHelper::computeFileChecksum("{$root}/{$path}"));

        foreach ($ops as $op) {
            if ($op['op'] === 'delete') {
                $this->delete($table, ['id' => $op['id']]);
            } else {
                $this->update($table, $op['set'], ['id' => $op['id']]);
            }
        }
        if ($ops) {
            echo '    > repaired ' . count($ops) . " block template tracking row change(s)\n";
        }

        // Rows an update left at an older version, refreshed only where the file on
        // disk is exactly the installed version's own template (the Library copy).
        $rows = (new Query())
            ->select(['id', 'packageId', 'targetPath', 'checksum', 'installedVersion'])
            ->from($table)
            ->all();
        $packages = (new Query())->select(['id', 'handle', 'version'])->from('{{%site7_packages}}')->indexBy('id')->all();
        $packageManager = \site7\studio\Site7Studio::getInstance()?->packageManager;
        $refresh = BaselinePathRepair::refreshPlan(
            $rows,
            fn(string $path) => PackageArchiveHelper::computeFileChecksum("{$root}/{$path}"),
            function(int $packageId) use ($packages, $packageManager): ?array {
                $package = $packages[$packageId] ?? null;
                $dir = ($package && $packageManager) ? $packageManager->getPackagePath($package['handle']) : null;
                $checksum = $dir ? PackageArchiveHelper::computeFileChecksum("{$dir}/template.twig") : null;
                return $checksum ? ['checksum' => $checksum, 'version' => (string)$package['version']] : null;
            },
        );
        foreach ($refresh as $op) {
            $this->update($table, $op['set'], ['id' => $op['id']]);
        }
        if ($refresh) {
            echo '    > brought ' . count($refresh) . " block template tracking row(s) up to the installed version\n";
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
