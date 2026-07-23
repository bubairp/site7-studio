<?php

namespace site7\studio\models\publishing;

use craft\base\Model;

/**
 * The result of PackageValidatorInterface::validate() - the Publish wizard's
 * "Package Validation" + "Package Quality" steps in one pass. Distinct from
 * the Package Engine's own (frozen) site7\studio\services\engine\PackageValidator,
 * which only gates install/discovery and is deliberately left untouched -
 * this is a new, stricter, publish-only readiness check layered on top.
 */
class PublishReadinessResult extends Model
{
    /** True only when $errors is empty - Publish is only ever offered when this is true. */
    public bool $valid = false;

    /** @var string[] Fatal problems that block publishing outright. */
    public array $errors = [];

    /** @var string[] Non-fatal issues shown to the user but that don't block publishing. */
    public array $warnings = [];

    /**
     * 0-100 "publishing score" - the Package Quality section's percentage,
     * derived from how many of the quality checks passed (required docs,
     * required preview, required metadata, required assets, naming,
     * semantic versioning, compatibility) rather than from errors/warnings
     * directly, so a package can still be "valid" (no errors) while scoring
     * below 100 on quality/completeness.
     */
    public int $score = 0;

    /** A short human label for $score, e.g. "Ready for Marketplace". */
    public string $label = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['valid'], 'boolean'];
        $rules[] = [['score'], 'integer', 'min' => 0, 'max' => 100];
        $rules[] = [['label'], 'string'];
        $rules[] = [['errors', 'warnings'], 'safe'];
        return $rules;
    }
}
