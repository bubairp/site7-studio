<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260724_130000_create_shared_resources_tables migration.
 *
 * Adds the Shared Resource Registry (Phase 16): site7_shared_resources (one
 * row per Craft resource intentionally reused across many packages - a
 * Matrix field like blockStyle, a shared field like button, a Volume,
 * Category/Tag Group, Global Set, or Navigation field) and
 * site7_shared_resource_dependencies (Shared -> Shared forward edges, e.g.
 * blockStyle depending on button), mirroring site7_package_dependencies'
 * shape for a different entity. "Referenced By"/usage count for
 * Package -> Shared edges reuses the existing site7_package_dependencies
 * table (dependencyType = 'sharedResource') rather than a new table - see
 * MarketplaceService::syncDependencyRecords()'s existing forward-edge
 * pattern. Pure addition - the original five package tables are untouched.
 */
class m260724_130000_create_shared_resources_tables extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_shared_resources}}')) {
            $this->createTable('{{%site7_shared_resources}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'handle' => $this->string()->notNull(),
                'name' => $this->string()->notNull(),
                'type' => $this->string()->notNull(),
                'craftUid' => $this->string(),
                'craftId' => $this->integer(),
                'version' => $this->string()->notNull()->defaultValue('1.0.0'),
                'installStatus' => $this->string()->notNull()->defaultValue('registered'),
                'definitionSnapshot' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_shared_resources}}', 'handle', true);
            $this->createIndex(null, '{{%site7_shared_resources}}', 'type', false);
        }

        if (!$this->db->tableExists('{{%site7_shared_resource_dependencies}}')) {
            $this->createTable('{{%site7_shared_resource_dependencies}}', [
                'id' => $this->primaryKey(),
                'sharedResourceId' => $this->integer()->notNull(),
                'dependsOnHandle' => $this->string()->notNull(),
                'dependencyType' => $this->string()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%site7_shared_resource_dependencies}}', 'sharedResourceId', false);
            $this->addForeignKey(
                null,
                '{{%site7_shared_resource_dependencies}}',
                'sharedResourceId',
                '{{%site7_shared_resources}}',
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
        $this->dropTableIfExists('{{%site7_shared_resource_dependencies}}');
        $this->dropTableIfExists('{{%site7_shared_resources}}');

        return true;
    }
}
