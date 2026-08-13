<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260802_000000_create_website_import_sources_table migration.
 *
 * Phase 9.3 - "Import Existing Website" duplicate-detection/update-tracking,
 * mirroring m260731_150000_create_section_import_sources_table.php /
 * m260801_000000_create_page_import_sources_table.php. A website has no
 * single source uid, so identity here is a `selectionKey` (sha256 of the
 * sorted list of captured Entry uids) rather than a single uid column - see
 * WebsiteImportSourceRepository.
 */
class m260802_000000_create_website_import_sources_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_website_import_sources}}')) {
            $this->createTable('{{%site7_website_import_sources}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'selectionKey' => $this->string()->notNull(),
                'sourceEntryUids' => $this->text()->notNull(),
                'sourceHash' => $this->string()->notNull(),
                'importedAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_website_import_sources}}', 'packageId', true);
            $this->createIndex(null, '{{%site7_website_import_sources}}', 'selectionKey', true);
            $this->addForeignKey(null, '{{%site7_website_import_sources}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_website_import_sources}}');

        return true;
    }
}
