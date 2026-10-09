<?php

namespace site7\studio\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;

/**
 * m261005_100000_add_package_version_unique_index migration.
 *
 * Backs MarketplaceService::recordVersion()'s "one row per (package,
 * version)" check with a unique index, so a race between two version
 * writers can't store the same version twice (docs/43 #10).
 *
 * A site that already has duplicate rows (written by PageUpdateService
 * before 2026-10-05, docs/18 §5a) keeps them: the index is skipped with a
 * warning rather than deleting version history. Remove the extra rows and
 * run `craft migrate/redo` for this migration to add it.
 */
class m261005_100000_add_package_version_unique_index extends Migration
{
    private const INDEX = 'site7_package_versions_packageId_version_unq';

    public function safeUp(): bool
    {
        $table = '{{%site7_package_versions}}';
        if (!$this->db->tableExists($table) || $this->hasIndex()) {
            return true;
        }

        $duplicates = (new Query())
            ->select(['packageId', 'version'])
            ->from($table)
            ->groupBy(['packageId', 'version'])
            ->having('COUNT(*) > 1')
            ->count('*', $this->db);
        if ($duplicates > 0) {
            Craft::warning("Not adding the unique (packageId, version) index: {$duplicates} package versions are stored more than once.", 'site7-studio');
            return true;
        }

        $this->createIndex(self::INDEX, $table, ['packageId', 'version'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%site7_package_versions}}';
        if ($this->hasIndex()) {
            // MySQL drops the packageId foreign key's own index once this
            // index covers it, then refuses to drop this one (error 1553,
            // "needed in a foreign key constraint") - which failed every
            // plugin uninstall. Give the key a plain index first.
            if (Db::findIndex($table, ['packageId'], false, $this->db) === null) {
                $this->createIndex(null, $table, ['packageId'], false);
            }
            $this->dropIndex(self::INDEX, $table);
        }

        return true;
    }

    private function hasIndex(): bool
    {
        $schema = $this->db->getSchema();
        $rawTable = $schema->getRawTableName('{{%site7_package_versions}}');
        foreach ($schema->findUniqueIndexes($schema->getTableSchema($rawTable, true)) as $name => $columns) {
            if ($columns === ['packageId', 'version']) {
                return true;
            }
        }

        return false;
    }
}
