<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use site7\studio\Site7Studio;

use site7\studio\interfaces\ComponentRegistryInterface;

class ComponentRegistry extends Component implements ComponentRegistryInterface
{
    private array $components = [];
    private bool $loaded = false;
    
    /**
     * Gets all loaded components.
     *
     * @return array
     */
    public function getComponents(): array
    {
        if (!$this->loaded) {
            $this->loadComponents();
        }
        
        return $this->components;
    }
    
    /**
     * Loads components from the file system.
     */
    public function loadComponents(): void
    {
        $this->components = [];
        
        // Define paths where components could be located
        $componentsPath = Craft::getAlias('@site7/studio/components');
        
        if (!is_dir($componentsPath)) {
            $this->loaded = true;
            return;
        }
        
        try {
            $directories = FileHelper::findDirectories($componentsPath, ['recursive' => false]);
            /** @var ManifestReader $reader */
            $reader = Site7Studio::getInstance()->get('manifestReader');
            
            if ($reader) {
                foreach ($directories as $dir) {
                    $manifest = $reader->read($dir);
                    if ($manifest) {
                        $manifest['path'] = $dir;
                        $manifest['id'] = $manifest['id'] ?? basename($dir);
                        $this->components[] = $manifest;
                    }
                }
            }
        } catch (\Exception $e) {
            Craft::error('Failed to load components: ' . $e->getMessage(), __METHOD__);
        }
        
        $this->loaded = true;
    }
}
