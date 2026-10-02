<?php

namespace site7\studio\migrations;

use Craft;
use craft\db\Migration;

/**
 * m261002_000000_store_matrix_field_uid migration.
 *
 * The plugin's settings used to store the Site7 Components field as
 * matrixFieldId. Project config is shared between environments but field
 * IDs aren't, so on any other environment that ID pointed at the wrong
 * field or none. Settings now store matrixFieldUid; this converts an
 * existing matrixFieldId once.
 *
 * Only the environment that authors project config converts it: when the
 * incoming YAML is already at schema 1.0.4 it carries the UID, and the
 * migration leaves project config alone.
 */
class m261002_000000_store_matrix_field_uid extends Migration
{
    private const SETTINGS_PATH = 'plugins.site7-studio.settings';

    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.site7-studio.schemaVersion', true);
        if ($schemaVersion !== null && version_compare($schemaVersion, '1.0.4', '>=')) {
            return true;
        }

        $settings = $projectConfig->get(self::SETTINGS_PATH) ?? [];
        if (!array_key_exists('matrixFieldId', $settings)) {
            return true;
        }

        if (!empty($settings['matrixFieldId']) && empty($settings['matrixFieldUid'])) {
            $field = Craft::$app->getFields()->getFieldById((int)$settings['matrixFieldId']);
            $settings['matrixFieldUid'] = $field?->uid;
        }
        unset($settings['matrixFieldId']);

        $projectConfig->set(self::SETTINGS_PATH, $settings, 'Store the Site7 Components field by UID');

        return true;
    }

    public function safeDown(): bool
    {
        // Settings no longer read matrixFieldId; nothing to restore.
        return true;
    }
}
