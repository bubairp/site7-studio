<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m260728_120000_add_entitlement_removable_on migration.
 *
 * Moves "which installed packages a plan downgrade disabled, and the date
 * they become eligible for permanent removal" off of Craft's general data
 * cache (PackageService::PENDING_DELETIONS_CACHE_KEY, stored with duration 0
 * meaning "never expires" - but that's a promise the cache component itself
 * doesn't keep) and onto this durable column instead. A site admin running
 * the CP's own "Clear Caches -> Data Caches" utility - a routine,
 * expected-to-be-harmless action - was silently wiping this bookkeeping,
 * leaving already-disabled packages with no path back to being tracked for
 * removal short of manually re-enabling and re-disabling them.
 */
class m260728_120000_add_entitlement_removable_on extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%site7_packages}}', 'entitlementRemovableOn')) {
            $this->addColumn(
                '{{%site7_packages}}',
                'entitlementRemovableOn',
                $this->dateTime()->null()->after('authoringStatus')
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%site7_packages}}', 'entitlementRemovableOn')) {
            $this->dropColumn('{{%site7_packages}}', 'entitlementRemovableOn');
        }

        return true;
    }
}
