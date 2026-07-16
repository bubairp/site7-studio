<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260716_100535_create_package_tables migration.
 */
class m260716_100535_create_package_tables extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // 1. site7_packages
        if (!$this->db->tableExists('{{%site7_packages}}')) {
            $this->createTable('{{%site7_packages}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'type' => $this->string()->notNull(),
                'version' => $this->string()->notNull(),
                'status' => $this->string()->notNull()->defaultValue('installed'),
                'description' => $this->text(),
                'author' => $this->string(),
                'requiredCraftVersion' => $this->string(),
                'minimumStudioVersion' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_packages}}', 'handle', true);
            $this->createIndex(null, '{{%site7_packages}}', 'type', false);
            $this->createIndex(null, '{{%site7_packages}}', 'status', false);
        }

        // 2. site7_components
        if (!$this->db->tableExists('{{%site7_components}}')) {
            $this->createTable('{{%site7_components}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'category' => $this->string(),
                'icon' => $this->string(),
                'previewImage' => $this->string(),
                'matrixEntryTypeHandle' => $this->string(),
                'enabled' => $this->boolean()->defaultValue(true),
                'requiredPlan' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->addForeignKey(null, '{{%site7_components}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        // 3. site7_templates
        if (!$this->db->tableExists('{{%site7_templates}}')) {
            $this->createTable('{{%site7_templates}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'previewImage' => $this->string(),
                'templateCategory' => $this->string(),
                'supportedPlans' => $this->string(),
                'homepage' => $this->boolean()->defaultValue(false),
                'enabled' => $this->boolean()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->addForeignKey(null, '{{%site7_templates}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        // 4. site7_package_dependencies
        if (!$this->db->tableExists('{{%site7_package_dependencies}}')) {
            $this->createTable('{{%site7_package_dependencies}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'dependencyType' => $this->string()->notNull(),
                'dependencyHandle' => $this->string()->notNull(),
                'minimumVersion' => $this->string(),
                'optional' => $this->boolean()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->addForeignKey(null, '{{%site7_package_dependencies}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        // 5. site7_package_versions
        if (!$this->db->tableExists('{{%site7_package_versions}}')) {
            $this->createTable('{{%site7_package_versions}}', [
                'id' => $this->primaryKey(),
                'packageId' => $this->integer()->notNull(),
                'version' => $this->string()->notNull(),
                'releaseDate' => $this->dateTime(),
                'releaseNotes' => $this->text(),
                'checksum' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->addForeignKey(null, '{{%site7_package_versions}}', 'packageId', '{{%site7_packages}}', 'id', 'CASCADE', 'CASCADE');
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%site7_package_versions}}');
        $this->dropTableIfExists('{{%site7_package_dependencies}}');
        $this->dropTableIfExists('{{%site7_templates}}');
        $this->dropTableIfExists('{{%site7_components}}');
        $this->dropTableIfExists('{{%site7_packages}}');

        return true;
    }
}
