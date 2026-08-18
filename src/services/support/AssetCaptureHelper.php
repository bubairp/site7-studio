<?php

namespace site7\studio\services\support;

use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\FileHelper;

/**
 * Shared, stateless helper for capturing a live Craft Assets field's VALUE
 * (which specific files were actually selected on a specific piece of
 * content) into a package on disk, and restoring that value into a real
 * Asset element on install elsewhere.
 *
 * This is deliberately orthogonal to ResourceClassifierService's Assets ==
 * SHARED_RESOURCE classification, which governs whether an Assets FIELD's
 * *definition* gets captured into fields.yaml (it never does - the Volume it
 * points at must already exist on the installing site). That classification
 * is unchanged by this helper: an Assets field is still referenced, not
 * duplicated, as a field. What was previously entirely unhandled is the
 * field's *value* on a specific page/block - which real image(s) an editor
 * actually picked - so a fresh install of an imported page/section
 * previously always arrived with that field empty. This helper captures the
 * selected file(s) themselves (copied into the package's own
 * preview/assets/ subfolder) plus enough linkage metadata (filename, source
 * Volume handle, folder subpath, alt text, title) for restoreAssetField() to
 * either find a matching Asset already on the target site (by filename +
 * volume, so re-running an install/update doesn't duplicate uploads) or
 * upload a new one from the package's own bundled copy and re-link the
 * field to it.
 *
 * The `__site7Type => 'assets'` marker on the returned/consumed array is how
 * TemplateGeneratorService::extractFieldValues() and PageImportService::
 * captureNativeFields() distinguish this structured descriptor from a plain
 * scalar field value when writing demoContent/entryFields, and how
 * TemplateInsertionService::createEntryFromTemplate() recognizes it on
 * restore.
 */
class AssetCaptureHelper
{
    public const TYPE_MARKER = 'assets';
    private const ASSETS_SUBDIR = 'preview/assets';

    /**
     * @param mixed $value Whatever Entry::getFieldValue() returned for an
     *   Assets field - normally an AssetQuery, occasionally an already-
     *   resolved array (e.g. when read from a fresh unsaved element).
     * @param string|null $packagePath When non-null, referenced asset files
     *   are actually copied into $packagePath/preview/assets/. When null (or
     *   $copyFiles is false), only the descriptor is built - no I/O - so a
     *   read-only preview (PageUpdateService::diff()) can still detect a
     *   changed selection without mutating the package on disk.
     * @return array{__site7Type: string, items: array}|null null if the
     *   field has no selection to capture.
     */
    public static function captureAssetField(mixed $value, ?string $packagePath, bool $copyFiles = true): ?array
    {
        $assets = self::resolveAssets($value);
        if (empty($assets)) {
            return null;
        }

        $items = [];
        foreach ($assets as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $volume = $asset->getVolume();
            $targetRelative = self::ASSETS_SUBDIR . '/' . $asset->id . '-' . $asset->filename;

            if ($copyFiles && $packagePath !== null) {
                $sourcePath = $asset->getCopyOfFile();
                if ($sourcePath && is_file($sourcePath)) {
                    $destination = rtrim($packagePath, '/') . '/' . $targetRelative;
                    FileHelper::createDirectory(dirname($destination));
                    copy($sourcePath, $destination);
                    @unlink($sourcePath);
                }
            }

            $items[] = [
                'filename' => $asset->filename,
                'file' => $targetRelative,
                'volumeHandle' => $volume?->handle,
                'folderPath' => $asset->getFolder()?->path ?? '',
                'altText' => $asset->alt ?? null,
                'title' => $asset->title,
                'kind' => $asset->kind,
            ];
        }

        if (empty($items)) {
            return null;
        }

        return ['__site7Type' => self::TYPE_MARKER, 'items' => $items];
    }

    /**
     * @param mixed $value A field value that may or may not be this
     *   helper's captured-asset descriptor shape.
     */
    public static function isAssetDescriptor(mixed $value): bool
    {
        return is_array($value) && ($value['__site7Type'] ?? null) === self::TYPE_MARKER;
    }

    /**
     * Restores a captured-asset descriptor into real Asset element(s) on the
     * installing site, uploading from the package's own bundled copy only
     * when a matching Asset (same filename in the same Volume) doesn't
     * already exist there - so re-running an install/update is idempotent
     * rather than accumulating duplicate uploads.
     *
     * @param array $descriptor As produced by captureAssetField().
     * @return int[] Asset element IDs, suitable for Entry::setFieldValue() on an Assets field.
     */
    public static function restoreAssetField(array $descriptor, string $packagePath): array
    {
        $ids = [];
        $volumesService = Craft::$app->getVolumes();
        $assetsService = Craft::$app->getAssets();
        $defaultVolume = $volumesService->getAllVolumes()[0] ?? null;

        foreach ((array)($descriptor['items'] ?? []) as $item) {
            $filename = (string)($item['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            $volume = null;
            if (!empty($item['volumeHandle'])) {
                $volume = $volumesService->getVolumeByHandle((string)$item['volumeHandle']);
            }
            $volume ??= $defaultVolume;
            if (!$volume) {
                // No Volume exists at all on this install - nothing to upload into.
                continue;
            }

            $existing = Asset::find()->filename($filename)->volumeId($volume->id)->one();
            if ($existing) {
                $ids[] = $existing->id;
                continue;
            }

            $sourceFile = rtrim($packagePath, '/') . '/' . ltrim((string)($item['file'] ?? ''), '/');
            if (!is_file($sourceFile)) {
                continue;
            }

            $folderPath = trim((string)($item['folderPath'] ?? ''), '/');
            $folder = $folderPath !== ''
                ? $assetsService->ensureFolderByFullPathAndVolume($folderPath . '/', $volume)
                : ($assetsService->getRootFolderByVolumeId($volume->id) ?? $assetsService->ensureFolderByFullPathAndVolume('', $volume));

            $asset = new Asset();
            $asset->tempFilePath = $sourceFile;
            $asset->filename = $filename;
            $asset->newFolderId = $folder->id;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if (!empty($item['altText'])) {
                $asset->alt = $item['altText'];
            }
            if (!empty($item['title'])) {
                $asset->title = $item['title'];
            }

            if (Craft::$app->getElements()->saveElement($asset)) {
                $ids[] = $asset->id;
            } else {
                Craft::warning("Failed to restore captured asset '{$filename}': " . implode(' ', $asset->getFirstErrors()), __METHOD__);
            }
        }

        return $ids;
    }

    /** @return Asset[] */
    private static function resolveAssets(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if ($value instanceof AssetQuery) {
            return $value->all();
        }
        if (is_array($value)) {
            return array_values(array_filter($value, fn($v) => $v instanceof Asset));
        }
        return [];
    }
}
