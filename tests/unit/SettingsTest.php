<?php

namespace site7\studio\tests\unit;

use PHPUnit\Framework\TestCase;
use site7\studio\models\Settings;

class SettingsTest extends TestCase
{
    public function testSettingsModelInstantiation(): void
    {
        $settings = new Settings();
        
        // Assert that the settings class is a valid Craft model
        $this->assertInstanceOf(\craft\base\Model::class, $settings);
    }

    public function testSettingsValidationRules(): void
    {
        $settings = new Settings();

        // At this phase, there are no custom properties to validate,
        // so validate() should natively return true.
        $this->assertTrue($settings->validate());
    }

    /**
     * Only the field UID may reach project config - an ID differs per
     * environment. Craft persists exactly getAttributes().
     */
    public function testMatrixFieldIsPersistedByUidNotId(): void
    {
        $attributes = (new Settings())->getAttributes();

        $this->assertArrayHasKey('matrixFieldUid', $attributes);
        $this->assertArrayNotHasKey('matrixFieldId', $attributes);
    }

    public function testMatrixFieldIdIsNullWithoutAUid(): void
    {
        $this->assertNull((new Settings())->matrixFieldId);
    }

    /**
     * The Settings screen edits only what the config file doesn't set
     * (saving those has no effect).
     */
    public function testEditableKeysLeaveOutConfigFileOverrides(): void
    {
        $keys = ['commerceApiEndpoint', 'commerceApiKey', 'commerceStoreIdentifier', 'defaultPackageAuthor'];

        $this->assertSame(
            ['commerceStoreIdentifier', 'defaultPackageAuthor'],
            Settings::editableKeys($keys, ['commerceApiEndpoint', 'commerceApiKey', 'trustedSigningKeys'], true)
        );
        $this->assertSame($keys, Settings::editableKeys($keys, [], true));
    }

    /**
     * Without allowAdminChanges nothing is editable: saving writes project
     * config, which such environments take from git only.
     */
    public function testNothingIsEditableWithoutAdminChanges(): void
    {
        $this->assertSame([], Settings::editableKeys(['commerceStoreIdentifier', 'defaultPackageAuthor'], [], false));
    }
}
