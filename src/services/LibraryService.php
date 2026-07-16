<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\interfaces\LibraryServiceInterface;
use site7\studio\interfaces\LibrarySourceInterface;

class LibraryService extends Component implements LibraryServiceInterface
{
    /** @var LibrarySourceInterface[] */
    private array $sources = [];

    public function registerSource(LibrarySourceInterface $source): void
    {
        $this->sources[$source->getSourceHandle()] = $source;
    }

    /**
     * @return \site7\studio\models\Asset[]
     */
    public function getAllAssets(): array
    {
        $allAssets = [];
        foreach ($this->sources as $source) {
            $allAssets = array_merge($allAssets, $source->getComponents(), $source->getTemplates());
        }
        return $allAssets;
    }

    /**
     * @return \site7\studio\models\Asset[]
     */
    public function getAssetsBySource(string $sourceHandle): array
    {
        if (!isset($this->sources[$sourceHandle])) {
            return [];
        }
        
        $source = $this->sources[$sourceHandle];
        return array_merge($source->getComponents(), $source->getTemplates());
    }
}
