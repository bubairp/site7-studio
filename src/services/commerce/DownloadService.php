<?php

namespace site7\studio\services\commerce;

use Craft;
use craft\base\Component;
use site7\studio\interfaces\CommerceClientInterface;
use site7\studio\models\commerce\CommerceApiException;
use site7\studio\Site7Studio;

/**
 * Backs the Downloads tab: Commerce24's own download/purchase history, plus
 * this site's local Import/Export history (which never touches Commerce24 -
 * it's the existing Marketplace Foundation's own record of .s7pkg activity,
 * read straight off disk).
 */
class DownloadService extends Component
{
    public CommerceClientInterface $client;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (!isset($this->client)) {
            $this->client = Site7Studio::getInstance()->commerceClient;
        }
    }

    /**
     * Purchased packages available to download again from Commerce24 - real
     * installable SITE7 Studio packages only ('type' => 'package'). See
     * getPurchasedAddOns() for the other half of the same /downloads/purchased
     * response: Commerce24 is documented (Phase 24) as the source of truth
     * for "Purchased Packages" and "Purchased Add-ons" as two distinct
     * things, but until now this only ever exposed one flattened list with
     * no way to tell them apart.
     */
    public function getPurchasedPackages(): array
    {
        return $this->filterPurchasedByType('package');
    }

    /**
     * Purchased add-ons from Commerce24 - not installable SITE7 Studio
     * packages (no manifest, nothing the Package Engine can install), e.g.
     * a support tier or a service entitlement. Kept separate from
     * getPurchasedPackages() rather than merged, since "download and
     * install" never applies to these the way it does a package.
     */
    public function getPurchasedAddOns(): array
    {
        return $this->filterPurchasedByType('addon');
    }

    private function filterPurchasedByType(string $type): array
    {
        $items = $this->fetch('/downloads/purchased')['packages'] ?? [];
        return array_values(array_filter($items, fn($item) => ($item['type'] ?? 'package') === $type));
    }

    /** Commerce24's own record of past downloads for this account. */
    public function getDownloadHistory(): array
    {
        return $this->fetch('/downloads/history')['downloads'] ?? [];
    }

    /**
     * This site's local .s7pkg export history - one entry per file currently
     * sitting in storage/site7-studio/exports/, newest first. Local-only;
     * never calls Commerce24.
     */
    public function getExportHistory(): array
    {
        return $this->listLocalArchives(Craft::getAlias('@storage') . '/site7-studio/exports');
    }

    /**
     * This site's local .s7pkg import history. PackageImportService doesn't
     * currently persist a log of past imports (only the resulting installed
     * packages), so for now this reflects whatever's in the Local Repository
     * folder as a proxy for "packages available to have been imported."
     * A dedicated import log is a natural follow-up, not built here to avoid
     * a schema change beyond this milestone's scope.
     */
    public function getImportHistory(): array
    {
        return $this->listLocalArchives(Craft::getAlias('@storage') . '/site7-studio/marketplace-repo');
    }

    private function listLocalArchives(string $directory): array
    {
        $files = glob($directory . '/*.s7pkg') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        return array_map(fn($file) => [
            'fileName' => basename($file),
            'size' => filesize($file) ?: 0,
            'date' => date('Y-m-d H:i:s', filemtime($file)),
        ], $files);
    }

    private function fetch(string $endpoint): array
    {
        if (!$this->client->isConfigured()) {
            return [];
        }

        try {
            return $this->client->request('GET', $endpoint);
        } catch (CommerceApiException $e) {
            Craft::warning("Could not fetch {$endpoint} from Commerce24: " . $e->getMessage(), 'site7-studio');
            return [];
        }
    }
}
