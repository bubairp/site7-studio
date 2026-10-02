<?php

namespace site7\studio\tests\unit\services\publishing;

use Codeception\Test\Unit;
use site7\studio\models\marketplace\SignatureVerification;
use site7\studio\services\commerce\PackageService;
use site7\studio\services\publishing\Ed25519PackageSigner;

/**
 * Covers Ed25519PackageSigner against real zip archives, with keys injected
 * instead of read from config/environment.
 */
class Ed25519PackageSignerTest extends Unit
{
    protected \UnitTester $tester;

    private string $archive;
    private array $pair;

    protected function _before()
    {
        $this->pair = Ed25519PackageSigner::generateKeyPair();
        $this->archive = sys_get_temp_dir() . '/site7_signer_test_' . uniqid() . '.s7pkg';
        $this->writeArchive(['pricingType' => 'premium']);
    }

    protected function _after()
    {
        if (is_file($this->archive)) {
            unlink($this->archive);
        }
    }

    private function writeArchive(array $bundle): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('bundle-manifest.json', json_encode($bundle + ['packages' => [['handle' => 'hero', 'checksum' => 'abc']]]));
        $zip->addFromString('packages/hero/manifest.json', '{"handle":"hero"}');
        $zip->close();
    }

    private function signer(array $trustedKeys = ['c24-1' => null], string $keyId = 'c24-1'): Ed25519PackageSigner
    {
        $trustedKeys = array_map(fn($key) => $key ?? $this->pair['publicKey'], $trustedKeys);
        return new Ed25519PackageSigner([
            'trustedKeys' => $trustedKeys,
            'secretKey' => $this->pair['secretKey'],
            'keyId' => $keyId,
        ]);
    }

    private function replaceInArchive(string $name, string $contents): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->archive);
        $zip->addFromString($name, $contents);
        $zip->close();
    }

    public function testSignedArchiveVerifies(): void
    {
        $signer = $this->signer();
        $this->assertNotNull($signer->sign($this->archive));

        $result = $signer->verifyArchive($this->archive);

        $this->assertSame(SignatureVerification::VERIFIED, $result->status);
        $this->assertSame('c24-1', $result->keyId);
        $this->assertTrue($signer->verify($this->archive, null));
    }

    public function testArchiveWithoutSignatureIsUnsigned(): void
    {
        $this->assertSame(SignatureVerification::UNSIGNED, $this->signer()->verifyArchive($this->archive)->status);
    }

    public function testAlteredBundleManifestIsInvalid(): void
    {
        $signer = $this->signer();
        $signer->sign($this->archive);

        // e.g. relabelling a paid package as free after signing
        $this->replaceInArchive('bundle-manifest.json', json_encode(['pricingType' => 'free', 'packages' => [['handle' => 'hero', 'checksum' => 'abc']]]));

        $result = $signer->verifyArchive($this->archive);
        $this->assertSame(SignatureVerification::INVALID, $result->status);
        $this->assertStringContainsString('altered', $result->message);
    }

    public function testSignatureFromUntrustedKeyIsInvalid(): void
    {
        $this->signer()->sign($this->archive);

        $other = Ed25519PackageSigner::generateKeyPair();
        $result = $this->signer(['c24-1' => $other['publicKey']])->verifyArchive($this->archive);

        $this->assertSame(SignatureVerification::INVALID, $result->status);
    }

    public function testUnknownKeyIdIsInvalid(): void
    {
        $this->signer(['c24-1' => null], 'attacker')->sign($this->archive);

        $result = $this->signer()->verifyArchive($this->archive);

        $this->assertSame(SignatureVerification::INVALID, $result->status);
        $this->assertStringContainsString('does not trust', $result->message);
    }

    public function testMalformedSignatureFileIsInvalid(): void
    {
        $this->replaceInArchive('signature.json', '{"algorithm":"rsa","keyId":"c24-1","signature":"x"}');

        $this->assertSame(SignatureVerification::INVALID, $this->signer()->verifyArchive($this->archive)->status);
    }

    public function testSignWithoutKeyReturnsNull(): void
    {
        $signer = new Ed25519PackageSigner(['trustedKeys' => [], 'secretKey' => '', 'keyId' => '']);

        $this->assertNull($signer->sign($this->archive));
        $this->assertSame(SignatureVerification::UNSIGNED, $signer->verifyArchive($this->archive)->status);
    }

    public function testPaidPricingTypes(): void
    {
        $this->assertFalse(PackageService::isPaidPricingType(null));
        $this->assertFalse(PackageService::isPaidPricingType(''));
        $this->assertFalse(PackageService::isPaidPricingType('free'));
        $this->assertTrue(PackageService::isPaidPricingType('premium'));
        $this->assertTrue(PackageService::isPaidPricingType('enterprise'));
    }
}
