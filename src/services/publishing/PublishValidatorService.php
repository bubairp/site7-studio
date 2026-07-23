<?php

namespace site7\studio\services\publishing;

use Craft;
use craft\base\Component;
use site7\studio\events\publishing\AfterValidationEvent;
use site7\studio\events\publishing\BeforeValidationEvent;
use site7\studio\interfaces\PackageValidatorInterface;
use site7\studio\models\publishing\PublishReadinessResult;
use site7\studio\services\PackageExportService;
use site7\studio\Site7Studio;

/**
 * Publish-readiness validation - see PackageValidatorInterface's docblock
 * for why this is new and separate from the frozen Package Engine validator.
 *
 * Runs two kinds of check:
 *   - Hard errors (block publishing): manifest invalid, package not found,
 *     dependency closure unresolvable, duplicate handle already published
 *     to a repository the caller isn't overwriting.
 *   - Quality checks (feed the 0-100 score, never block publishing on their
 *     own): required documentation (README), required preview image,
 *     required metadata (description/category/author/license/keywords),
 *     required assets (a Section's template.twig/fields.yaml), package
 *     naming (handle is kebab-case), semantic versioning (version matches
 *     MAJOR.MINOR.PATCH), compatibility (minimumCraftVersion/minimumSite7Version set).
 */
class PublishValidatorService extends Component implements PackageValidatorInterface
{
    private const QUALITY_CHECK_COUNT = 7;

    /**
     * @inheritdoc
     */
    public function validatePackage(string $handle): PublishReadinessResult
    {
        $dispatcher = Site7Studio::getInstance()->getService('eventDispatcher');
        $dispatcher->dispatch(new BeforeValidationEvent(['handle' => $handle]));

        $result = new PublishReadinessResult();

        $packageManager = Site7Studio::getInstance()->packageManager;
        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            $result->errors[] = "Package '{$handle}' was not found.";
            $this->finish($result, $dispatcher, $handle);
            return $result;
        }

        $packagePath = $packageManager->getPackagePath($handle);
        if (!$packagePath) {
            $result->errors[] = 'Package directory could not be located on disk.';
            $this->finish($result, $dispatcher, $handle);
            return $result;
        }

        $manifest = $record->getManifest();
        if (!$manifest || !$manifest->validate()) {
            $result->errors[] = 'manifest.json is missing or invalid.';
            $this->finish($result, $dispatcher, $handle);
            return $result;
        }

        // Dependency closure must be fully resolvable, or the archive would
        // be built incomplete/broken - reuses the exact same resolver
        // export already depends on, rather than re-walking `requires` here.
        try {
            (new PackageExportService())->resolveDependencyClosure($handle);
        } catch (\Throwable $e) {
            $result->errors[] = 'Dependencies could not be resolved: ' . $e->getMessage();
        }

        $passedChecks = 0;

        // 1. Required documentation
        if (file_exists($packagePath . '/README.md') && trim((string)file_get_contents($packagePath . '/README.md')) !== '') {
            $passedChecks++;
        } else {
            $result->warnings[] = 'No README.md - add one describing what this package does.';
        }

        // 2. Required preview
        $hasPreview = false;
        foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
            if (file_exists($packagePath . '/preview/preview.' . $ext)) {
                $hasPreview = true;
                break;
            }
        }
        if ($hasPreview) {
            $passedChecks++;
        } else {
            $result->warnings[] = 'No preview image - upload one from the Package Editor.';
        }

        // 3. Required metadata
        $missingMetadata = array_filter([
            'description' => empty($record->description),
            'category' => empty($record->category),
            'author' => empty($record->author),
            'license' => empty($manifest->license),
        ]);
        if (empty($missingMetadata)) {
            $passedChecks++;
        } else {
            $result->warnings[] = 'Missing metadata: ' . implode(', ', array_keys($missingMetadata)) . '.';
        }

        // 4. Required assets (type-specific)
        $requiredAssetsPresent = match ($record->type) {
            'section' => file_exists($packagePath . '/template.twig') && file_exists($packagePath . '/fields.yaml'),
            default => true,
        };
        if ($requiredAssetsPresent) {
            $passedChecks++;
        } else {
            $result->warnings[] = 'Missing required assets for a Section package (template.twig/fields.yaml).';
        }

        // 5. Package naming
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $handle)) {
            $passedChecks++;
        } else {
            $result->warnings[] = 'Handle should be lowercase, kebab-case (e.g. "hero-banner").';
        }

        // 6. Semantic versioning
        if (preg_match('/^\d+\.\d+\.\d+$/', $record->version)) {
            $passedChecks++;
        } else {
            $result->warnings[] = "Version '{$record->version}' is not semantic (expected MAJOR.MINOR.PATCH).";
        }

        // 7. Compatibility
        if (!empty($manifest->minimumCraftVersion) || !empty($manifest->minimumSite7Version)) {
            $passedChecks++;
        } else {
            $result->warnings[] = 'No minimum Craft/Site7 version set - add one on the Publish wizard\'s Metadata step.';
        }

        $result->score = (int)round(($passedChecks / self::QUALITY_CHECK_COUNT) * 100);
        $result->label = match (true) {
            $result->score >= 90 => 'Ready for Marketplace',
            $result->score >= 60 => 'Needs minor improvements',
            default => 'Needs work before publishing',
        };

        $this->finish($result, $dispatcher, $handle);

        return $result;
    }

    private function finish(PublishReadinessResult $result, $dispatcher, string $handle): void
    {
        $result->valid = empty($result->errors);
        $dispatcher->dispatch(new AfterValidationEvent(['handle' => $handle, 'result' => $result]));
    }
}
