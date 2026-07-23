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

    /** Purchased packages available to download again from Commerce24. */
    public function getPurchasedPackages(): array
    {
        return $this->fetch('/downloads/purchased')['packages'] ?? [];
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
