<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260801_000000_create_page_import_sources_table migration.
 *
 * Phase 9.2 - "Import Existing Page" duplicate-detection/update-tracking,
 * mirroring m260731_150000_create_section_import_sources_table.php exactly.
 * A separate table (not new columns on {{%site7_packages}}) so no other
 * package type's behavior changes. One row per imported Page package, keyed
 * by the source Craft Entry's own `uid` (never its numeric id).
 */
class m260801_000000_create_page_import_sources_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_page_import_sources}}')) {
            $this->createTable('{{%site7_page_import_sources}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'sourceUid' => $this->string()->notNull(),
                'sourceHandle' => $this->string()->notNull(),
                'sourceHash' => $this->string()->notNull(),
                'importedAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_page_import_sources}}', 'packageId', true);
            $this->createIndex(null, '{{%site7_page_import_sources}}', 'sourceUid', true);
            $this->addForeignKey(null, '{{%site7_page_import_sources}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_page_import_sources}}');

        return true;
    }
}
