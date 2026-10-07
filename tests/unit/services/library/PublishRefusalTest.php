<?php

namespace site7\studio\tests\unit\services\library;

use Codeception\Test\Unit;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\services\library\LibraryDistribution;

/**
 * Publishing stops at the first 401/403: a refused key refuses every package.
 */
class PublishRefusalTest extends Unit
{
    protected \UnitTester $tester;

    private function refused(\Throwable $e): bool
    {
        $method = new \ReflectionMethod(LibraryDistribution::class, 'isKeyRefused');
        $method->setAccessible(true);

        return $method->invoke(null, $e);
    }

    private function wrapped(int $status): \Exception
    {
        $guzzle = new RequestException('HTTP error', new Request('POST', 'marketplace/publish'), new Response($status));
        $api = new CommerceApiException("Commerce24: refused (HTTP {$status})", 0, $guzzle);

        return new \Exception('Could not publish to Commerce24: ' . $api->getMessage(), 0, $api);
    }

    public function testARefusedKeyStopsPublishing(): void
    {
        $this->assertTrue($this->refused($this->wrapped(403)));
        $this->assertTrue($this->refused($this->wrapped(401)));
    }

    public function testOtherErrorsDont(): void
    {
        $this->assertFalse($this->refused($this->wrapped(409)), 'a version that changed');
        $this->assertFalse($this->refused($this->wrapped(500)));
        $this->assertFalse($this->refused(new \Exception('not in the Library')));
    }
}
