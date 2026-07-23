<?php

namespace site7\studio\models\marketplace;

use craft\base\Model;

/**
 * The bundle-level manifest written at the root of every exported .s7pkg
 * (as "bundle-manifest.json"), distinct from the manifest.json each
 * individual package already carries. Since Pattern/Template/Starter Kit
 * packages only ever reference sibling packages by handle (never duplicate
 * their content - see PackageManifest's own docblock), this is what makes an
 * exported archive self-contained: it lists every package bundled inside,
 * each with the version and checksum it had at export time.
 */
class PackageBundleManifest extends Model
{
    /**
     * The bundle manifest schema version this build of Site7 Studio writes
     * and expects to read. Bump this if the shape of bundle-manifest.json
     * ever changes; PackageImportService warns (but does not necessarily
     * reject) on a mismatch.
     */
    public const SUPPORTED_SCHEMA_VERSION = '1';

    public string $schemaVersion = '1';
    public string $generatedAt = '';

    /** The handle of the package that was actually requested for export. */
    public string $rootHandle = '';
    public string $rootType = '';

    /** Craft/Site7 versions of the environment the export was produced on. */
    public string $craftVersion = '';
    public string $site7Version = '';

    /**
     * Every package bundled inside this archive (the root package plus its
     * full resolved dependency closure, if it was exported with dependencies
     * included). Each entry: {handle, type, version, checksum}.
     *
     * @var array<int, array{handle: string, type: string, version: string, checksum: string}>
     */
    public array $packages = [];

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['schemaVersion', 'rootHandle', 'rootType'], 'required'];
        $rules[] = [['schemaVersion', 'generatedAt', 'rootHandle', 'rootType', 'craftVersion', 'site7Version'], 'string'];
        $rules[] = [['packages'], 'safe'];
        return $rules;
    }

    /**
     * Finds this bundle's own entry for its root package.
     */
    public function getRootEntry(): ?array
    {
        foreach ($this->packages as $entry) {
            if (($entry['handle'] ?? null) === $this->rootHandle) {
                return $entry;
            }
        }
        return null;
    }
}
