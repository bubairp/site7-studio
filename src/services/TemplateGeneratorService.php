<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;
use site7\studio\records\PackageRecord;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates a new Template package from the current content of an existing entry's
 * Site7 Matrix field ("Save as Template").
 */
class TemplateGeneratorService extends Component
{
    /**
     * @param Entry $entry The saved entry to generate a Template from.
     * @param array $meta {name, description, category, tags, previewImage?: UploadedFile}
     * @return PackageRecord The newly registered Template package.
     * @throws \Exception if the entry has no content in the configured Matrix field.
     */
    public function generateFromEntry(Entry $entry, array $meta): PackageRecord
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        if (!$matrixHandle) {
            throw new \Exception('No Site7 Matrix field is configured.');
        }

        // Blocks inserted via this plugin's own Section/Pattern/Template insert flow
        // can remain provisional drafts on the owner until a subsequent native
        // full-page save merges them, so the default (canonical-only) field-value
        // query can under-report; read inclusively of drafts here.
        $fieldValue = $entry->getFieldValue($matrixHandle);
        $blocks = $fieldValue ? $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->all() : [];
        if (empty($blocks)) {
            throw new \Exception('This entry has no Site7 content to save as a Template.');
        }

        $entryTypeToSection = $this->buildEntryTypeToSectionMap();

        // Extract, in order: [sectionHandle => demoContent] pairs.
        $sectionHandles = [];
        $demoContent = [];
        foreach ($blocks as $block) {
            $entryTypeHandle = $block->getType()->handle;
            $sectionHandle = $entryTypeToSection[$entryTypeHandle] ?? null;
            if (!$sectionHandle) {
                // Not a recognized Site7 Section - skip rather than fail the whole save.
                continue;
            }

            $sectionHandles[] = $sectionHandle;
            $demoContent[$sectionHandle] = $this->extractFieldValues($block);
        }

        if (empty($sectionHandles)) {
            throw new \Exception('No recognized Site7 Sections were found in this entry.');
        }

        [$requiresPatterns, $requiresSections] = $this->detectPatternReferences($sectionHandles);

        // Capture the source Entry's own custom fields (its Section/Entry Type field
        // layout) - everything except the Site7 Matrix field, which is captured above
        // via demoContent/requires instead. Structural identity (handles) only, per
        // the "DO NOT SAVE" rule - never the entry's own runtime ID/slug/title/etc.
        $entryFields = $this->extractFieldValues($entry, [$matrixHandle]);

        $handle = $this->generateUniqueHandle($meta['name']);
        $packagePath = rtrim(Craft::getAlias('@packages'), '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $meta['name'],
            'type' => 'template',
            'version' => '1.0.0',
            'author' => Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7',
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?? null,
            'tags' => $tags,
            'sourceEntryType' => $entry->getType()->handle,
            'sourceSection' => $entry->getSection()?->handle,
            'requires' => array_filter([
                'patterns' => $requiresPatterns,
                'sections' => $requiresSections,
            ]),
            'demoContent' => $demoContent,
            'entryFields' => $entryFields,
            'dependencies' => [],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($meta['name'], $sectionHandles));

        FileHelper::createDirectory($packagePath . '/preview');
        file_put_contents($packagePath . '/preview/preview-data.yaml', Yaml::dump(['block' => $demoContent], 4));
        file_put_contents($packagePath . '/preview/preview.twig', $this->buildPreviewTwig($sectionHandles));

        /** @var UploadedFile|null $previewImage */
        $previewImage = $meta['previewImage'] ?? null;
        if ($previewImage instanceof UploadedFile && $previewImage->tempName) {
            copy($previewImage->tempName, $packagePath . '/preview/preview.png');
        }

        $packageManager = Site7Studio::getInstance()->packageManager;
        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Template was generated but could not be registered.');
        }

        return $record;
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }

    /**
     * Builds an [entryTypeHandle => sectionPackageHandle] map by scanning every
     * Section package's matrix.yaml, mirroring the same on-demand lookup already
     * performed (in the opposite direction) by PatternInsertionService and
     * PackageActionController::actionGetBrowserData.
     */
    private function buildEntryTypeToSectionMap(): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $map = [];

        foreach ($packageManager->getAllPackages() as $pkg) {
            if (strtolower($pkg->type) !== 'section') {
                continue;
            }
            $path = $packageManager->getPackagePath($pkg->handle);
            if (!$path) {
                continue;
            }
            $matrixYamlPath = $path . '/matrix.yaml';
            if (!file_exists($matrixYamlPath)) {
                continue;
            }
            $matrixData = Yaml::parseFile($matrixYamlPath);
            $entryTypeHandle = $matrixData['blocks'][0]['handle'] ?? null;
            if ($entryTypeHandle) {
                $map[$entryTypeHandle] = $pkg->handle;
            }
        }

        return $map;
    }

    /**
     * Reads a nested block's (or a top-level Entry's) custom field values into a
     * plain associative array. Section/entry fields are all PlainText today
     * (CraftResourceService's MVP scope), so a general field-type serializer isn't
     * needed - values are read as-is.
     *
     * $skipHandles excludes relational/non-scalar fields (e.g. the Site7 Matrix
     * field itself, when reading a top-level Entry) that would otherwise hit the
     * (string) cast below and fatal - ElementQueryInterface has no __toString().
     */
    private function extractFieldValues(Entry $block, array $skipHandles = []): array
    {
        $values = [];
        $layout = $block->getFieldLayout();
        if (!$layout) {
            return $values;
        }
        foreach ($layout->getCustomFields() as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }
            $value = $block->getFieldValue($field->handle);
            if (is_scalar($value) || $value === null) {
                $values[$field->handle] = $value;
            } else {
                $values[$field->handle] = (string)$value;
            }
        }
        return $values;
    }

    /**
     * Scans the ordered section-handle sequence for contiguous runs that exactly
     * match an existing Pattern's requires.sections, replacing each matched run with
     * a Pattern reference. Remaining sections are returned as bare Sections. This is
     * a best-effort reconstruction - true arbitrary interleaving of Patterns and
     * Sections isn't representable under Phase 9's existing (frozen) ordering model,
     * where all requires.patterns are expanded before any requires.sections.
     *
     * @return array{0: string[], 1: string[]} [requiresPatterns, requiresSections]
     */
    private function detectPatternReferences(array $sectionHandles): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $patterns = array_filter($packageManager->getAllPackages(), fn($p) => strtolower($p->type) === 'pattern');

        $requiresPatterns = [];
        $requiresSections = [];
        $i = 0;
        $count = count($sectionHandles);

        while ($i < $count) {
            $matchedPattern = null;
            foreach ($patterns as $pattern) {
                $manifest = $pattern->getManifest();
                $required = $manifest?->requires['sections'] ?? [];
                $len = count($required);
                if ($len === 0 || $i + $len > $count) {
                    continue;
                }
                if (array_slice($sectionHandles, $i, $len) === $required) {
                    $matchedPattern = $pattern;
                    $i += $len;
                    break;
                }
            }

            if ($matchedPattern) {
                $requiresPatterns[] = $matchedPattern->handle;
            } else {
                $requiresSections[] = $sectionHandles[$i];
                $i++;
            }
        }

        return [$requiresPatterns, $requiresSections];
    }

    private function generateUniqueHandle(string $name): string
    {
        $base = StringHelper::toKebabCase($name);
        $handle = $base;
        $basePath = Craft::getAlias('@packages');
        $suffix = 2;
        while (is_dir($basePath . '/' . $handle)) {
            $handle = $base . '-' . $suffix;
            $suffix++;
        }
        return $handle;
    }

    private function buildReadme(string $name, array $sectionHandles): string
    {
        $list = implode("\n", array_map(fn($h) => "- {$h}", $sectionHandles));
        return "# {$name}\n\nGenerated from an existing page via \"Save as Template\".\n\nSection order:\n\n{$list}\n";
    }

    private function buildPreviewTwig(array $sectionHandles): string
    {
        $includes = implode("\n", array_map(
            fn($h) => "    {% include \"@packages/{$h}/template.twig\" with { block: block['{$h}']|default({}) } only %}",
            $sectionHandles
        ));
        return "<div class=\"site7-template-preview\">\n{$includes}\n</div>\n";
    }
}
