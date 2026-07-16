<?php

namespace site7\studio\tests\unit\services;

use Codeception\Test\Unit;
use site7\studio\services\ManifestReader;

class ManifestReaderTest extends Unit
{
    /**
     * @var \UnitTester
     */
    protected clone $tester;
    
    private string $tempDir;

    protected function _before()
    {
        $this->tempDir = sys_get_temp_dir() . '/site7_manifest_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function _after()
    {
        if (file_exists($this->tempDir . '/manifest.json')) {
            unlink($this->tempDir . '/manifest.json');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testReadValidManifest()
    {
        $data = ['id' => 'test-component', 'title' => 'Test Component'];
        file_put_contents($this->tempDir . '/manifest.json', json_encode($data));
        
        $reader = new ManifestReader();
        $result = $reader->read($this->tempDir);
        
        $this->assertIsArray($result);
        $this->assertEquals('test-component', $result['id']);
        $this->assertEquals('Test Component', $result['title']);
    }

    public function testReadInvalidManifest()
    {
        file_put_contents($this->tempDir . '/manifest.json', 'invalid-json');
        
        $reader = new ManifestReader();
        $result = $reader->read($this->tempDir);
        
        $this->assertNull($result);
    }

    public function testReadMissingManifest()
    {
        $reader = new ManifestReader();
        $result = $reader->read($this->tempDir);
        
        $this->assertNull($result);
    }
}
