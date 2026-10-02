<?php

namespace site7\studio\migrations;

use craft\db\Migration;

/**
 * m261002_100000_add_package_signature_columns migration.
 *
 * Records what PackageImportService found when a package arrived in a
 * .s7pkg (see docs/47_PACKAGE_SIGNING.md):
 * - signatureStatus: verified / unsigned; null for packages that never came
 *   through an archive (authored here, or found on disk)
 * - signatureKeyId: the trusted key that signed it
 * - verifiedPricingType: the manifest's pricingType as signed. The licence
 *   gate prefers it over manifest.json on disk, which can be edited after
 *   import.
 */
class m261002_100000_add_package_signature_columns extends Migration
{
    private const COLUMNS = ['signatureStatus', 'signatureKeyId', 'verifiedPricingType'];

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%site7_packages}}')) {
            return true;
        }

        foreach (self::COLUMNS as $column) {
            if (!$this->db->columnExists('{{%site7_packages}}', $column)) {
                $this->addColumn('{{%site7_packages}}', $column, $this->string()->null());
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->tableExists('{{%site7_packages}}')) {
            return true;
        }

        foreach (self::COLUMNS as $column) {
            if ($this->db->columnExists('{{%site7_packages}}', $column)) {
                $this->dropColumn('{{%site7_packages}}', $column);
            }
        }

        return true;
    }
}
