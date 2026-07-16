<?php

namespace site7\studio\services\sources;

use craft\base\Component;
use site7\studio\interfaces\LibrarySourceInterface;
use site7\studio\interfaces\ComponentRegistryInterface;
use site7\studio\interfaces\TemplateRegistryInterface;
use site7\studio\Site7Studio;
use site7\studio\models\ComponentAsset;
use site7\studio\models\TemplateAsset;

class BuiltInLibrarySource extends Component implements LibrarySourceInterface
{
    public function getSourceHandle(): string
    {
        return 'builtin';
    }

    public function getSourceName(): string
    {
        return 'Built-in Library';
    }

    public function getComponents(): array
    {
        /** @var ComponentRegistryInterface $registry */
        $registry = Site7Studio::getInstance()->get('componentRegistry');
        $componentsData = $registry->getComponents();
        
        $assets = [];
        foreach ($componentsData as $data) {
            $asset = new ComponentAsset();
            $asset->setAttributes($data, false);
            $asset->sourceHandle = $this->getSourceHandle();
            $assets[] = $asset;
        }
        
        return $assets;
    }

    public function getTemplates(): array
    {
        /** @var TemplateRegistryInterface $registry */
        // We will fallback to empty array if templateRegistry is not yet fully implemented
        // but it will be bound in LibraryServiceProvider
        $registry = Site7Studio::getInstance()->get('templateRegistry');
        if (!$registry) {
            return [];
        }
        $templatesData = $registry->getTemplates();
        
        $assets = [];
        foreach ($templatesData as $data) {
            $asset = new TemplateAsset();
            $asset->setAttributes($data, false);
            $asset->sourceHandle = $this->getSourceHandle();
            $assets[] = $asset;
        }
        
        return $assets;
    }
}
