<?php

namespace site7\studio\tests\unit\services\installation;

use PHPUnit\Framework\TestCase;
use site7\studio\services\installation\StarterKitCatalogService;

/**
 * Regression coverage for S7V-002: the Install Wizard's catalog
 * (StarterKitCatalogService::listAvailable(), via describe()) discovered
 * "First Kit" but rejected it as not installable, because it was built by
 * the older, separate "Save Current Site as Starter Kit" flow
 * (StarterKitGeneratorService), which never writes blueprint.json - only
 * StarterKitBuilder -> BlueprintBuilder (the Build->Install->Sync
 * pipeline, docs/32_STARTER_KIT_SYSTEM.md §4-§13) does. That's the correct,
 * intended behavior, not a bug: a package without a valid blueprint.json
 * genuinely cannot be installed by this wizard. The actual fix was
 * producing a real pipeline-built kit (`php craft
 * site7-studio/make/starter-kit`) - see S7V-002 fix report.
 *
 * This suite tests isInstallable()/installabilityNotes() - the pure
 * decision logic describe() delegates to - directly and without a live
 * Craft app/DB (per tests/unit.suite.yml's established convention),
 * covering exactly the cases the coordinator's brief called out:
 * a package with no blueprint.json is rejected, a package whose blueprint
 * recorded a validation failure is rejected, and a package with a valid
 * blueprint is accepted.
 */
class StarterKitCatalogServiceTest extends TestCase
{
    public function testPackageWithNoBlueprintIsNotInstallable(): void
    {
        $this->assertFalse(StarterKitCatalogService::isInstallable(null));
    }

    public function testPackageWithNoBlueprintExplainsWhy(): void
    {
        $notes = StarterKitCatalogService::installabilityNotes(null);

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('No blueprint.json found', $notes[0]);
        $this->assertStringContainsString('Starter Kit pipeline', $notes[0]);
    }

    public function testPackageWithFailedValidationIsNotInstallable(): void
    {
        $blueprint = [
            'validation' => [
                'valid' => false,
                'errors' => ['Missing required field "handle" on section "home"'],
            ],
        ];

        $this->assertFalse(StarterKitCatalogService::isInstallable($blueprint));
    }

    public function testPackageWithFailedValidationExplainsWhy(): void
    {
        $blueprint = [
            'validation' => [
                'valid' => false,
                'errors' => ['Missing required field "handle" on section "home"'],
            ],
        ];

        $notes = StarterKitCatalogService::installabilityNotes($blueprint);

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('failed validation', $notes[0]);
        $this->assertStringContainsString('Missing required field "handle" on section "home"', $notes[0]);
    }

    public function testPackageWithValidBlueprintIsInstallable(): void
    {
        $blueprint = [
            'schemaVersion' => '1',
            'packageHandle' => 'demo-fresh-site',
            'validation' => ['valid' => true, 'errors' => [], 'warnings' => []],
        ];

        $this->assertTrue(StarterKitCatalogService::isInstallable($blueprint));
        $this->assertSame([], StarterKitCatalogService::installabilityNotes($blueprint));
    }

    /**
     * A blueprint with no 'validation' key at all (defensive - every
     * real BlueprintBuilder output has one, see §3) must not be treated
     * as invalid just because the key is absent; only an explicit
     * `valid: false` should reject it.
     */
    public function testPackageWithNoValidationKeyDefaultsToInstallable(): void
    {
        $blueprint = ['schemaVersion' => '1', 'packageHandle' => 'x'];

        $this->assertTrue(StarterKitCatalogService::isInstallable($blueprint));
        $this->assertSame([], StarterKitCatalogService::installabilityNotes($blueprint));
    }

    /**
     * Discovery-level regression check against the real artifacts this
     * fix concerns, without needing a live Craft app: "First Kit" (built
     * by the older flow) genuinely has no blueprint.json on disk, and the
     * newly pipeline-built "Demo Fresh Site" genuinely does and is valid.
     * If either fixture changes shape, this test - not just live
     * verification - will catch it.
     */
    public function testRealFirstKitFixtureHasNoBlueprintOnDisk(): void
    {
        $packagesDir = dirname((new \ReflectionClass(StarterKitCatalogService::class))->getFileName(), 4) . '/packages';

        if (!is_file($packagesDir . '/first-kit/manifest.json')) {
            $this->markTestSkipped('packages/first-kit not present in this checkout.');
        }

        $this->assertFileDoesNotExist($packagesDir . '/first-kit/blueprint.json');
        $this->assertFileExists($packagesDir . '/first-kit/manifest.json');
    }

    public function testRealDemoFreshSiteFixtureHasAValidBlueprintOnDisk(): void
    {
        $packagesDir = dirname((new \ReflectionClass(StarterKitCatalogService::class))->getFileName(), 4) . '/packages';
        $blueprintPath = $packagesDir . '/demo-fresh-site/blueprint.json';

        if (!is_file($blueprintPath)) {
            $this->markTestSkipped('packages/demo-fresh-site/blueprint.json not present in this checkout.');
        }

        $blueprint = json_decode((string)file_get_contents($blueprintPath), true);

        $this->assertIsArray($blueprint);
        $this->assertTrue(StarterKitCatalogService::isInstallable($blueprint));
        $this->assertArrayHasKey('resources', $blueprint);
        $this->assertArrayHasKey('installationOrder', $blueprint);
    }
}
