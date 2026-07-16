<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;

use site7\studio\interfaces\ManifestReaderInterface;

class ManifestReader extends Component implements ManifestReaderInterface
{
    /**
     * Reads and decodes a manifest.json file from a given directory path.
     *
     * @param string $directoryPath
     * @return array|null The decoded manifest data, or null on failure.
     */
    public function read(string $directoryPath): ?array
    {
        $manifestPath = rtrim($directoryPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manifest.json';
        
        if (!file_exists($manifestPath)) {
            return null;
        }
        
        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            return null;
        }
        
        $data = json_decode($contents, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::error("Failed to decode manifest at {$manifestPath}: " . json_last_error_msg(), __METHOD__);
            return null;
        }
        
        return $data;
    }
}
