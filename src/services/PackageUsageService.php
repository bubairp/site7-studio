<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class PackageUsageService extends Component
{
    /**
     * Checks if a package is currently used in any entries.
     * Returns an array of parent entries (the "affected entries") where the package is used.
     * 
     * @param string $handle The package handle
     * @return Entry[] Array of unique parent Entry objects
     */
    public function getUsage(string $handle): array
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            return [];
        }

        $matrixYamlPath = $packagePath . '/matrix.yaml';
        if (!file_exists($matrixYamlPath)) {
            return [];
        }

        $matrixData = Yaml::parseFile($matrixYamlPath);
        if (!isset($matrixData['blocks']) || !is_array($matrixData['blocks'])) {
            return [];
        }

        $entriesService = Craft::$app->getEntries();
        $affectedEntries = [];
        $seenEntryIds = [];

        foreach ($matrixData['blocks'] as $blockDef) {
            $blockHandle = $blockDef['handle'] ?? null;
            if (!$blockHandle) continue;

            $entryType = $entriesService->getEntryTypeByHandle($blockHandle);
            if (!$entryType) continue;

            // Direct DB Query to find Matrix blocks and their owners. Joined against
            // elements to exclude trashed blocks (dateDeleted) and draft-only blocks
            // (canonicalId) - without this, removing a block from a page only ever
            // stopped counting as "usage" once the whole owning entry was deleted,
            // since the trashed block's own row was still sitting in {{%entries}}.
            $blockRows = (new \craft\db\Query())
                ->select(['entries.primaryOwnerId'])
                ->from(['entries' => '{{%entries}}'])
                ->innerJoin('{{%elements}} elements', '[[elements.id]] = [[entries.id]]')
                ->where(['entries.typeId' => $entryType->id])
                ->andWhere(['elements.dateDeleted' => null])
                ->andWhere(['elements.canonicalId' => null])
                ->all();

            foreach ($blockRows as $row) {
                $ownerId = $row['primaryOwnerId'] ?? null;
                if ($ownerId) {
                    $owner = Entry::find()->id($ownerId)->status(null)->one();
                    if ($owner) {
                        if (!isset($seenEntryIds[$owner->id])) {
                            $seenEntryIds[$owner->id] = true;
                            $affectedEntries[] = $owner;
                        }
                    }
                }
            }
        }

        return $affectedEntries;
    }
}
