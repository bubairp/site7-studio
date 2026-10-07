<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Entries;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\Section;
use site7\studio\models\packages\PackageManifest;
use site7\studio\services\support\AssetCaptureHelper;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class TemplateInsertionService extends Component
{
    /**
     * A Template manifest's `requires.sections` as an ordered list of Section handles, in
     * listed order. No de-duplication - a Section may legitimately appear more than once
     * on a page. `fallbackDemo` is kept (always empty) so callers don't change; it used to
     * carry a Pattern's demo content when Patterns existed.
     *
     * @return array<int, array{handle: string, fallbackDemo: array}>
     */
    public function resolveSectionEntries(PackageManifest $manifest): array
    {
        $entries = [];

        foreach ($manifest->requires['sections'] ?? [] as $sectionHandle) {
            $entries[] = [
                'handle' => $sectionHandle,
                'fallbackDemo' => [],
            ];
        }

        return $entries;
    }

    /**
     * Gets the serialized block data needed to insert a Template into a Matrix field,
     * as {type, typeId, fields} blocks for pattern-matrix.js's createBlocksSequentially().
     */
    public function getTemplateBlocks(string $handle): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $package = $packageManager->getPackageByHandle($handle);

        if (!$package || $package->type !== 'template') {
            return [];
        }

        $manifest = $package->getManifest();
        if (!$manifest) {
            return [];
        }

        $templateDemoContent = $manifest->demoContent ?? [];
        $sectionEntries = $this->resolveSectionEntries($manifest);

        $blocks = [];
        $entriesService = Craft::$app->getEntries();

        foreach ($sectionEntries as $entry) {
            $sectionHandle = $entry['handle'];

            $sectionPackagePath = $packageManager->getPackagePath($sectionHandle);
            if (!$sectionPackagePath) {
                continue;
            }

            $matrixYamlPath = $sectionPackagePath . '/matrix.yaml';
            if (!file_exists($matrixYamlPath)) {
                continue;
            }

            $matrixData = Yaml::parseFile($matrixYamlPath);
            if (!isset($matrixData['blocks']) || !is_array($matrixData['blocks']) || empty($matrixData['blocks'])) {
                continue;
            }

            $entryTypeHandle = $matrixData['blocks'][0]['handle'] ?? null;
            if (!$entryTypeHandle) {
                continue;
            }

            $entryType = $entriesService->getEntryTypeByHandle($entryTypeHandle);
            if (!$entryType) {
                // Not installed/enabled - skip.
                continue;
            }

            $snakeHandle = str_replace('-', '_', $sectionHandle);
            $sectionData = $templateDemoContent[$sectionHandle]
                ?? $templateDemoContent[$snakeHandle]
                ?? $entry['fallbackDemo'][$sectionHandle]
                ?? $entry['fallbackDemo'][$snakeHandle]
                ?? [];

            $blocks[] = [
                'type' => $entryType->handle,
                'typeId' => $entryType->id,
                'fields' => $sectionData,
            ];
        }

        return $blocks;
    }

    /**
     * Lists the Section/Entry Type combinations a Template can be generated into -
     * any Entry Type whose field layout includes the configured Site7 Matrix field.
     *
     * @param string|null $preferredEntryTypeHandle Marks the matching option as
     *   'preferred' - e.g. the Entry Type a Template was originally generated from
     *   (manifest.sourceEntryType), so the "Create from Template" wizard can default
     *   to it. Purely a UI hint; the editor can still pick any eligible option.
     * @return array<int, array{entryTypeId: int, entryTypeHandle: string, entryTypeName: string, sectionId: int, sectionHandle: string, sectionName: string, showSlugField: bool, preferred: bool}>
     */
    public function getEligibleEntryTypes(?string $preferredEntryTypeHandle = null): array
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        if (!$matrixHandle) {
            return [];
        }

        $entriesService = Craft::$app->getEntries();
        $options = [];

        foreach ($entriesService->getAllSections() as $section) {
            // Single sections have exactly one, already-existing Entry -
            // there's no "create a new page" to do there, so they're never
            // an eligible target for this wizard even if their one Entry
            // Type happens to carry the Site7 Matrix field (e.g. a
            // single-entry "Contact" page built the same way a multi-entry
            // "Standard Pages" one is).
            if ($section->type === Section::TYPE_SINGLE) {
                continue;
            }

            foreach ($entriesService->getEntryTypesBySectionId($section->id) as $entryType) {
                $field = $entryType->getFieldLayout()?->getFieldByHandle($matrixHandle);
                if (!$field) {
                    continue;
                }

                $options[] = [
                    'entryTypeId' => $entryType->id,
                    'entryTypeHandle' => $entryType->handle,
                    'entryTypeName' => $entryType->name,
                    'sectionId' => $section->id,
                    'sectionHandle' => $section->handle,
                    'sectionName' => $section->name,
                    'showSlugField' => (bool)$entryType->showSlugField,
                    'preferred' => $preferredEntryTypeHandle !== null && $entryType->handle === $preferredEntryTypeHandle,
                ];
            }
        }

        return $options;
    }

    /**
     * Creates a brand new Entry from a Template package ("Create from Template"), the
     * reverse of TemplateGeneratorService::generateFromEntry(). The entry is created
     * disabled so an editor can review it before publishing, exactly like landing on
     * a manually-created entry's edit screen for the first time.
     *
     * @throws \Exception if the Template, Matrix field, or Entry Type/Section can't be resolved.
     */
    public function createEntryFromTemplate(string $templateHandle, int $entryTypeId, string $title, ?string $slug): Entry
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        if (!$matrixHandle) {
            throw new \Exception('No Site7 Matrix field is configured.');
        }

        $entriesService = Craft::$app->getEntries();
        $entryType = $entriesService->getEntryTypeById($entryTypeId);
        if (!$entryType) {
            throw new \Exception('Entry Type not found.');
        }

        $section = $this->findSectionForEntryType($entryTypeId);
        if (!$section) {
            throw new \Exception('This Entry Type is not attached to a Section.');
        }

        $blocks = $this->getTemplateBlocks($templateHandle);
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($templateHandle);
        $manifest = $package?->getManifest();

        // A Template captured with no Site7 Matrix content at all (e.g. via
        // PageImportService::importNativeContent(), now also reachable from a
        // Starter Kit capture of a page like Home/Contact that has no Site7 blocks
        // of its own - see StarterKitGeneratorService's PAGE_LIKE_SINGLE_SECTIONS and
        // docs/32_STARTER_KIT_SYSTEM.md 14.1.12) has zero blocks by design, not by
        // error - only entryFields carries its content. Only refuse when there's
        // truly nothing to apply at all.
        if (empty($blocks) && empty($manifest?->entryFields)) {
            throw new \Exception('This Template has no content to generate an Entry from.');
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = false;
        if ($slug !== null && $slug !== '' && $entryType->showSlugField) {
            $entry->slug = $slug;
        }

        // From the CP, the user must be allowed to create entries in that section.
        $user = Craft::$app->getUser()->getIdentity();
        if ($user && !Craft::$app->getElements()->canSave($entry, $user)) {
            throw new \Exception("You aren't allowed to create entries in {$section->name}.");
        }

        $this->applyTemplateContent($entry, $matrixHandle, $blocks, $manifest, $templateHandle);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \Exception('Could not create the Entry: ' . implode(' ', $entry->getFirstErrors()));
        }

        return $entry;
    }

    /**
     * Restores a Template's captured content onto an existing, already-loaded Entry
     * in place, rather than creating a new one - used by
     * StarterKitInstallationService::installStarterKit() for a page whose target
     * Section is a Single: Craft never allows a second Entry in a Single section, so
     * a Single-targeted page in manifest.pages is applied to that Single's one
     * existing Entry instead of going through createEntryFromTemplate(). Reuses the
     * exact same content-application logic (applyTemplateContent()) that method
     * uses for a brand new Entry - see its docblock for the matrixValue-shape and
     * Assets-restore caveats.
     *
     * @throws \Exception if the Template or its Matrix content can't be resolved, or
     *   if saving the updated Entry fails.
     */
    public function updateEntryFromTemplate(string $templateHandle, Entry $entry): Entry
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        if (!$matrixHandle) {
            throw new \Exception('No Site7 Matrix field is configured.');
        }

        $blocks = $this->getTemplateBlocks($templateHandle);
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($templateHandle);
        $manifest = $package?->getManifest();

        // See createEntryFromTemplate()'s matching guard - a native-only-captured
        // Template (no Site7 Matrix content) has zero blocks by design; only refuse
        // when there's truly nothing to apply at all.
        if (empty($blocks) && empty($manifest?->entryFields)) {
            throw new \Exception('This Template has no content to update the Entry from.');
        }

        $this->applyTemplateContent($entry, $matrixHandle, $blocks, $manifest, $templateHandle);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \Exception('Could not update the Entry: ' . implode(' ', $entry->getFirstErrors()));
        }

        return $entry;
    }

    /**
     * Shared by createEntryFromTemplate() (brand new Entry) and
     * updateEntryFromTemplate() (existing Entry, Single sections) - sets the Matrix
     * field value from the Template's captured blocks, then restores entryFields
     * (skipping any handle no longer present on the target's field layout).
     *
     * An Assets field's captured value isn't a plain scalar - it's the structured
     * descriptor AssetCaptureHelper::captureAssetField() wrote (filename/volume/
     * folder/alt/title + the file bundled at preview/assets/ inside the package).
     * Restore it into real Asset element(s) on this install (re-using an existing
     * matching Asset by filename+Volume when one's already there, uploading from the
     * package's own bundled copy otherwise) before calling setFieldValue(), rather
     * than passing the raw descriptor through - which would leave the field either
     * empty or containing garbage.
     */
    private function applyTemplateContent(Entry $entry, string $matrixHandle, array $blocks, ?PackageManifest $manifest, string $templateHandle): void
    {
        $matrixValue = [];
        foreach ($blocks as $i => $block) {
            $matrixValue['new' . ($i + 1)] = [
                'type' => $block['type'],
                'fields' => $block['fields'],
            ];
        }
        $entry->setFieldValue($matrixHandle, $matrixValue);

        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($templateHandle);
        $entryFieldLayout = $entry->getFieldLayout();
        foreach ($manifest?->entryFields ?? [] as $fieldHandle => $fieldValue) {
            if (!$entryFieldLayout?->getFieldByHandle($fieldHandle)) {
                continue;
            }
            if (AssetCaptureHelper::isAssetDescriptor($fieldValue)) {
                if ($packagePath) {
                    $assetIds = AssetCaptureHelper::restoreAssetField($fieldValue, $packagePath);
                    if (!empty($assetIds)) {
                        $entry->setFieldValue($fieldHandle, $assetIds);
                    }
                }
                continue;
            }
            $entry->setFieldValue($fieldHandle, $fieldValue);
        }
    }

    private function findSectionForEntryType(int $entryTypeId): ?Section
    {
        $entriesService = Craft::$app->getEntries();
        foreach ($entriesService->getAllSections() as $section) {
            foreach ($entriesService->getEntryTypesBySectionId($section->id) as $entryType) {
                if ($entryType->id === $entryTypeId) {
                    return $section;
                }
            }
        }
        return null;
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }

    public const INSERT_CONTENT = 'content';
    public const INSERT_LAYOUT = 'layout';

    /**
     * Nested Matrix fields that hold a block's design (Block Style, spacing,
     * typography...), kept when a Template inserts its layout only.
     */
    private const DESIGN_FIELD = '/(style|spacing|typography|background|border|divider|settings)$/i';

    /**
     * How the Content Browser's Insert brings a Template (format v2, docs/50)
     * into another page. A general page - a Single, or a top-level page such
     * as /about-us - brings its content. A detail page (blogs/…, products/…)
     * brings its layout and block styles only: its text and images belong
     * to that one page. template.json `insert` ('content'|'layout') overrides.
     */
    public function insertMode(string $handle): string
    {
        return self::modeFor($this->templateMeta($handle));
    }

    /** insertMode() for a template.json's data. */
    public static function modeFor(array $meta): string
    {
        if (in_array($meta['insert'] ?? null, [self::INSERT_CONTENT, self::INSERT_LAYOUT], true)) {
            return $meta['insert'];
        }

        return ($meta['sectionType'] ?? null) === 'single' || !str_contains((string)($meta['uri'] ?? ''), '/')
            ? self::INSERT_CONTENT
            : self::INSERT_LAYOUT;
    }

    /**
     * Inserts a Template into the page being edited: its page's blocks on
     * this site are duplicated into $ownerId's Matrix field the way Craft's
     * own paste does (elements/bulk-duplicate), then rendered by the client
     * through matrix/render-blocks. In layout mode the copies' text, images,
     * links and page relations are cleared; design settings stay.
     *
     * @return array{newElements: array[], mode: string, message: string}
     * @throws \Exception with a message for the editor
     */
    public function insertIntoPage(string $handle, int $ownerId, int $fieldId, int $siteId): array
    {
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$package || $package->type !== 'template') {
            throw new \Exception("'{$handle}' is not a Template package.");
        }
        $meta = $this->templateMeta($handle);
        $source = !empty($meta['entryUid'])
            ? Entry::find()->uid($meta['entryUid'])->siteId($siteId)->status(null)->one()
            : null;
        if (!$source) {
            throw new \Exception("The page '{$package->name}' isn't on this site yet. Install it from Site7 Studio → Library → Templates first.");
        }
        $pageBuilder = Craft::$app->getFields()->getFieldById((int)Site7Studio::getInstance()->getSettings()->matrixFieldId);
        $target = Craft::$app->getFields()->getFieldById($fieldId);
        if (!$pageBuilder instanceof Matrix || !$target instanceof Matrix) {
            throw new \Exception('Templates insert into the page builder field.');
        }
        $owner = Craft::$app->getElements()->getElementById($ownerId, null, $siteId);
        if (!$owner) {
            throw new \Exception('The page being edited was not found.');
        }

        $allowedTypeIds = array_map(fn($entryType) => $entryType->id, $target->getEntryTypes());
        $blocks = $source->getFieldValue($pageBuilder->handle)->status(null)->all();
        $mode = $this->insertMode($handle);
        $elementsService = Craft::$app->getElements();
        $copies = [];
        $skipped = 0;

        Craft::$app->getDb()->transaction(function() use ($blocks, $allowedTypeIds, $owner, $target, $siteId, $mode, $elementsService, &$copies, &$skipped) {
            $elementsService->ensureBulkOp(function() use ($blocks, $allowedTypeIds, $owner, $target, $siteId, $mode, $elementsService, &$copies, &$skipped) {
                foreach ($blocks as $block) {
                    if (!in_array($block->typeId, $allowedTypeIds, true)) {
                        $skipped++;
                        continue;
                    }
                    $attributes = array_intersect_key(
                        ['primaryOwnerId' => $owner->id, 'ownerId' => $owner->id, 'fieldId' => $target->id, 'siteId' => $siteId],
                        array_flip($block->safeAttributes())
                    );
                    $copy = $elementsService->duplicateElement($block, $attributes + $block::baseBulkDuplicateAttributes(), false, checkAuthorization: true);
                    if ($mode === self::INSERT_LAYOUT) {
                        $this->clearContent($copy);
                    }
                    $copies[] = $copy;
                }
            });
        });

        $message = $mode === self::INSERT_CONTENT
            ? 'Template inserted with its content - replace the text and images with your own.'
            : 'Template inserted: its sections and styles, ready for your own text and images.';
        if ($skipped) {
            $message .= " {$skipped} section(s) this field doesn't allow were left out.";
        }

        return [
            'newElements' => array_map(fn($copy) => $copy->toArray($copy->attributes()), $copies),
            'mode' => $mode,
            'message' => $copies ? $message : 'This template has no sections this field can take.',
        ];
    }

    /** template.json of a format v2 Template package; [] otherwise. */
    private function templateMeta(string $handle): array
    {
        $path = Site7Studio::getInstance()->packageManager->getPackagePath($handle);

        return $path ? (json_decode((string)@file_get_contents("{$path}/template.json"), true) ?: []) : [];
    }

    /**
     * Clears a copied block's content - text, images, links, relations to
     * pages - in it and its nested blocks, keeping design fields (dropdowns,
     * toggles, colours, DESIGN_FIELD matrices).
     */
    private function clearContent(ElementInterface $element): void
    {
        $changed = false;
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof Matrix) {
                if (!preg_match(self::DESIGN_FIELD, $field->handle)) {
                    foreach ($element->getFieldValue($field->handle)->status(null)->all() as $nested) {
                        $this->clearContent($nested);
                    }
                }
                continue;
            }
            if ($field instanceof PlainText || $field instanceof Assets || $field instanceof Link
                || is_a($field, 'craft\ckeditor\Field')
                || ($field instanceof Entries && $this->relatesToPages($field))) {
                // Relation fields read null as "not set, keep what's stored".
                $element->setFieldValue($field->handle, $field instanceof Assets || $field instanceof Entries ? [] : null);
                $changed = true;
            }
        }
        if ($changed) {
            Craft::$app->getElements()->saveElement($element, false);
        }
    }

    /** Whether an Entries field can relate to pages (entries with URLs), not to libraries like colours or fonts. */
    private function relatesToPages(Entries $field): bool
    {
        if ($field->sources === '*' || $field->sources === null) {
            return true;
        }
        foreach ((array)$field->sources as $source) {
            if ($source === 'singles') {
                return true;
            }
            if (str_starts_with((string)$source, 'section:')) {
                $section = Craft::$app->getEntries()->getSectionByUid(substr($source, 8));
                foreach ($section?->getSiteSettings() ?? [] as $siteSettings) {
                    if ($siteSettings->hasUrls) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
