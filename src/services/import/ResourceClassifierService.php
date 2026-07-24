<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use site7\studio\Site7Studio;

/**
 * Classifies every Craft resource (field) encountered during import.
 * "Unknown Resource" is never a final answer here - an Entries or Matrix
 * field is recursively inspected (its target Section/Entry Type resolved
 * and classified in turn) rather than stopping at the raw Craft field type,
 * and anything that genuinely can't be resolved automatically becomes
 * Review Required (with an explanation) instead of a silent Unknown.
 *
 * | Classification         | Action                 | Captured into fields.yaml? |
 * |------------------------|------------------------|-----------------------------|
 * | Shared Resource        | reference              | no - referenced by handle   |
 * | Package Resource       | reference              | no - referenced by handle   |
 * | Platform Configuration | reference (excluded)   | no                          |
 * | Plugin Dependency      | report-missing-plugin  | no - manifest dependency    |
 * | External Dependency    | report-missing-plugin  | no - manifest dependency    |
 * | Native Resource        | import                 | yes                         |
 * | Feature Dependency     | import                 | yes (Entries -> a Section)  |
 * | Nested Resource        | import                 | yes (Matrix -> its own type)|
 * | Reusable Component     | import                 | yes (Matrix -> a shared type)|
 * | Review Required        | report-dependency      | no                          |
 *
 * Consumes CraftResourceService::describeField()/describeFieldLayout()'s
 * output (unchanged) plus a `fieldClass` key (added alongside this service)
 * and enriches it with classification/action/statusLabel/detail - it never
 * duplicates the Craft-type mapping logic that lives in CraftResourceService.
 */
class ResourceClassifierService extends Component
{
    public const SHARED_RESOURCE = 'shared-resource';
    public const PACKAGE_RESOURCE = 'package-resource';
    public const PLATFORM_CONFIGURATION = 'platform-configuration';
    public const PLUGIN_DEPENDENCY = 'plugin-dependency';
    public const EXTERNAL_DEPENDENCY = 'external-dependency';
    public const FEATURE_RESOURCE = 'feature-resource';
    public const FEATURE_DEPENDENCY = 'feature-dependency';
    public const NESTED_RESOURCE = 'nested-resource';
    public const REUSABLE_COMPONENT = 'reusable-component';
    public const REVIEW_REQUIRED = 'review-required';
    /** @deprecated Kept only so manifests written before this classification pass still read back. classifyField() never returns this anymore - see REVIEW_REQUIRED. */
    public const UNKNOWN_RESOURCE = 'unknown-resource';

    /**
     * Classifications that represent a real field this package should
     * capture into its own fields.yaml (as opposed to a reference/
     * dependency the install resolves against something else, or a field
     * excluded entirely). Centralized here so every importer's "should I
     * capture this field's definition" check stays in sync with whatever
     * classifications this service produces - a classification added here
     * without also being added to this list would otherwise silently stop
     * being captured.
     */
    public const CAPTURABLE_CLASSIFICATIONS = [
        self::FEATURE_RESOURCE,
        self::FEATURE_DEPENDENCY,
        self::NESTED_RESOURCE,
        self::REUSABLE_COMPONENT,
    ];

    public static function isCapturable(string $classification): bool
    {
        return in_array($classification, self::CAPTURABLE_CLASSIFICATIONS, true);
    }

    /** Fields whose type is one of these are always shared (Craft-native singleton pointers). */
    private const ALWAYS_SHARED_TYPES = ['Assets'];

    private const ALWAYS_SHARED_CLASSES = [
        \craft\fields\Categories::class,
        \craft\fields\Tags::class,
    ];

    /**
     * Placeholder heuristic pending a full PlatformConfigService (future
     * phase) - a field whose own handle/name contains one of these signal
     * words is treated as site-wide configuration rather than feature
     * content. Approximate by design; documented as a placeholder in the
     * Phase 16 architecture doc.
     */
    private const PLATFORM_SIGNAL_WORDS = ['theme', 'colorpalette', 'colorlibrary', 'typography', 'spacing', 'codecss', 'codejs', 'containerwidth', 'animationpreset'];

    /** Best-effort namespace-prefix -> Composer package map for Plugin Dependency messaging. */
    private const PLUGIN_PACKAGE_MAP = [
        'ether\\seo' => 'ether/seo',
        'remoteprogrammer\\simplerpmenu' => 'remoteprogrammer/simple-rp-menu',
        'craft\\ckeditor' => 'craftcms/ckeditor',
        'verbb\\' => 'verbb plugin',
    ];

    /**
     * Minimum number of distinct Entry Types a field must appear on to be
     * considered structurally shared. Craft fields are inherently reusable/
     * global by design, so incidental reuse across 2-3 entry types (a
     * developer reusing an ordinary content field like a page title) is
     * common and NOT what this classification means - it's meant to catch
     * genuinely pervasive structural building blocks like blockStyle
     * (fan-out 31 in a real audited project) or button (fan-out 9), not
     * every mildly-reused field. Tuned well above typical incidental reuse.
     */
    private const SHARED_FAN_OUT_THRESHOLD = 6;

    /**
     * Classifies every field in a described field layout (from
     * CraftResourceService::describeFieldLayout()) in one pass, computing
     * fan-out once for the whole layout rather than per field.
     *
     * @param array<int, array{handle: string, name: string, type: string, instructions: string, supported: bool, fieldClass?: string}> $describedFields
     * @return array<int, array{handle: string, name: string, type: string, instructions: string, supported: bool, classification: string, action: string, statusLabel: string, detail: string, requiredPlugin?: string}>
     */
    public function classifyFieldLayout(array $describedFields): array
    {
        $fanOutMap = $this->buildFieldFanOutMap();
        $packageMap = $this->buildEntryTypeToPackageMap();
        $registry = Site7Studio::getInstance()->sharedResourceRegistry;

        $classified = [];
        foreach ($describedFields as $field) {
            $handle = $field['handle'] ?? '';
            $classified[] = $this->classifyField($field, [
                'fanOut' => $fanOutMap[$handle] ?? 0,
                'packageHandle' => $packageMap[$handle] ?? null,
                'isRegisteredShared' => $registry->getByHandle($handle) !== null,
            ]);
        }
        return $classified;
    }

    /**
     * @param array $field One entry from CraftResourceService::describeField().
     * @param array $context {fanOut: int, packageHandle: ?string, isRegisteredShared: bool}
     */
    public function classifyField(array $field, array $context = []): array
    {
        $type = $field['type'] ?? '';
        $fieldClass = $field['fieldClass'] ?? null;

        if (!empty($context['isRegisteredShared'])) {
            return $this->classified($field, self::SHARED_RESOURCE, 'reference', 'Shared Resource', 'Already registered as a Shared Resource - referencing the existing one.');
        }

        if (in_array($type, self::ALWAYS_SHARED_TYPES, true)) {
            return $this->classified($field, self::SHARED_RESOURCE, 'reference', 'Shared Resource Dependency', 'References an Asset Volume - shared project-wide, never duplicated.');
        }
        foreach (self::ALWAYS_SHARED_CLASSES as $class) {
            if ($fieldClass === $class || (class_exists($class) && is_a($fieldClass, $class, true))) {
                $kind = str_contains($class, 'Categories') ? 'Category Group' : 'Tag Group';
                return $this->classified($field, self::SHARED_RESOURCE, 'reference', 'Shared Resource Dependency', "References a {$kind} - shared project-wide, never duplicated.");
            }
        }

        if (!empty($context['packageHandle'])) {
            return $this->classified($field, self::PACKAGE_RESOURCE, 'reference', 'Package Resource', "Matches the installed package '{$context['packageHandle']}' - referencing it instead of duplicating.");
        }

        if ($fieldClass && !str_starts_with($fieldClass, 'craft\\') && !str_starts_with($fieldClass, 'site7\\studio\\')) {
            $requiredPlugin = $this->guessPluginPackage($fieldClass);
            $result = $this->classified($field, self::PLUGIN_DEPENDENCY, 'report-missing-plugin', 'Plugin Dependency', "Provided by the {$requiredPlugin} plugin - not imported; the plugin must be installed for this field to work.");
            $result['requiredPlugin'] = $requiredPlugin;
            return $result;
        }

        if (($context['fanOut'] ?? 0) >= self::SHARED_FAN_OUT_THRESHOLD) {
            $count = $context['fanOut'];
            return $this->classified($field, self::SHARED_RESOURCE, 'reference', 'Shared Resource', "Used across {$count} Entry Types - shared, referencing the existing field instead of duplicating it.");
        }

        if ($this->matchesPlatformSignal($field['handle'] ?? '')) {
            return $this->classified($field, self::PLATFORM_CONFIGURATION, 'reference', 'Platform Configuration', 'Site-wide configuration value - excluded from the package, referenced informationally only.');
        }

        // A relationship field's own Craft type never explains what it's
        // actually for - inspect what it targets instead of stopping here.
        if ($type === 'Entries') {
            return $this->classifyEntriesField($field);
        }
        if ($type === 'Matrix') {
            return $this->classifyMatrixField($field, $context);
        }

        if (!empty($field['supported'])) {
            return $this->classified($field, self::FEATURE_RESOURCE, 'import', 'Native Resource', 'A plain Craft field - captured into this package.');
        }

        return $this->classified($field, self::REVIEW_REQUIRED, 'report-dependency', 'Review Required', "Field type '{$type}' could not be classified automatically - review manually before relying on this package.");
    }

    /**
     * An Entries field's own Craft type says nothing about what it's for -
     * "references entries" could mean anything from "picks a hero image's
     * linked page" to "lists every post in the Blog". Resolving its actual
     * source Section(s) turns that into an honest, specific dependency
     * instead of an opaque "Unknown Resource".
     */
    private function classifyEntriesField(array $field): array
    {
        $sectionHandles = array_filter((array)($field['settings']['sectionHandles'] ?? []));
        if (empty($sectionHandles)) {
            // sources === '*' (no restriction) - there's no specific target
            // to name, so this can't be resolved automatically.
            return $this->classified($field, self::REVIEW_REQUIRED, 'report-dependency', 'Review Required', 'Entries field allows any Section - no specific target to resolve automatically; review manually.');
        }

        $entriesService = Craft::$app->getEntries();
        $targets = [];
        foreach ($sectionHandles as $sectionHandle) {
            $section = $entriesService->getSectionByHandle((string)$sectionHandle);
            if ($section) {
                $targets[] = ['handle' => $section->handle, 'name' => $section->name, 'sectionType' => $section->type];
            }
        }

        if (empty($targets)) {
            return $this->classified($field, self::REVIEW_REQUIRED, 'report-dependency', 'Review Required', 'Entries field references a Section that no longer exists - review manually.');
        }

        $names = implode(', ', array_map(fn($t) => $t['name'], $targets));
        $result = $this->classified(
            $field,
            self::FEATURE_DEPENDENCY,
            'import',
            'Feature Dependency',
            "References the {$names} Section - that Section must exist (with content) for this field to have anything to select."
        );
        $result['targetSections'] = $targets;
        return $result;
    }

    /**
     * A nested Matrix field's own Craft type is equally uninformative - the
     * real question is whether the Entry Type(s) it composes are specific to
     * this component (Nested Resource, captured alongside it) or already a
     * broadly-reused building block referenced from several Matrix fields
     * project-wide (Reusable Component, still captured here but flagged as
     * shared so a developer doesn't assume it's private to this Section).
     */
    private function classifyMatrixField(array $field, array $context): array
    {
        $entryTypeHandles = array_filter((array)($field['settings']['entryTypeHandles'] ?? []));
        if (empty($entryTypeHandles)) {
            return $this->classified($field, self::REVIEW_REQUIRED, 'report-dependency', 'Review Required', 'Matrix field has no configured Entry Types - review manually.');
        }

        $entriesService = Craft::$app->getEntries();
        $nestedTypes = [];
        $anyBroadlyReused = false;
        foreach ($entryTypeHandles as $entryTypeHandle) {
            $entryType = $entriesService->getEntryTypeByHandle((string)$entryTypeHandle);
            if (!$entryType) {
                continue;
            }
            $fanOut = $context['matrixEntryTypeFanOut'][$entryType->handle] ?? $this->countMatrixFieldsAllowing($entryType->handle);
            if ($fanOut >= self::SHARED_FAN_OUT_THRESHOLD) {
                $anyBroadlyReused = true;
            }
            $nestedTypes[] = ['handle' => $entryType->handle, 'name' => $entryType->name, 'fanOut' => $fanOut];
        }

        if (empty($nestedTypes)) {
            return $this->classified($field, self::REVIEW_REQUIRED, 'report-dependency', 'Review Required', 'Matrix field references Entry Types that no longer exist - review manually.');
        }

        $names = implode(', ', array_map(fn($t) => $t['name'], $nestedTypes));
        if ($anyBroadlyReused) {
            $result = $this->classified($field, self::REUSABLE_COMPONENT, 'import', 'Reusable Component', "Composes the {$names} Entry Type, already used across multiple Matrix fields project-wide - captured here too, referencing the same existing Entry Type rather than duplicating it.");
        } else {
            $result = $this->classified($field, self::NESTED_RESOURCE, 'import', 'Nested Resource', "Composes the {$names} Entry Type - specific to this component, captured alongside it.");
        }
        $result['nestedEntryTypes'] = $nestedTypes;
        return $result;
    }

    /**
     * How many Matrix fields project-wide allow the given Entry Type - the
     * same "is this structurally shared" signal buildFieldFanOutMap() gives
     * for field handles, but for a Matrix field's nested Entry Type instead.
     * A live, uncached scan - only run per distinct nested Entry Type
     * encountered during an Analyze pass, not a hot path.
     */
    private function countMatrixFieldsAllowing(string $entryTypeHandle): int
    {
        $count = 0;
        foreach (Craft::$app->getFields()->getAllFields() as $liveField) {
            if (!$liveField instanceof \craft\fields\Matrix) {
                continue;
            }
            foreach ($liveField->getEntryTypes() as $entryType) {
                if ($entryType->handle === $entryTypeHandle) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    private function classified(array $field, string $classification, string $action, string $label, string $detail): array
    {
        return array_merge($field, [
            'classification' => $classification,
            'action' => $action,
            'statusLabel' => $label,
            'detail' => $detail,
        ]);
    }

    private function matchesPlatformSignal(string $handle): bool
    {
        $lower = strtolower($handle);
        foreach (self::PLATFORM_SIGNAL_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }
        return false;
    }

    private function guessPluginPackage(string $fieldClass): string
    {
        foreach (self::PLUGIN_PACKAGE_MAP as $prefix => $package) {
            if (str_starts_with($fieldClass, $prefix)) {
                return $package;
            }
        }
        $parts = explode('\\', $fieldClass);
        return $parts[0] . '\\' . ($parts[1] ?? '') . ' plugin';
    }

    /**
     * One pass over every Entry Type in the project, counting how many
     * distinct Entry Types each field handle appears on - far cheaper than
     * calling getFieldLayout()->getFieldByHandle() once per field per entry
     * type (O(fields x entryTypes) vs. this single O(entryTypes) pass).
     *
     * @return array<string, int>
     */
    private function buildFieldFanOutMap(): array
    {
        $map = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            foreach ($entryType->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                $map[$field->handle] = ($map[$field->handle] ?? 0) + 1;
            }
        }
        return $map;
    }

    /**
     * [entryTypeHandle => site7 package handle] for every installed Section
     * package - mirrors TemplateGeneratorService::buildEntryTypeToSectionMap()'s
     * private lookup (kept separate rather than made public cross-service,
     * per that service staying untouched).
     *
     * @return array<string, string>
     */
    private function buildEntryTypeToPackageMap(): array
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
            $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
            $entryTypeHandle = $matrixData['blocks'][0]['handle'] ?? null;
            if ($entryTypeHandle) {
                $map[$entryTypeHandle] = $pkg->handle;
            }
        }

        return $map;
    }
}
