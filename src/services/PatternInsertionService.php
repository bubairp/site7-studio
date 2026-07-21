<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class PatternInsertionService extends Component
{
    /**
     * Gets the serialized block data needed to insert a pattern into a Matrix field.
     * This prepares the structure expected by Matrix for new blocks based on the pattern's manifest demo content.
     */
    public function getPatternBlocks(string $handle): array
    {
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        
        if (!$package || $package->type !== 'pattern') {
            return [];
        }

        $manifest = $package->getManifest();
        if (!$manifest || empty($manifest->requires['sections'])) {
            return [];
        }

        $blocks = [];
        $demoContent = $manifest->demoContent ?? [];
        
        $entriesService = Craft::$app->getEntries();

        foreach ($manifest->requires['sections'] as $sectionHandle) {
            // A section handle translates to the Matrix block Entry Type handle
            // However, the section handle is e.g. "hero-banner" but the entry type handle is usually camelCase.
            // Let's get the section package and check its matrix.yaml to find the exact handle.
            $sectionPackagePath = Site7Studio::getInstance()->packageManager->getPackagePath($sectionHandle);
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
            
            $blockDef = $matrixData['blocks'][0]; // Assume first block is the primary one
            $entryTypeHandle = $blockDef['handle'] ?? null;
            if (!$entryTypeHandle) {
                continue;
            }

            $entryType = $entriesService->getEntryTypeByHandle($entryTypeHandle);
            if (!$entryType) {
                // If it's not installed/enabled, we can't insert it.
                continue;
            }

            // Find demo content for this section if provided in pattern
            $sectionData = $demoContent[$sectionHandle] ?? $demoContent[str_replace('-', '_', $sectionHandle)] ?? [];
            
            // To emulate how Craft creates new Matrix blocks in CP:
            $blocks[] = [
                'type' => $entryType->handle,
                'typeId' => $entryType->id,
                'fields' => $sectionData,
            ];
        }

        return $blocks;
    }
}
