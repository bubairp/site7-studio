<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\LibraryService;
use site7\studio\interfaces\LibrarySourceInterface;
use site7\studio\models\ComponentAsset;
use site7\studio\models\TemplateAsset;

class LibraryServiceTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected clone $tester;
    
    public function testLibraryAggregation()
    {
        $service = new LibraryService();
        
        $mockSource1 = $this->createMock(LibrarySourceInterface::class);
        $mockSource1->method('getSourceHandle')->willReturn('source1');
        $comp1 = new ComponentAsset(['id' => 'comp1']);
        $mockSource1->method('getComponents')->willReturn([$comp1]);
        $mockSource1->method('getTemplates')->willReturn([]);
        
        $mockSource2 = $this->createMock(LibrarySourceInterface::class);
        $mockSource2->method('getSourceHandle')->willReturn('source2');
        $comp2 = new ComponentAsset(['id' => 'comp2']);
        $temp1 = new TemplateAsset(['id' => 'temp1']);
        $mockSource2->method('getComponents')->willReturn([$comp2]);
        $mockSource2->method('getTemplates')->willReturn([$temp1]);
        
        $service->registerSource($mockSource1);
        $service->registerSource($mockSource2);
        
        $allAssets = $service->getAllAssets();
        $this->assertCount(3, $allAssets);
        
        $source2Assets = $service->getAssetsBySource('source2');
        $this->assertCount(2, $source2Assets);
    }
}
