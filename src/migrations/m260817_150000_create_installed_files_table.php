<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260817_150000_create_installed_files_table migration.
 *
 * Step 5 (Installed-File Baseline): no existing table records what a
 * package actually wrote onto a site's disk - site7_package_versions is the
 * package's own portable version history (see archivePath, added in
 * m260813), and site7_installed_starter_kits (Phase 8) is a whole-Blueprint
 * JSON snapshot for native Craft resources, neither of which is per-file
 * granularity. site7_installed_files is: one row per (package, target file)
 * pair, recording the checksum of that file exactly as it existed
 * immediately after the package last wrote it - the baseline a later
 * update/conflict check (Step 6) diffs the live file and an incoming
 * package version against, per this project's baseline/live/incoming rule.
 */
class m260817_150000_create_installed_files_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_installed_files}}')) {
            $this->createTable('{{%site7_installed_files}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                // The block/Entry Type handle this file belongs to - lets a
                // later conflict report name "which component" without
                // re-deriving it from targetPath's filename.
                'resourceHandle' => $this->string()->notNull(),
                // Relative to the Craft install root, e.g.
                // templates/_blocks/ctaBanner.twig - never absolute, since an
                // absolute path isn't portable across environments.
                'targetPath' => $this->string()->notNull(),
                // The package version that was active when this file was
                // last (re)written - not necessarily the package's current
                // version if a later version's install/update hasn't reached
                // this site yet.
                'installedVersion' => $this->string()->notNull(),
                // PackageArchiveHelper::computeFileChecksum() of the file as
                // it existed immediately after this package wrote it.
                'checksum' => $this->string()->notNull(),
                'installedAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            // One baseline per (package, file) - installPackage() re-running
            // (repair/reinstall) upserts this row rather than accumulating a
            // new one every time the same file is (re)written.
            $this->createIndex(null, '{{%site7_installed_files}}', ['packageId', 'targetPath'], true);
            $this->addForeignKey(null, '{{%site7_installed_files}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_installed_files}}');

        return true;
    }
}
