<?php

namespace site7\studio\models\marketplace;

use craft\base\Model;

/**
 * The result of PackageImportService::validatePackage(): everything the
 * Import tab's Preview step needs, plus enough state (the extracted temp
 * directory) for a subsequent, separate request to actually install it once
 * the user confirms. Held in the CP session between the "Validate" and
 * "Install" steps of Import -> Select -> Validate -> Preview -> Install.
 */
class PackageValidationResult extends Model
{
    /** True only when $errors is empty - Install is only ever offered when this is true. */
    public bool $valid = false;

    /** @var string[] Fatal problems; presence of any means this package must be rejected. */
    public array $errors = [];

    /** @var string[] Non-fatal warnings (schema/Craft version drift, etc.) shown to the user. */
    public array $warnings = [];

    /** @var string[] Handles bundled in this archive that aren't installed locally yet. */
    public array $newPackages = [];

    /** @var string[] Handles that already exist locally with different content (same handle, different checksum). */
    public array $conflicts = [];

    /** @var string[] Handles that already exist locally with identical content - always skipped, never a conflict. */
    public array $alreadyInstalled = [];

    public ?PackageBundleManifest $bundle = null;

    /** Absolute path to the archive's extracted contents on disk, pending install or cancellation. */
    public string $tempDir = '';

    /** The original .s7pkg path this result was validated from (informational only). */
    public string $sourcePath = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['errors', 'warnings', 'newPackages', 'conflicts', 'alreadyInstalled'], 'safe'];
        $rules[] = [['valid'], 'boolean'];
        $rules[] = [['tempDir', 'sourcePath'], 'string'];
        return $rules;
    }
}
