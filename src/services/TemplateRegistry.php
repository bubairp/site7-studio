<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use site7\studio\Site7Studio;
use site7\studio\interfaces\TemplateRegistryInterface;

class TemplateRegistry extends Component implements TemplateRegistryInterface
{
    private array $templates = [];
    private bool $loaded = false;
    
    public function getTemplates(): array
    {
        if (!$this->loaded) {
            $this->loadTemplates();
        }
        
        return $this->templates;
    }
    
    public function loadTemplates(): void
    {
        $this->templates = [];
        
        $templatesPath = Craft::getAlias('@site7/studio/templates');
        
        if (!is_dir($templatesPath)) {
            $this->loaded = true;
            return;
        }
        
        try {
            $directories = FileHelper::findDirectories($templatesPath, ['recursive' => false]);
            $reader = Site7Studio::getInstance()->getManifestReader();
            
            if ($reader) {
                foreach ($directories as $dir) {
                    $manifest = $reader->read($dir);
                    if ($manifest) {
                        $manifest['path'] = $dir;
                        $manifest['id'] = $manifest['id'] ?? basename($dir);
                        $this->templates[] = $manifest;
                    }
                }
            }
        } catch (\Exception $e) {
            Craft::error('Failed to load templates: ' . $e->getMessage(), __METHOD__);
        }
        
        $this->loaded = true;
    }
}
