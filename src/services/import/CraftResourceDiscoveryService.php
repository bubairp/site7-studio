<?php

namespace site7\studio\services\import;

use Craft;
use craft\base\Component;
use craft\base\Field;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\Tags;
use craft\models\EntryType;
use site7\studio\models\import\EntryTypeDiscoveryResult;
use site7\studio\Site7Studio;

/**
 * The Craft Resource Discovery Engine (Phase 17). Classifies every Matrix
 * Entry Type in the project against the real Craft resource graph - never a
 * hardcoded handle list - before "Import Existing Section"'s Select step
 * shows it to the user. Pure read/compute, no writes, no package generation:
 * this only feeds a smarter Select step ahead of the existing, unmodified
 * Analyze -> Preview -> Save pipeline (ResourceAnalyzerService/
 * MatrixEntryTypeImportService/CraftSectionImportService/
 * PackageManagerService are untouched by this service).
 *
 * Builds on Phase 16's field-level ResourceClassifierService (reused
 * unmodified as an input signal - not duplicated) to add a higher-level,
 * whole-Entry-Type classification into six buckets: Presentation Section,
 * Feature Component, Shared Resource, Utility Component, Plugin Component,
 * Unknown. Every signal is a live Craft API read; nothing is guessed from
 * naming alone except the deliberately-narrow, documented heuristics also
 * inherited from Phase 16 (PLATFORM_SIGNAL_WORDS-style approximations).
 */
class CraftResourceDiscoveryService extends Component
{
    public const PRESENTATION_SECTION = 'presentation-section';
    public const FEATURE_COMPONENT = 'feature-component';
    public const SHARED_RESOURCE = 'shared-resource';
    public const UTILITY_COMPONENT = 'utility-component';
    public const PLUGIN_COMPONENT = 'plugin-component';
    public const UNKNOWN = 'unknown';

    /** An Entry Type used as an allowed block on this many (or more) Matrix fields project-wide is structurally shared. */
    private const SHARED_USAGE_THRESHOLD = 2;

    /** Minimum top-bucket score to trust a classification at all. */
    private const MIN_CONFIDENCE = 35;

    /** If the top bucket doesn't beat the runner-up by at least this many points, the call is too close to trust automatically. */
    private const MIN_MARGIN = 15;

    private const UTILITY_NAME_PATTERNS = ['style', 'container', 'row', 'column', 'matrix'];

    private const RECOMMENDATIONS = [
        self::PRESENTATION_SECTION => 'Create Section Package',
        self::FEATURE_COMPONENT => 'Requires Feature Bundle',
        self::SHARED_RESOURCE => 'Convert to Shared Resource',
        self::UTILITY_COMPONENT => 'Internal Utility',
        self::PLUGIN_COMPONENT => 'Manual Review',
        self::UNKNOWN => 'Manual Review',
    ];

    /**
     * @return array{presentationSections: EntryTypeDiscoveryResult[], featureComponents: EntryTypeDiscoveryResult[], sharedResources: EntryTypeDiscoveryResult[], utilities: EntryTypeDiscoveryResult[], pluginComponents: EntryTypeDiscoveryResult[], unknown: EntryTypeDiscoveryResult[]}
     */
    public function discoverEntryTypes(): array
    {
        $matrixFieldsByEntryType = $this->buildMatrixFieldsByEntryTypeMap();
        $site7MatrixHandle = $this->getSite7MatrixFieldHandle();

        $groups = [
            'presentationSections' => [],
            'featureComponents' => [],
            'sharedResources' => [],
            'utilities' => [],
            'pluginComponents' => [],
            'unknown' => [],
        ];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $result = $this->analyzeEntryType($entryType, $matrixFieldsByEntryType, $site7MatrixHandle, false);

            $groups[match ($result->classification) {
                self::PRESENTATION_SECTION => 'presentationSections',
                self::FEATURE_COMPONENT => 'featureComponents',
                self::SHARED_RESOURCE => 'sharedResources',
                self::UTILITY_COMPONENT => 'utilities',
                self::PLUGIN_COMPONENT => 'pluginComponents',
                default => 'unknown',
            }][] = $result;
        }

        return $groups;
    }

    public function getEntryTypeDetail(int $entryTypeId): EntryTypeDiscoveryResult
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
        if (!$entryType) {
            throw new \Exception('Entry Type not found.');
        }

        $matrixFieldsByEntryType = $this->buildMatrixFieldsByEntryTypeMap();
        $site7MatrixHandle = $this->getSite7MatrixFieldHandle();

        return $this->analyzeEntryType($entryType, $matrixFieldsByEntryType, $site7MatrixHandle, true);
    }

    /**
     * Every Matrix field in the project, for the Select step's "filter by
     * Matrix field" dropdown - discovered dynamically (never hardcoded), so
     * a project with more than one content Matrix (e.g. a "matrixContent"
     * field alongside this plugin's own "site7Components") can browse either.
     *
     * @return array<int, array{handle: string, name: string}>
     */
    public function getMatrixFields(): array
    {
        $fields = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof Matrix) {
                $fields[] = ['handle' => $field->handle, 'name' => $field->name];
            }
        }
        return $fields;
    }

    /**
     * @param array<string, string[]> $matrixFieldsByEntryType entryTypeHandle => [Matrix field handles]
     */
    private function analyzeEntryType(EntryType $entryType, array $matrixFieldsByEntryType, ?string $site7MatrixHandle, bool $includeFieldDetail): EntryTypeDiscoveryResult
    {
        $result = new EntryTypeDiscoveryResult(['id' => $entryType->id, 'handle' => $entryType->handle, 'name' => $entryType->name]);

        $craftResourceService = Site7Studio::getInstance()->craftResourceGenerator;
        $classifier = new ResourceClassifierService();
        $layout = $entryType->getFieldLayout();
        $describedFields = $layout ? $craftResourceService->describeFieldLayout($layout) : [];
        $classifiedFields = $classifier->classifyFieldLayout($describedFields);
        if ($includeFieldDetail) {
            $result->fields = array_map(fn($f) => [
                'handle' => $f['handle'],
                'name' => $f['name'],
                'type' => $f['type'],
                'classification' => $f['classification'],
                'statusLabel' => $f['statusLabel'],
            ], $classifiedFields);
        }

        $matrixFieldHandles = $matrixFieldsByEntryType[$entryType->handle] ?? [];
        $usageCount = count($matrixFieldHandles);
        $result->usageCount = $usageCount;
        $result->referencedBy = array_map(function ($h) {
            $field = Craft::$app->getFields()->getFieldByHandle($h);
            return ['handle' => $h, 'name' => $field?->name ?? $h];
        }, $matrixFieldHandles);

        $isRegisteredShared = Site7Studio::getInstance()->sharedResourceRegistry->getByHandle($entryType->handle) !== null;
        $isInSite7Matrix = $site7MatrixHandle && in_array($site7MatrixHandle, $matrixFieldHandles, true);

        $dependencies = [];
        $warnings = [];
        $pluginRequirements = [];
        $sharedResourceHandles = [];
        $hasNestedMatrix = false;
        $craftSectionDependency = false;
        $platformCount = 0;
        $pluginCount = 0;

        foreach ($layout?->getCustomFields() ?? [] as $field) {
            $classified = null;
            foreach ($classifiedFields as $cf) {
                if ($cf['handle'] === $field->handle) {
                    $classified = $cf;
                    break;
                }
            }

            if ($field instanceof Matrix) {
                $hasNestedMatrix = true;
                $dependencies[] = ['kind' => 'template', 'handle' => $field->handle, 'name' => $field->name, 'detail' => 'Nested Matrix field - this Entry Type composes other Matrix content.'];
                $warnings[] = "{$field->name} - Type: Matrix, Status: Template Dependency. Nested Matrix field; its own block types are a separate dependency.";
            }

            if ($field instanceof Entries) {
                $sectionNames = $this->resolveEntriesFieldSections($field);
                if (!empty($sectionNames)) {
                    $craftSectionDependency = true;
                    foreach ($sectionNames as $sectionName) {
                        $dependencies[] = ['kind' => 'craft-section', 'handle' => $field->handle, 'name' => $sectionName, 'detail' => "Queries the Craft Section '{$sectionName}'."];
                        $warnings[] = "{$field->handle} - Type: Entries, Status: Craft Section Dependency. Queries the Craft Section '{$sectionName}'.";
                    }
                } else {
                    $craftSectionDependency = true;
                    $dependencies[] = ['kind' => 'craft-section', 'handle' => $field->handle, 'name' => 'Any Section', 'detail' => 'Queries entries with no specific Section restriction.'];
                    $warnings[] = "{$field->handle} - Type: Entries, Status: Craft Section Dependency. Queries entries across all Sections.";
                }
            }

            if ($this->isNavigationField($field)) {
                $fieldClassName = get_class($field);
                $dependencies[] = ['kind' => 'navigation', 'handle' => $field->handle, 'name' => $field->name, 'detail' => 'Navigation menu field (remoteprogrammer/simple-rp-menu).'];
                $warnings[] = "{$field->handle} - Type: {$fieldClassName}, Status: Navigation Dependency. Requires the Simple RP Menus plugin.";
            }

            if (!$classified) {
                continue;
            }

            switch ($classified['classification']) {
                case ResourceClassifierService::SHARED_RESOURCE:
                    $sharedResourceHandles[] = $field->handle;
                    $kind = $field instanceof Assets ? 'asset' : ($field instanceof Categories || $field instanceof Tags ? 'category' : 'shared-resource');
                    $dependencies[] = ['kind' => $kind, 'handle' => $field->handle, 'name' => $field->name, 'detail' => $classified['detail']];
                    $warnings[] = "{$field->handle} - Type: {$classified['type']}, Status: {$classified['statusLabel']}. {$classified['detail']}";
                    break;
                case ResourceClassifierService::PLATFORM_CONFIGURATION:
                    $platformCount++;
                    break;
                case ResourceClassifierService::PLUGIN_DEPENDENCY:
                    $pluginCount++;
                    $pluginRequirements[] = ['handle' => $field->handle, 'requiredPlugin' => $classified['requiredPlugin'] ?? 'unknown'];
                    $dependencies[] = ['kind' => 'plugin', 'handle' => $field->handle, 'name' => $field->name, 'detail' => $classified['detail']];
                    $warnings[] = "{$field->handle} - Type: {$classified['type']}, Status: {$classified['statusLabel']}. {$classified['detail']}";
                    break;
                case ResourceClassifierService::UNKNOWN_RESOURCE:
                    $dependencies[] = ['kind' => 'unknown', 'handle' => $field->handle, 'name' => $field->name, 'detail' => $classified['detail']];
                    $warnings[] = "{$field->handle} - Type: {$classified['type']}, Status: {$classified['statusLabel']}. {$classified['detail']}";
                    break;
            }
        }

        $totalFields = count($classifiedFields) ?: 1;
        $utilityNameMatch = $this->matchesUtilityNaming($entryType->handle) || $this->matchesUtilityNaming($entryType->name);

        $scoring = self::scoreClassification([
            'isRegisteredShared' => $isRegisteredShared,
            'usageCount' => $usageCount,
            'isInSite7Matrix' => $isInSite7Matrix,
            'craftSectionDependency' => $craftSectionDependency,
            'hasNestedMatrix' => $hasNestedMatrix,
            'platformFieldCount' => $platformCount,
            'pluginFieldCount' => $pluginCount,
            'totalFieldCount' => $totalFields,
            'utilityNameMatch' => $utilityNameMatch,
        ]);
        $result->classification = $scoring['classification'];
        $result->confidence = $scoring['confidence'];
        $result->reviewRequired = $scoring['reviewRequired'];

        if (empty($dependencies) && empty($warnings)) {
            $warnings[] = 'No dependencies detected - this Entry Type is self-contained.';
        }

        $result->dependencies = $dependencies;
        $result->warnings = $warnings;
        $result->pluginRequirements = $pluginRequirements;
        $result->sharedResources = array_values(array_unique($sharedResourceHandles));
        $result->recommendation = $result->reviewRequired ? 'Manual Review' : self::RECOMMENDATIONS[$result->classification];
        $result->estimatedPackageSize = $this->estimateSize($classifiedFields);

        return $result;
    }

    /**
     * The pure scoring function - given precomputed signals (booleans/counts
     * only, no Craft API calls), picks the highest-scoring bucket and
     * decides whether it's trustworthy enough to apply automatically.
     * Extracted from analyzeEntryType() specifically so it's testable
     * without a live Craft app (see CraftResourceDiscoveryServiceTest).
     *
     * @param array $signals {isRegisteredShared: bool, usageCount: int, isInSite7Matrix: bool,
     *   craftSectionDependency: bool, hasNestedMatrix: bool, platformFieldCount: int,
     *   pluginFieldCount: int, totalFieldCount: int, utilityNameMatch: bool}
     * @return array{classification: string, confidence: int, reviewRequired: bool}
     */
    public static function scoreClassification(array $signals): array
    {
        $totalFields = max(1, (int)($signals['totalFieldCount'] ?? 1));

        $scores = [
            self::SHARED_RESOURCE => (!empty($signals['isRegisteredShared']) ? 100 : 0)
                + (($signals['usageCount'] ?? 0) >= self::SHARED_USAGE_THRESHOLD ? min(60, ($signals['usageCount'] ?? 0) * 10) : 0),
            self::PRESENTATION_SECTION => !empty($signals['isInSite7Matrix']) ? 100 : 0,
            self::FEATURE_COMPONENT => (!empty($signals['craftSectionDependency']) ? 60 : 0) + (!empty($signals['hasNestedMatrix']) ? 20 : 0),
            self::UTILITY_COMPONENT => (int)round((($signals['platformFieldCount'] ?? 0) / $totalFields) * 80) + (!empty($signals['utilityNameMatch']) ? 20 : 0),
            self::PLUGIN_COMPONENT => ($signals['pluginFieldCount'] ?? 0) > 0
                ? (int)round((($signals['pluginFieldCount'] ?? 0) / $totalFields) * 70) + 10
                : 0,
        ];
        arsort($scores);
        $topBucket = array_key_first($scores);
        $topScore = $scores[$topBucket];
        $secondScore = array_values($scores)[1] ?? 0;

        if ($topScore < self::MIN_CONFIDENCE) {
            return ['classification' => self::UNKNOWN, 'confidence' => $topScore, 'reviewRequired' => true];
        }

        return [
            'classification' => $topBucket,
            'confidence' => min(100, $topScore),
            'reviewRequired' => ($topScore - $secondScore) < self::MIN_MARGIN,
        ];
    }

    /**
     * One pass over every Matrix field in the project, recording which
     * Entry Types each one allows - the "used as a block on N Matrix
     * fields" fan-out signal, and the "is this entry type one of the Site7
     * Matrix field's own allowed types" presentation signal.
     *
     * @return array<string, string[]> entryTypeHandle => [Matrix field handles]
     */
    private function buildMatrixFieldsByEntryTypeMap(): array
    {
        $map = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }
            foreach ($field->getEntryTypes() as $entryType) {
                $map[$entryType->handle][] = $field->handle;
            }
        }
        return $map;
    }

    private function getSite7MatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        return Craft::$app->getFields()->getFieldById($settings->matrixFieldId)?->handle;
    }

    /**
     * @return string[] Names of the Craft Sections this Entries field is scoped to (empty if unrestricted/unresolvable).
     */
    private function resolveEntriesFieldSections(Entries $field): array
    {
        $sources = $field->sources;
        if (!is_array($sources)) {
            return [];
        }

        $names = [];
        foreach ($sources as $source) {
            if (!is_string($source) || !str_starts_with($source, 'section:')) {
                continue;
            }
            $uid = substr($source, strlen('section:'));
            $section = Craft::$app->getEntries()->getSectionByUid($uid);
            if ($section) {
                $names[] = $section->name;
            }
        }
        return $names;
    }

    private function isNavigationField(Field $field): bool
    {
        return str_starts_with(get_class($field), 'remoteprogrammer\\simplerpmenu');
    }

    private function matchesUtilityNaming(string $value): bool
    {
        $lower = strtolower($value);
        foreach (self::UTILITY_NAME_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rough browsing-time size estimate - deliberately not the same
     * computation as ResourceAnalyzerService::estimateSize() (that service
     * is part of the frozen Analyze/Preview/Save pipeline this phase must
     * not touch); this is only for the Select step's Resource Detail view.
     */
    private function estimateSize(array $classifiedFields): int
    {
        $featureFieldCount = count(array_filter($classifiedFields, fn($f) => $f['classification'] === ResourceClassifierService::FEATURE_RESOURCE));
        return 512 + ($featureFieldCount * 96);
    }
}
