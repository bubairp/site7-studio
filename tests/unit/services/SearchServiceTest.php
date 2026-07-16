<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\SearchService;

class SearchServiceTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected clone $tester;
    
    private array $components = [
        [
            'id' => 'hero-banner',
            'title' => 'Hero Banner',
            'description' => 'A large hero banner with a call to action.',
            'category' => 'Headers'
        ],
        [
            'id' => 'feature-list',
            'title' => 'Feature List',
            'description' => 'A grid of features with icons.',
            'category' => 'Content'
        ],
        [
            'id' => 'pricing-table',
            'title' => 'Pricing Table',
            'description' => 'Three tier pricing table for commerce.',
            'category' => 'Commerce'
        ],
        [
            'id' => 'simple-header',
            'title' => 'Simple Header',
            'description' => 'A simple header with navigation.',
            'category' => 'Headers'
        ]
    ];

    public function testSearchByQuery()
    {
        $service = new SearchService();
        $results = $service->filterAssets($this->components, 'hero');
        
        $this->assertCount(1, $results);
        $this->assertEquals('hero-banner', $results[0]['id']);
    }

    public function testSearchByDescription()
    {
        $service = new SearchService();
        $results = $service->filterAssets($this->components, 'commerce');
        
        $this->assertCount(1, $results);
        $this->assertEquals('pricing-table', $results[0]['id']);
    }

    public function testFilterByCategory()
    {
        $service = new SearchService();
        $results = $service->filterAssets($this->components, '', 'Headers');
        
        $this->assertCount(2, $results);
        $this->assertEquals('hero-banner', $results[0]['id']);
        $this->assertEquals('simple-header', $results[1]['id']);
    }

    public function testFilterByCategoryAndQuery()
    {
        $service = new SearchService();
        $results = $service->filterAssets($this->components, 'simple', 'Headers');
        
        $this->assertCount(1, $results);
        $this->assertEquals('simple-header', $results[0]['id']);
    }

    public function testNoResults()
    {
        $service = new SearchService();
        $results = $service->filterAssets($this->components, 'nonexistent');
        
        $this->assertCount(0, $results);
    }
}
