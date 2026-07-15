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
}
