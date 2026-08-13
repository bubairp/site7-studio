<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260813_142335_add_package_version_archive_path migration.
 *
 * Phase 5 (Initial Version): site7_package_versions already records a
 * version's checksum via MarketplaceService::recordVersion() (called from
 * PackageExportService::exportPackage()), but nothing points back to the
 * actual .s7pkg snapshot that checksum was computed from. Adds a nullable
 * archivePath so a version row can reference its real, restorable
 * snapshot - the foundation Phase 10 (Rollback) needs later.
 */
class m260813_142335_add_package_version_archive_path extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%site7_package_versions}}', 'archivePath')) {
            $this->addColumn('{{%site7_package_versions}}', 'archivePath', $this->string()->null());
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%site7_package_versions}}', 'archivePath')) {
            $this->dropColumn('{{%site7_package_versions}}', 'archivePath');
        }

        return true;
    }
}
