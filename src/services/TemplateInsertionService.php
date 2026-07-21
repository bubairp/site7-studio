<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
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
}
