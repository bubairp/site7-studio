<?php

namespace site7\studio\tests\unit\services\import;

use Codeception\Test\Unit;
use site7\studio\services\import\ResourceClassifierService;
use site7\studio\services\import\ResourceImportValidator;

/**
 * Covers validate()'s pure logic checks. The duplicate-handle check (and
 * generateUniqueHandle()) touch Craft::getAlias('@packages'), which needs a
 * bootstrapped Craft application - out of scope for this plugin's existing
 * PHPUnit setup (see tests/bootstrap.php, which only autoloads Composer).
 * Every other new service in this feature is a thin wrapper around live
 * Craft::$app calls for the same reason and has no equivalent unit test,
 * consistent with TemplateGeneratorService/StarterKitGeneratorService/
 * PackageAuthoringService, none of which are unit-tested in this repo either.
 */
class ResourceImportValidatorTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;

    private ResourceImportValidator $validator;

    protected function _before()
    {
        $this->validator = new ResourceImportValidator();
    }

    /**
     * validateImport() reads each field's classification, so run fields
     * through the real classifier first, as every import flow does.
     */
    private function classified(array $fields): array
    {
        $classifier = new ResourceClassifierService();
        return array_map(fn(array $field) => $classifier->classifyField($field, ['fanOut' => 1]), $fields);
    }

    public function testFlagsUnsupportedFieldsAsWarnings()
    {
        $result = $this->validator->validateImport('matrix-entry-type', [
            'detectedFields' => $this->classified([
                ['handle' => 'heading', 'name' => 'Heading', 'type' => 'PlainText', 'supported' => true, 'fieldClass' => \craft\fields\PlainText::class],
                ['handle' => 'ranking', 'name' => 'Ranking', 'type' => 'SomeExoticType', 'supported' => false, 'fieldClass' => \craft\fields\PlainText::class],
            ]),
            'hasCapturableContent' => true,
        ]);

        $this->assertEmpty($result['errors']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('ranking', $result['warnings'][0]);
        $this->assertStringContainsString('Review Required', $result['warnings'][0]);
    }

    public function testFlagsAssetsFieldsAsWarning()
    {
        $result = $this->validator->validateImport('page', [
            'detectedFields' => $this->classified([
                ['handle' => 'heroImage', 'name' => 'Hero Image', 'type' => 'Assets', 'supported' => true, 'fieldClass' => \craft\fields\Assets::class],
            ]),
            'hasCapturableContent' => true,
        ]);

        $this->assertStringContainsString('heroImage', implode(' ', $result['warnings']));
        $this->assertStringContainsString('Assets', implode(' ', $result['warnings']));
    }

    public function testNoCapturableContentIsAnError()
    {
        $result = $this->validator->validateImport('page', [
            'hasCapturableContent' => false,
        ]);

        $this->assertNotEmpty($result['errors']);
    }

    public function testHasCapturableContentProducesNoError()
    {
        $result = $this->validator->validateImport('page', [
            'hasCapturableContent' => true,
        ]);

        $this->assertEmpty($result['errors']);
    }

    public function testMissingDependencyIsAWarningNotAnError()
    {
        $result = $this->validator->validateImport('website', [
            'hasCapturableContent' => true,
            'dependencies' => [
                ['kind' => 'Section', 'handle' => 'hero', 'status' => 'missing'],
                ['kind' => 'Category Group', 'handle' => 'topics', 'status' => 'not-packaged'],
            ],
        ]);

        $this->assertEmpty($result['errors']);
        $this->assertCount(2, $result['warnings']);
    }

    public function testInvalidHandleSyntaxProducesWarning()
    {
        $result = $this->validator->validateImport('matrix-entry-type', [
            'detectedFields' => [
                ['handle' => '9bad-handle', 'type' => 'PlainText', 'supported' => true],
            ],
            'hasCapturableContent' => true,
        ]);

        $this->assertNotEmpty($result['warnings']);
    }

    public function testNonSemverVersionProducesWarning()
    {
        $result = $this->validator->validateImport('page', [
            'hasCapturableContent' => true,
            'version' => 'v1',
        ]);

        $this->assertStringContainsString('version', strtolower(implode(' ', $result['warnings'])));
    }

    public function testSemverVersionProducesNoVersionWarning()
    {
        $result = $this->validator->validateImport('page', [
            'hasCapturableContent' => true,
            'version' => '2.1.0',
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->assertStringNotContainsString('semantic version', $warning);
        }
    }
}
