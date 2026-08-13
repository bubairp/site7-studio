<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260730_123644_add_package_category_tags migration.
 *
 * `PackageRepository::save()` and `PackageAuthoringService` have always set
 * `category`/`tags` on `PackageRecord` (and `PublishValidatorService` reads
 * `category` back for publish-readiness checks), but no migration ever
 * added the matching columns to `site7_packages` - they only ever existed
 * on already-running installs because they were added to the database by
 * hand at some point. A fresh install has never had these columns, so
 * `discoverPackages()` (called on every Library page load) throws
 * `UnknownPropertyException: Setting unknown property: ...PackageRecord::category`
 * immediately.
 */
class m260730_123644_add_package_category_tags extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%site7_packages}}', 'category')) {
            $this->addColumn('{{%site7_packages}}', 'category', $this->string()->null());
        }

        if (!$this->db->columnExists('{{%site7_packages}}', 'tags')) {
            $this->addColumn('{{%site7_packages}}', 'tags', $this->text()->null());
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%site7_packages}}', 'tags')) {
            $this->dropColumn('{{%site7_packages}}', 'tags');
        }

        if ($this->db->columnExists('{{%site7_packages}}', 'category')) {
            $this->dropColumn('{{%site7_packages}}', 'category');
        }

        return true;
    }
}
