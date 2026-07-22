<?php

namespace site7\studio\models\packages;

use craft\base\Model;

/**
 * Represents the data defined inside a package's manifest.json.
 */
class PackageManifest extends Model
{
    public string $schemaVersion = '1';
    public string $type = 'section';
    public string $handle = '';
    public string $name = '';
    public string $version = '1.0.0';
    public string $author = '';
    public string $description = '';
    public ?string $category = null;
    public array $tags = [];
    public array $compatibility = [];
    public array $dependencies = [];
    public array $requires = [];
    public array $demoContent = [];
    public ?string $preview = null;

    /**
     * The handle of the Entry Type/Section a Template was generated from ("Save as
     * Template"), kept as structural identity only - never a runtime ID. Used to
     * pre-select a matching option in the "Create from Template" wizard when one is
     * still installed; the editor can always choose a different Entry Type instead.
     */
    public ?string $sourceEntryType = null;
    public ?string $sourceSection = null;

    /**
     * The source Entry's own custom field values (i.e. its Section/Entry Type field
     * layout), keyed by field handle - everything except the Site7 Matrix field
     * itself, which is captured separately via demoContent/requires.
     */
    public array $entryFields = [];

    /**
     * Starter Kit only: the pages that make up the captured site, in order.
     * Each entry is {title, slug, sectionHandle, entryTypeHandle, templateHandle} -
     * structural identity plus a reference to the Template package that holds the
     * actual content, per the "never duplicate Templates inside the Starter Kit"
     * rule. Installing a Starter Kit replays this list through the existing
     * Create-from-Template mechanism rather than storing page content twice.
     */
    public array $pages = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion'], 'required'];
        $rules[] = [['type', 'handle', 'name', 'version', 'schemaVersion', 'author', 'description', 'category', 'preview', 'sourceEntryType', 'sourceSection'], 'string'];
        $rules[] = [['compatibility', 'dependencies', 'tags', 'requires', 'demoContent', 'entryFields', 'pages'], 'safe'];
        return $rules;
    }
}
