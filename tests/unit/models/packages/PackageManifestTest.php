<?php

namespace site7\studio\tests\unit\models\packages;

use Codeception\Test\Unit;
use site7\studio\models\packages\PackageManifest;

class PackageManifestTest extends Unit
{
    protected \UnitTester $tester;

    /**
     * A manifest written before the Website Starter Kit System schema
     * additions existed must still load and validate cleanly, with every
     * new property simply defaulting to empty.
     */
    public function testLoadsLegacyManifestWithoutNewFields()
    {
        $data = [
            'schemaVersion' => '1',
            'type' => 'section',
            'handle' => 'test-hero',
            'name' => 'Test Hero',
            'version' => '1.0.0',
        ];

        $manifest = new PackageManifest($data);

        $this->assertTrue($manifest->validate());
        $this->assertSame([], $manifest->assetVolumes);
        $this->assertSame([], $manifest->categoryGroups);
        $this->assertSame([], $manifest->tagGroups);
        $this->assertSame([], $manifest->craftSections);
        $this->assertSame([], $manifest->navigation);
        $this->assertSame([], $manifest->projectConfigPaths);
    }

    /**
     * Round-trips a manifest carrying every Phase 1 schema addition through
     * json_encode/json_decode and back into a PackageManifest, mirroring how
     * PackageReader loads manifest.json off disk.
     */
    public function testRoundTripsNewSchemaFields()
    {
        $data = [
            'schemaVersion' => '1',
            'type' => 'starter-kit',
            'handle' => 'test-site',
            'name' => 'Test Site',
            'version' => '1.0.0',
            'dependencies' => [
                'sharedResources' => ['shared-hero-image'],
                'pluginDependencies' => [
                    ['handle' => 'seo', 'requiredPlugin' => 'ether/seo'],
                ],
                'plugins' => [
                    ['handle' => 'seo', 'package' => 'ether/seo', 'versionConstraint' => '^4.0'],
                ],
                'npmPackages' => [
                    ['name' => 'tailwindcss', 'version' => '^3.4.0', 'dev' => true],
                ],
                'frontendTooling' => [
                    'system' => 'vite',
                    'configFiles' => ['vite.config.js', 'tailwind.config.js'],
                ],
            ],
            'assetVolumes' => [
                ['handle' => 'images', 'name' => 'Images'],
            ],
            'categoryGroups' => [
                ['handle' => 'blogCategories', 'name' => 'Blog Categories'],
            ],
            'tagGroups' => [
                ['handle' => 'topics', 'name' => 'Topics'],
            ],
            'craftSections' => [
                [
                    'handle' => 'blog',
                    'name' => 'Blog',
                    'type' => 'channel',
                    'propagationMethod' => 'all',
                    'enableVersioning' => true,
                    'maxLevels' => null,
                    'defaultPlacement' => 'end',
                    'siteSettings' => [
                        ['siteHandle' => 'default', 'enabledByDefault' => true, 'hasUrls' => true, 'uriFormat' => 'blog/{slug}', 'template' => 'blog/_entry'],
                    ],
                ],
            ],
            'navigation' => [
                ['label' => 'Home', 'url' => '/'],
            ],
            'projectConfigPaths' => ['sections.*', 'fields.*'],
        ];

        $encoded = json_encode($data);
        $decoded = json_decode($encoded, true);

        $manifest = new PackageManifest($decoded);

        $this->assertTrue($manifest->validate());
        $this->assertSame($data['dependencies'], $manifest->dependencies);
        $this->assertSame($data['assetVolumes'], $manifest->assetVolumes);
        $this->assertSame($data['categoryGroups'], $manifest->categoryGroups);
        $this->assertSame($data['tagGroups'], $manifest->tagGroups);
        $this->assertSame($data['craftSections'], $manifest->craftSections);
        $this->assertSame($data['navigation'], $manifest->navigation);
        $this->assertSame($data['projectConfigPaths'], $manifest->projectConfigPaths);
    }

    /**
     * Step 8.1 - a manifest written before ownedFiles existed must still
     * load with it simply defaulting to empty. Deliberately does not call
     * ->validate() (unlike the two tests above) - that requires a live
     * Yii/Craft app this test suite doesn't have (a pre-existing,
     * unrelated gap - see Step 3's regression notes), and isn't needed to
     * verify the property itself defaults/parses correctly.
     */
    public function testOwnedFilesDefaultsToEmptyArrayOnALegacyManifest()
    {
        $manifest = new PackageManifest([
            'schemaVersion' => '1',
            'type' => 'section',
            'handle' => 'test-hero',
            'name' => 'Test Hero',
            'version' => '1.0.0',
        ]);

        $this->assertSame([], $manifest->ownedFiles);
    }

    /**
     * Round-trips explicit ownedFiles entries through json_encode/decode,
     * mirroring how PackageReader loads manifest.json off disk.
     */
    public function testOwnedFilesRoundTripsThroughJson()
    {
        $data = [
            'schemaVersion' => '1',
            'type' => 'section',
            'handle' => 'cta-banner',
            'name' => 'CTA Banner',
            'version' => '1.0.0',
            'ownedFiles' => [
                ['sourcePath' => 'frontend/src/css/components/ctaBanner.css', 'targetPath' => 'frontend/src/css/components/ctaBanner.css', 'type' => 'frontend-css'],
                ['sourcePath' => 'frontend/src/js/components/ctaBanner.js', 'targetPath' => 'frontend/src/js/components/ctaBanner.js', 'type' => 'frontend-js'],
            ],
        ];

        $manifest = new PackageManifest(json_decode(json_encode($data), true));

        $this->assertSame($data['ownedFiles'], $manifest->ownedFiles);
    }
}
