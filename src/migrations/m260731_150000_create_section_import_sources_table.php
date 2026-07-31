<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260731_150000_create_section_import_sources_table migration.
 *
 * Phase 9.1 - "Import Existing Section" duplicate-detection/update-tracking.
 * Deliberately a separate table rather than new columns on the shared
 * {{%site7_packages}} table: every other package type (Page/Website/
 * Component/Pattern/Starter Kit) goes through the same PackageRecord/
 * PackageRepository, and this phase must not change their behavior at all.
 * One row per imported Section package, keyed by the source Craft Entry
 * Type's own `uid` (never its numeric id - ids aren't stable identity across
 * environments/reinstalls, per this phase's explicit requirement).
 */
class m260731_150000_create_section_import_sources_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_section_import_sources}}')) {
            $this->createTable('{{%site7_section_import_sources}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'sourceUid' => $this->string()->notNull(),
                'sourceType' => $this->string()->notNull(),
                'sourceHandle' => $this->string()->notNull(),
                'sourceHash' => $this->string()->notNull(),
                'importedAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_section_import_sources}}', 'packageId', true);
            $this->createIndex(null, '{{%site7_section_import_sources}}', 'sourceUid', true);
            $this->addForeignKey(null, '{{%site7_section_import_sources}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_section_import_sources}}');

        return true;
    }
}
