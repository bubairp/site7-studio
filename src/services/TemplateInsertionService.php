<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\models\Section;
use site7\studio\models\packages\PackageManifest;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class TemplateInsertionService extends Component
{
    /**
     * Flattens a Template manifest's `requires.patterns` and `requires.sections` into an
     * ordered list of Section handles, each carrying the demo content of the Pattern it was
     * expanded from (if any) as a fallback. Each `requires.patterns` entry expands to that
     * Pattern's own `requires.sections`, in the Pattern's order; `requires.sections` entries
     * are appended directly afterward, in listed order. No de-duplication - a Section may
     * legitimately appear more than once on a page.
     *
     * @return array<int, array{handle: string, fallbackDemo: array}>
     */
    public function resolveSectionEntries(PackageManifest $manifest): array
    {
        $entries = [];
        $packageManager = Site7Studio::getInstance()->packageManager;

        foreach ($manifest->requires['patterns'] ?? [] as $patternHandle) {
            $patternRecord = $packageManager->getPackageByHandle($patternHandle);
            $patternManifest = $patternRecord?->getManifest();
            if (!$patternManifest) {
                continue;
            }

            $patternDemoContent = $patternManifest->demoContent ?? [];
            foreach ($patternManifest->requires['sections'] ?? [] as $sectionHandle) {
                $entries[] = [
                    'handle' => $sectionHandle,
                    'fallbackDemo' => $patternDemoContent,
                ];
            }
        }

        foreach ($manifest->requires['sections'] ?? [] as $sectionHandle) {
            $entries[] = [
                'handle' => $sectionHandle,
                'fallbackDemo' => [],
            ];
        }

        return $entries;
    }

    /**
     * Gets the serialized block data needed to insert a Template into a Matrix field.
     * Returns the same flat shape as PatternInsertionService::getPatternBlocks() so the
     * frontend's block-creation logic can be reused unchanged.
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
                // Not installed/enabled - skip, same convention as PatternInsertionService.
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
     * @return array<int, array{entryTypeId: int, entryTypeName: string, sectionId: int, sectionName: string, showSlugField: bool, preferred: bool}>
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
            foreach ($entriesService->getEntryTypesBySectionId($section->id) as $entryType) {
                $field = $entryType->getFieldLayout()?->getFieldByHandle($matrixHandle);
                if (!$field) {
                    continue;
                }

                $options[] = [
                    'entryTypeId' => $entryType->id,
                    'entryTypeName' => $entryType->name,
                    'sectionId' => $section->id,
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
        if (empty($blocks)) {
            throw new \Exception('This Template has no content to generate an Entry from.');
        }

        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($templateHandle);
        $manifest = $package?->getManifest();

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = false;
        if ($slug !== null && $slug !== '' && $entryType->showSlugField) {
            $entry->slug = $slug;
        }

        $matrixValue = [];
        foreach ($blocks as $i => $block) {
            $matrixValue['new' . ($i + 1)] = [
                'type' => $block['type'],
                'fields' => $block['fields'],
            ];
        }
        $entry->setFieldValue($matrixHandle, $matrixValue);

        // Restore the source Entry's own captured custom field values (e.g. Theme,
        // Header Style - anything besides the Matrix field), skipping any handle no
        // longer present on the target Entry Type's field layout.
        $entryFieldLayout = $entryType->getFieldLayout();
        foreach ($manifest?->entryFields ?? [] as $fieldHandle => $fieldValue) {
            if ($entryFieldLayout?->getFieldByHandle($fieldHandle)) {
                $entry->setFieldValue($fieldHandle, $fieldValue);
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new \Exception('Could not create the Entry: ' . implode(' ', $entry->getFirstErrors()));
        }

        return $entry;
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
}
