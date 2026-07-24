<?php

namespace site7\studio\services\import;

use craft\base\Component;
use craft\helpers\StringHelper;
use site7\studio\Site7Studio;

/**
 * The Resource Importer's pre-write validation. Distinct from both the
 * (frozen) site7\studio\services\engine\PackageValidator, which only gates
 * install/discovery of an already-written package, and
 * site7\studio\services\publishing\PublishValidatorService, which measures
 * publish-readiness of an already-generated package - this one measures
 * whether a *live Craft resource* is safe to generate a package from, before
 * any manifest.json/fields.yaml/etc. is written.
 *
 * Called once by ResourceAnalyzerService (feeding the wizard's Preview step)
 * and again, defensively, by each import*() service immediately before it
 * writes to disk - never trusting a possibly-stale client-side analysis.
 */
class ResourceImportValidator extends Component
{
    /**
     * @param string $kind One of: matrix-entry-type, craft-section, page, website.
     * @param array $context {
     *     detectedFields?: array (from CraftResourceService::describeFieldLayout()),
     *     hasCapturableContent?: bool,
     *     dependencies?: array<int, array{kind: string, handle: string, status: string}>,
     *     proposedHandle?: string,
     *     version?: string,
     * }
     * @return array{errors: string[], warnings: string[]}
     */
    public function validateImport(string $kind, array $context): array
    {
        $errors = [];
        $warnings = [];

        // 1. Every detected field is classified (ResourceClassifierService) into
        // exactly one of six buckets, each with its own action - never a
        // silent skip. Feature Resource (imported) and successfully-registered
        // Shared/Package Resource references need no message; the rest do.
        foreach ($context['detectedFields'] ?? [] as $field) {
            $classification = $field['classification'] ?? null;
            $statusLabel = $field['statusLabel'] ?? null;
            $detail = $field['detail'] ?? '';
            if ($classification === null) {
                continue;
            }

            $message = "{$field['handle']} - Type: {$field['type']}, Status: {$statusLabel}." . ($detail ? " {$detail}" : '');

            switch ($classification) {
                case \site7\studio\services\import\ResourceClassifierService::PLUGIN_DEPENDENCY:
                case \site7\studio\services\import\ResourceClassifierService::UNKNOWN_RESOURCE:
                    $warnings[] = $message;
                    break;
                case \site7\studio\services\import\ResourceClassifierService::SHARED_RESOURCE:
                case \site7\studio\services\import\ResourceClassifierService::PACKAGE_RESOURCE:
                case \site7\studio\services\import\ResourceClassifierService::PLATFORM_CONFIGURATION:
                    // Informational only - these are always resolved to a
                    // reference, never a hard failure or a silent drop.
                    $warnings[] = $message;
                    break;
                // Feature Resource: imported normally, no message needed.
            }
        }

        // 2. Missing templates - nothing capturable at all is a hard failure;
        // mirrors TemplateGeneratorService's existing "no Site7 content" /
        // "no recognized Sections" exceptions, surfaced here pre-write instead.
        if (array_key_exists('hasCapturableContent', $context) && !$context['hasCapturableContent']) {
            $errors[] = 'Nothing could be captured from this resource - it has no supported fields or content.';
        }

        // 3. Dependencies - referenced Sections/Templates/Patterns/Category or
        // Tag groups that aren't installed/packaged are never blocking, since
        // installPackage()'s cascade can only reach handles that are
        // themselves packages, not arbitrary native Craft resources.
        foreach ($context['dependencies'] ?? [] as $dependency) {
            if (($dependency['status'] ?? '') === 'missing') {
                $warnings[] = "Depends on {$dependency['kind']} '{$dependency['handle']}', which is not yet installed - install it separately before enabling.";
            } elseif (($dependency['status'] ?? '') === 'not-packaged') {
                $warnings[] = "{$dependency['handle']} - Type: {$dependency['kind']}, Status: Shared Resource Dependency. Not imported; reference the existing {$dependency['kind']} after install.";
            }
        }

        // 4. Duplicate handles - the generator auto-suffixes rather than
        // failing (matches PackageAuthoringService/TemplateGeneratorService's
        // existing collision behavior), so this is only ever a warning.
        $proposedHandle = (string)($context['proposedHandle'] ?? '');
        if ($proposedHandle !== '') {
            $basePath = \Craft::getAlias('@packages');
            if (is_dir($basePath . '/' . $proposedHandle)) {
                $warnings[] = "Handle '{$proposedHandle}' already exists - will be saved as '{$proposedHandle}-2' (or the next available suffix).";
            }
        }

        // 6. Compatibility - live Craft field/entry-type handles are always
        // valid Craft handle syntax to begin with, but a handle carried
        // through from a much older install could in principle contain
        // characters that don't round-trip cleanly through YAML/JSON keys.
        foreach ($context['detectedFields'] ?? [] as $field) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', (string)($field['handle'] ?? ''))) {
                $warnings[] = "Field handle '{$field['handle']}' is not a standard Craft handle and may not import cleanly.";
            }
        }

        // 7. Version.
        $version = (string)($context['version'] ?? '1.0.0');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $warnings[] = "Version '{$version}' is not a standard semantic version (expected e.g. 1.0.0).";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Computes a unique, kebab-case package handle for a proposed name -
     * the same collision-avoidance loop used by TemplateGeneratorService/
     * StarterKitGeneratorService/PackageAuthoringService, shared here so the
     * Analyzer (preview) and the importers (commit) always agree on what
     * handle a given name would resolve to.
     */
    public function generateUniqueHandle(string $name): string
    {
        $base = StringHelper::toKebabCase($name);
        $handle = $base;
        $basePath = \Craft::getAlias('@packages');
        $suffix = 2;
        while (is_dir($basePath . '/' . $handle)) {
            $handle = $base . '-' . $suffix;
            $suffix++;
        }
        return $handle;
    }
}
