<?php

namespace site7\studio\tests\unit\services\design;

use Codeception\Test\Unit;
use site7\studio\services\design\DesignLoader;

class DesignLoaderTest extends Unit
{
    protected \UnitTester $tester;

    protected function _before(): void
    {
        // Craft's Security service signs with the app's key; Yii's base class with a fixed one.
        \Craft::$app = new class {
            public function getSecurity(): object
            {
                return new class {
                    private \yii\base\Security $security;
                    public function __construct() { $this->security = new \yii\base\Security(); }
                    public function hashData(string $data): string { return $this->security->hashData($data, 'test-key'); }
                    public function validateData(string $data): string|false { return $this->security->validateData($data, 'test-key'); }
                };
            }
        };
    }

    protected function _after(): void
    {
        \Craft::$app = null;
    }

    public function testTheSettingPicksTheDesign(): void
    {
        $this->assertSame(['canopy', false], DesignLoader::resolve(null, null, false, 'canopy'));
        $this->assertSame([null, false], DesignLoader::resolve(null, null, false, null), 'no setting: the default design');
        $this->assertSame([null, false], DesignLoader::resolve(null, null, false, 'default'), '"default" is the site\'s own templates');
    }

    public function testQueryPreviewOnlyInDevMode(): void
    {
        $this->assertSame(['canopy', true], DesignLoader::resolve(null, 'canopy', true, null));
        $this->assertSame([null, false], DesignLoader::resolve(null, 'canopy', false, null), 'ignored outside Dev Mode');
        $this->assertSame(['canopy', true], DesignLoader::resolve(null, 'canopy', true, 'other'), 'preview wins over the setting');
        $this->assertSame([null, true], DesignLoader::resolve(null, 'default', true, 'harbor'), '?design=default previews the default over an active design');
        $this->assertSame(['harbor', false], DesignLoader::resolve(null, 'default', false, 'harbor'), '...only in Dev Mode');
    }

    /** Harbor showed the payment plugin's bare pages instead of the site's own templates/payment-gateway/ */
    public function testTheSitesOwnTemplatesWinOverAPluginRootWhileADesignIsActive(): void
    {
        $base = sys_get_temp_dir() . '/s7-roots-' . uniqid();
        mkdir("{$base}/payment-gateway", 0777, true);

        $roots = DesignLoader::siteRootsFirst(['payment-gateway' => '/plugins/pay/templates', 'other-plugin' => ['/plugins/other']], $base);

        $this->assertSame(["{$base}/payment-gateway", '/plugins/pay/templates'], $roots['payment-gateway'], 'the site folder goes first');
        $this->assertSame(['/plugins/other'], $roots['other-plugin'], 'no site folder: the plugin root is untouched');
        $this->assertSame([$base], $roots[''], 'the site templates are the catch-all root');
        rmdir("{$base}/payment-gateway");
        rmdir($base);
    }

    public function testASignedPreviewWinsEverywhere(): void
    {
        $this->assertSame(['canopy', true], DesignLoader::resolve('canopy', 'other', true, 'third'));
        $this->assertSame(['canopy', true], DesignLoader::resolve('canopy', null, false, null), 'works without Dev Mode');
    }

    public function testOnlyPlainHandlesAreDesigns(): void
    {
        foreach (['../etc', 'Canopy', '-x', 'a/b', 'a b', '', str_repeat('a', 65), ['canopy'], 7] as $bad) {
            $this->assertNull(DesignLoader::validHandle($bad), json_encode($bad));
        }
        $this->assertSame('design-2', DesignLoader::validHandle('design-2'));
    }

    public function testPreviewTokenRoundTripAndExpiry(): void
    {
        $url = DesignLoader::previewUrl('canopy', 60, 'https://example.test/about');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query[DesignLoader::PREVIEW_PARAM];

        $this->assertSame('canopy', DesignLoader::previewHandle($token));
        $this->assertNull(DesignLoader::previewHandle($token, time() + 61), 'expired');
        $this->assertNull(DesignLoader::previewHandle(substr($token, 0, -2) . 'xx'), 'tampered');
        $this->assertNull(DesignLoader::previewHandle(null));
    }
}
