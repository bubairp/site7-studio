<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260723_110923_create_package_publications_table migration.
 *
 * Adds site7_package_publications for the Package Publishing Platform's
 * "Publish History" - one row per publish attempt, since a single package
 * version can in principle be published to more than one repository target
 * (Local, a future Private/Commerce24/Enterprise repository), which doesn't
 * fit as another column on the existing site7_packages/site7_package_versions
 * tables. This is a pure addition - the package architecture itself (the
 * five tables the original package-tables migration created) is untouched.
 */
class m260723_110923_create_package_publications_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_package_publications}}')) {
            $this->createTable('{{%site7_package_publications}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'packageId' => $this->integer()->notNull(),
                'repositoryHandle' => $this->string()->notNull(),
                'version' => $this->string()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('published'),
                'publishedAt' => $this->dateTime(),
                'releaseNotes' => $this->text(),
                'downloadCount' => $this->integer()->notNull()->defaultValue(0),
                // Digital Signature extension point (Phase 14 explicitly defers
                // implementing cryptography) - nullable, never populated by
                // NullPackageSigner, but a real signer only needs to write here.
                'signature' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_package_publications}}', 'packageId', false);
            $this->createIndex(null, '{{%site7_package_publications}}', 'repositoryHandle', false);
            $this->addForeignKey(
                null,
                '{{%site7_package_publications}}',
                'packageId',
                '{{%site7_packages}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_package_publications}}');

        return true;
    }
}
