<?php

namespace site7\studio\services\publishing;

use Craft;
use craft\base\Component;
use site7\studio\events\publishing\PackageBuiltEvent;
use site7\studio\interfaces\PackageBuilderInterface;
use site7\studio\records\PackageVersionRecord;
use site7\studio\services\PackageExportService;
use site7\studio\Site7Studio;

/**
 * Builds a distributable .s7pkg for publishing. Deliberately does not
 * reimplement zip/checksum/dependency-closure logic - delegates the actual
 * archive assembly to the existing PackageExportService (Marketplace
 * Foundation), and only adds generating the extra files a *publish* build
 * wants alongside what an *export* build already produces: package.json
 * (an npm-style descriptor mirroring the manifest, for tooling interop),
 * CHANGELOG.md (from PackageVersionRecord history), and LICENSE.md (from
 * the manifest's license field). Since PackageExportService's own
 * addDirectoryToZip() copies a package's directory verbatim, writing these
 * files into that directory *before* calling it is all that's needed - no
 * change to PackageExportService itself.
 */
class PackageBuilderService extends Component implements PackageBuilderInterface
{
    /**
     * @inheritdoc
     */
    public function build(string $handle, array $options = []): string
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception("Package '{$handle}' was not found.");
        }

        $packagePath = $packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \Exception("Package directory for '{$handle}' could not be located on disk.");
        }

        $manifest = $record->getManifest();

        $this->writePackageJson($packagePath, $record, $manifest);
        $this->writeChangelog($packagePath, $record);
        if ($manifest && !empty($manifest->license)) {
            $this->writeLicense($packagePath, $manifest);
        }

        $includeDependencies = (bool)($options['includeDependencies'] ?? true);
        $s7pkgPath = (new PackageExportService())->exportPackage($handle, $includeDependencies);

        Site7Studio::getInstance()->getService('eventDispatcher')->dispatch(new PackageBuiltEvent([
            'handle' => $handle,
            'packagePath' => $s7pkgPath,
        ]));

        return $s7pkgPath;
    }

    private function writePackageJson(string $packagePath, $record, $manifest): void
    {
        $data = [
            'name' => $record->handle,
            'displayName' => $manifest->displayName ?? $record->name,
            'version' => $record->version,
            'description' => $record->description,
            'author' => $record->author,
            'company' => $manifest->company ?? null,
            'license' => $manifest->license ?? null,
            'homepage' => $manifest->website ?? null,
            'keywords' => $manifest->keywords ?? [],
            'site7' => [
                'type' => $record->type,
                'pricingType' => $manifest->pricingType ?? 'free',
                'minimumCraftVersion' => $manifest->minimumCraftVersion ?? null,
                'minimumSite7Version' => $manifest->minimumSite7Version ?? null,
            ],
        ];

        file_put_contents(
            $packagePath . '/package.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeChangelog(string $packagePath, $record): void
    {
        $versions = PackageVersionRecord::find()
            ->where(['packageId' => $record->id])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        $lines = ["# Changelog", "", "All notable changes to **{$record->name}** are documented here.", ""];
        if (empty($versions)) {
            $lines[] = "## {$record->version}";
            $lines[] = '';
            $lines[] = 'Initial release.';
        } else {
            foreach ($versions as $version) {
                $date = $version->releaseDate ? substr((string)$version->releaseDate, 0, 10) : '';
                $lines[] = "## {$version->version}" . ($date ? " - {$date}" : '');
                $lines[] = '';
                $lines[] = trim((string)$version->releaseNotes) !== '' ? $version->releaseNotes : 'No release notes provided.';
                $lines[] = '';
            }
        }

        file_put_contents($packagePath . '/CHANGELOG.md', implode("\n", $lines) . "\n");
    }

    private function writeLicense(string $packagePath, $manifest): void
    {
        $content = "# License\n\n{$manifest->license}\n";
        file_put_contents($packagePath . '/LICENSE.md', $content);
    }
}
