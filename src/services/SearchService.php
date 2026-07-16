<?php

namespace site7\studio\services;

use craft\base\Component;
use site7\studio\Site7Studio;

use site7\studio\interfaces\SearchServiceInterface;
use site7\studio\interfaces\LibraryServiceInterface;

class SearchService extends Component implements SearchServiceInterface
{
    /**
     * Searches for assets across all sources based on query and category.
     *
     * @param string $query
     * @param string $category
     * @return array
     */
    public function search(string $query = '', string $category = ''): array
    {
        /** @var LibraryServiceInterface $library */
        $library = Site7Studio::getInstance()->get('libraryService');
        
        if (!$library) {
            return [];
        }
        
        $assets = $library->getAllAssets();
        
        return $this->filterAssets($assets, $query, $category);
    }
    
    /**
     * Filters an array of assets based on query and category.
     *
     * @param array $assets
     * @param string $query
     * @param string $category
     * @return array
     */
    public function filterAssets(array $assets, string $query = '', string $category = ''): array
    {
        $results = [];
        
        $query = mb_strtolower(trim($query));
        $category = mb_strtolower(trim($category));
        
        foreach ($assets as $asset) {
            $match = true;
            
            // For now, asset properties might be accessed differently depending on if it's an object or array.
            // BuiltInLibrarySource returns Asset objects.
            $isObject = is_object($asset);
            
            // Filter by category if provided
            if ($category !== '') {
                $compCategory = mb_strtolower($isObject ? ($asset->category ?? '') : ($asset['category'] ?? ''));
                if ($compCategory !== $category) {
                    $match = false;
                }
            }
            
            // Filter by search query if provided
            if ($match && $query !== '') {
                $title = mb_strtolower($isObject ? ($asset->name ?? '') : ($asset['title'] ?? $asset['name'] ?? ''));
                $description = mb_strtolower($isObject ? ($asset->description ?? '') : ($asset['description'] ?? ''));
                
                if (mb_strpos($title, $query) === false && mb_strpos($description, $query) === false) {
                    $match = false;
                }
            }
            
            if ($match) {
                $results[] = $asset;
            }
        }
        
        return $results;
    }
}
