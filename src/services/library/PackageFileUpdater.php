<?php

namespace site7\studio\services\library;

use craft\helpers\FileHelper;
use site7\studio\services\design\DesignLoader;
use site7\studio\services\support\PackageArchiveHelper;
use site7\studio\services\import\SectionSchemaService;
use site7\studio\services\PackageManagerService;
use site7\studio\services\synchronization\PackageUpdatePlanner;

/**
 * The files a Section package writes onto a site - its block template
 * (templates/_blocks/<entry type>.twig) and its manifest's ownedFiles - on a
 * Library update (docs/53 §3). Every file follows the same rule:
 *
 * - new upstream, nothing here: added
 * - unchanged here since installed: replaced with the new version
 * - already the new version here: left, its tracking row brought up to date
 * - changed here: kept, never overwritten
 * - removed upstream (or the block renamed): deleted only if unchanged here,
 *   otherwise kept; fields and content are never touched
 * - deleted here: not recreated
 *
 * "Since installed" is the old version's file (the Library copy the update
 * set aside); a tracking row stands in when that version had no such file.
 * A file is tracked (site7_installed_files) only after it's verified on disk
 * to be exactly the new version's bytes; a failed write throws, so the whole
 * package update fails and can be run again. Paths are relative to the
 * Craft root, the form install, Rollback and the uninstall snapshot use.
 *
 * A block template the theme package has (a core block, docs/60 §3) and
 * this site has no copy of is the theme's: it's left out, and an old
 * tracking row for it is dropped. A site's own copy is updated as above.
 */
class PackageFileUpdater
{
    public const ADD = 'add';
    public const UPDATE = 'update';
    public const REFRESH = 'refresh';
    public const KEEP = 'kept';
    public const KEEP_REMOVED = 'kept-removed';
    public const REMOVE = 'remove';
    public const FORGET = 'forget';
    public const DELETED_HERE = 'deleted-here';
    public const NONE = 'none';

    /** @var callable(string $source, string $target): void */
    private $writer;

    /** @var callable(string $template): bool whether the theme package has this template, e.g. "_blocks/ctaBanner.twig" */
    private $themeHas;

    public function __construct(private string $root, private FileBaselineStore $store, ?callable $writer = null, ?callable $themeHas = null)
    {
        $this->root = rtrim($root, '/');
        $this->writer = $writer ?? static function(string $source, string $target): void {
            FileHelper::createDirectory(dirname($target));
            copy($source, $target);
        };
        $this->themeHas = $themeHas ?? static fn(string $template): bool => DesignLoader::themeTemplate($template) !== null;
    }

    /**
     * What to do with one file, from its checksums (null: no such file).
     * Built on PackageUpdatePlanner's three-way rule; $tracked says a
     * tracking row exists, so a missing file was ours and was deleted here.
     */
    public static function action(?string $baseline, ?string $live, ?string $incoming, bool $tracked): string
    {
        if ($incoming === null) {
            if ($live === null) {
                return $tracked ? self::FORGET : self::NONE;
            }
            if ($baseline === null) {
                return self::NONE;
            }
            return (new PackageUpdatePlanner())->classify(
                ['checksum' => $baseline, 'targetPath' => '', 'resourceHandle' => ''], $live, null
            )['result'] === PackageUpdatePlanner::RESULT_SAFE_REMOVAL ? self::REMOVE : self::KEEP_REMOVED;
        }
        if ($live === null) {
            return ($baseline !== null || $tracked) ? self::DELETED_HERE : self::ADD;
        }
        if ($live === $incoming) {
            return self::REFRESH;
        }
        if ($baseline === null) {
            return self::KEEP; // something else already sits here
        }

        return (new PackageUpdatePlanner())->classify(
            ['checksum' => $baseline, 'targetPath' => '', 'resourceHandle' => ''], $live, $incoming
        )['result'] === PackageUpdatePlanner::RESULT_SAFE_UPDATE ? self::UPDATE : self::KEEP;
    }

    /**
     * A package version's files: target path => source file, kind, resource handle.
     *
     * @return array{files: array<string, array{source: string, kind: string, handle: ?string}>, refused: string[]}
     */
    public static function files(string $packageDir): array
    {
        $files = [];
        $refused = [];
        $schema = json_decode((string)@file_get_contents("{$packageDir}/" . SectionSchemaService::FILE), true) ?: [];
        foreach ($schema['items'] ?? [] as $item) {
            if (($item['kind'] ?? '') === 'entryTypes' && ($item['uid'] ?? null) === ($schema['entryType'] ?? null)
                && is_file("{$packageDir}/template.twig") && PackageManagerService::isRelativeSafePath("templates/_blocks/{$item['handle']}.twig")) {
                $files["templates/_blocks/{$item['handle']}.twig"] = ['source' => "{$packageDir}/template.twig", 'kind' => 'template', 'handle' => $item['handle']];
            }
        }
        $manifest = json_decode((string)@file_get_contents("{$packageDir}/manifest.json"), true) ?: [];
        foreach ((array)($manifest['ownedFiles'] ?? []) as $owned) {
            $target = (string)($owned['targetPath'] ?? '');
            $source = (string)($owned['sourcePath'] ?? '');
            if ($target === '' || $source === '') {
                continue;
            }
            if (!PackageManagerService::isAllowedOwnedFileTarget($target) || !PackageManagerService::isRelativeSafePath($source) || !is_file("{$packageDir}/{$source}")) {
                $refused[] = $target;
                continue;
            }
            $files[$target] = ['source' => "{$packageDir}/{$source}", 'kind' => 'file', 'handle' => null];
        }

        return ['files' => $files, 'refused' => $refused];
    }

    /**
     * Applies the new version's files over the old version's.
     *
     * @return array{updated: string[], added: string[], kept: string[], removed: string[], deletedHere: string[], unchanged: int, notes: string[], templates: string[]}
     *   templates: absolute paths of block templates written (for the Tailwind safelist)
     * @throws \RuntimeException when a written file doesn't read back as the new version
     */
    public function apply(int $packageId, string $packageHandle, string $version, string $oldDir, string $newDir): array
    {
        $report = ['updated' => [], 'added' => [], 'kept' => [], 'removed' => [], 'deletedHere' => [], 'unchanged' => 0, 'notes' => [], 'templates' => []];
        $old = self::files($oldDir);
        $new = self::files($newDir);
        foreach ($new['refused'] as $target) {
            $report['notes'][] = "owned file {$target} is outside the frontend source folders - not written";
        }

        $paths = array_unique(array_merge(array_keys($old['files']), array_keys($new['files'])));
        sort($paths);
        foreach ($paths as $path) {
            $was = $old['files'][$path] ?? null;
            $is = $new['files'][$path] ?? null;
            $kind = ($is ?? $was)['kind'];
            $label = ($kind === 'template' ? 'template ' . substr($path, strlen('templates/')) : "file {$path}");
            $handle = ($is ?? $was)['handle'] ?? $packageHandle;
            $absolute = "{$this->root}/{$path}";
            $row = $this->store->find($packageId, $path);

            if ($kind === 'file' && ($owner = $this->store->otherOwner($packageId, $path)) !== null) {
                $report['notes'][] = "{$label} belongs to package {$owner} - left alone";
                continue;
            }

            if ($kind === 'template' && !is_file($absolute) && ($this->themeHas)(substr($path, strlen('templates/')))) {
                if ($row !== null) {
                    $this->store->remove($packageId, $path);
                }
                $report['unchanged']++;
                continue;
            }

            $baseline = $was ? PackageArchiveHelper::computeFileChecksum($was['source']) : (($row['checksum'] ?? '') ?: null);
            $live = is_file($absolute) ? PackageArchiveHelper::computeFileChecksum($absolute) : null;
            $incoming = $is ? PackageArchiveHelper::computeFileChecksum($is['source']) : null;

            switch (self::action($baseline, $live, $incoming, $row !== null)) {
                case self::ADD:
                case self::UPDATE:
                    ($this->writer)($is['source'], $absolute);
                    $written = is_file($absolute) ? PackageArchiveHelper::computeFileChecksum($absolute) : null;
                    if ($written === null || $written !== $incoming) {
                        throw new \RuntimeException("Could not write {$path}: the file on disk doesn't match the new version. Nothing was recorded for it; run the update again.");
                    }
                    $this->store->record($packageId, $handle, $path, $version, $written);
                    $report[$live === null ? 'added' : 'updated'][] = $label;
                    if ($kind === 'template') {
                        $report['templates'][] = $absolute;
                    }
                    break;
                case self::REFRESH:
                    $this->store->record($packageId, $handle, $path, $version, $live);
                    $report['unchanged']++;
                    if ($kind === 'template') {
                        $report['templates'][] = $absolute;
                    }
                    break;
                case self::REMOVE:
                    @unlink($absolute);
                    if (is_file($absolute)) {
                        throw new \RuntimeException("Could not remove {$path}, which the new version no longer has. Run the update again.");
                    }
                    $this->store->remove($packageId, $path);
                    $report['removed'][] = $label;
                    break;
                case self::FORGET:
                    $this->store->remove($packageId, $path);
                    $report['unchanged']++;
                    break;
                case self::KEEP:
                    $report['kept'][] = $label;
                    break;
                case self::KEEP_REMOVED:
                    $report['kept'][] = "{$label} (removed upstream, edited here)";
                    break;
                case self::DELETED_HERE:
                    $report['deletedHere'][] = $label;
                    break;
                default:
                    $report['unchanged']++;
            }
        }

        return $report;
    }
}
