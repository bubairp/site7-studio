<?php

namespace site7\studio\services\installation\executors;

use Craft;
use craft\enums\PropagationMethod;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\TagGroup;
use craft\models\Volume;
use site7\studio\interfaces\StepExecutorInterface;

/**
 * Creates or updates the native Craft resources Phase 2 captures - Asset
 * Volumes, Category/Tag Groups, and Craft Section settings - via official
 * Craft service APIs only (Volumes::saveVolume(), Categories::saveGroup(),
 * Tags::saveTagGroup(), Entries::saveSection()). Idempotent throughout:
 * looks up by handle first, updates in place if found, creates only if
 * missing - a repeated run never creates a duplicate.
 *
 * Two deliberate scope boundaries, both because Site7 Studio doesn't
 * capture the data that would be needed to do more safely:
 * - An Asset Volume's referenced filesystem (fsHandle) must already exist
 *   on the target - Filesystems aren't captured by any phase so far, and
 *   auto-provisioning one (path/permissions) is its own feature. Missing
 *   the referenced filesystem fails that one step, not the whole install.
 * - A Craft Section's own Entry Types/field layout are never created here -
 *   those arrive from the Section/Template package's own install cascade
 *   (StarterKitInstallationService, already existing). This executor only
 *   updates settings on a Section that already exists by the time it runs;
 *   a Section that doesn't exist yet is skipped, not partially created.
 */
class CraftResourceInstallExecutor implements StepExecutorInterface
{
    public function execute(array $steps, bool $dryRun): array
    {
        $results = [];

        foreach ($steps as $step) {
            $kind = $step->payload['kind'];
            $data = $step->payload['data'];

            if ($dryRun) {
                $results[] = ['step' => $step, 'status' => 'skipped', 'message' => "Dry run - would create/update {$kind} '{$data['handle']}'."];
                continue;
            }

            try {
                $message = match ($kind) {
                    'assetVolume' => $this->applyAssetVolume($data),
                    'categoryGroup' => $this->applyCategoryGroup($data),
                    'tagGroup' => $this->applyTagGroup($data),
                    'craftSection' => $this->applyCraftSection($data),
                    default => null,
                };
                if ($message === null) {
                    $results[] = ['step' => $step, 'status' => 'failed', 'message' => "Unknown craft-resource kind '{$kind}'."];
                    continue;
                }
                $status = str_starts_with($message, 'Skipped') ? 'skipped' : 'completed';
                $results[] = ['step' => $step, 'status' => $status, 'message' => $message];
            } catch (\Throwable $e) {
                $results[] = ['step' => $step, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function applyAssetVolume(array $data): string
    {
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle($data['handle']);
        $isNew = $volume === null;

        $fsHandle = $data['fsHandle'] ?? null;
        if ($fsHandle && !Craft::$app->getFs()->getFilesystemByHandle($fsHandle)) {
            return "Skipped Asset Volume '{$data['handle']}' - referenced filesystem '{$fsHandle}' does not exist on this site (Filesystems aren't captured/created by Site7 Studio; configure it first).";
        }

        if ($isNew) {
            $volume = new Volume();
            $volume->handle = $data['handle'];
        }

        $volume->name = $data['name'];
        if ($fsHandle) {
            $volume->fsHandle = $fsHandle;
        }
        if (!empty($data['transformFsHandle'])) {
            $volume->transformFsHandle = $data['transformFsHandle'];
        }
        $volume->transformSubpath = $data['transformSubpath'] ?? $volume->transformSubpath ?? '';
        $volume->titleTranslationMethod = $data['titleTranslationMethod'] ?? $volume->titleTranslationMethod;

        if (!$volumesService->saveVolume($volume)) {
            throw new \Exception(implode(' ', $volume->getFirstErrors()));
        }

        return $isNew ? "Created Asset Volume '{$data['handle']}'." : "Updated existing Asset Volume '{$data['handle']}'.";
    }

    private function applyCategoryGroup(array $data): string
    {
        $categoriesService = Craft::$app->getCategories();
        $group = $categoriesService->getGroupByHandle($data['handle']);
        $isNew = $group === null;

        if ($isNew) {
            $group = new CategoryGroup();
            $group->handle = $data['handle'];
            $group->setSiteSettings($this->buildCategorySiteSettings($data['siteSettings'] ?? []));
        }

        $group->name = $data['name'];
        $group->maxLevels = $data['maxLevels'] ?? null;
        if (!empty($data['defaultPlacement'])) {
            $group->defaultPlacement = $data['defaultPlacement'];
        }

        if (!$categoriesService->saveGroup($group)) {
            throw new \Exception(implode(' ', $group->getFirstErrors()));
        }

        return $isNew ? "Created Category Group '{$data['handle']}'." : "Updated existing Category Group '{$data['handle']}'.";
    }

    /** @return CategoryGroup_SiteSettings[] keyed by site ID, one per site currently on this Craft install */
    private function buildCategorySiteSettings(array $capturedSiteSettings): array
    {
        $byHandle = [];
        foreach ($capturedSiteSettings as $s) {
            $byHandle[$s['siteHandle']] = $s;
        }

        $result = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $captured = $byHandle[$site->handle] ?? null;
            $settings = new CategoryGroup_SiteSettings();
            $settings->siteId = $site->id;
            $settings->hasUrls = $captured['hasUrls'] ?? false;
            $settings->uriFormat = $captured['uriFormat'] ?? null;
            $settings->template = $captured['template'] ?? null;
            $result[$site->id] = $settings;
        }
        return $result;
    }

    private function applyTagGroup(array $data): string
    {
        $tagsService = Craft::$app->getTags();
        $tagGroup = $tagsService->getTagGroupByHandle($data['handle']);
        $isNew = $tagGroup === null;

        if ($isNew) {
            $tagGroup = new TagGroup();
            $tagGroup->handle = $data['handle'];
        }
        $tagGroup->name = $data['name'];

        if (!$tagsService->saveTagGroup($tagGroup)) {
            throw new \Exception(implode(' ', $tagGroup->getFirstErrors()));
        }

        return $isNew ? "Created Tag Group '{$data['handle']}'." : "Tag Group '{$data['handle']}' already existed (name refreshed).";
    }

    private function applyCraftSection(array $data): string
    {
        $entriesService = Craft::$app->getEntries();
        $section = $entriesService->getSectionByHandle($data['handle']);
        if (!$section) {
            return "Skipped Section '{$data['handle']}' - it does not exist on this site yet (Sections arrive via their Template package's own install, not this step).";
        }

        if (!empty($data['type'])) {
            $section->type = $data['type'];
        }
        $section->enableVersioning = $data['enableVersioning'] ?? $section->enableVersioning;
        $section->maxLevels = $data['maxLevels'] ?? $section->maxLevels;
        if (!empty($data['defaultPlacement'])) {
            $section->defaultPlacement = $data['defaultPlacement'];
        }
        if (!empty($data['propagationMethod'])) {
            $section->propagationMethod = PropagationMethod::from($data['propagationMethod']);
        }

        $capturedByHandle = [];
        foreach ($data['siteSettings'] ?? [] as $s) {
            $capturedByHandle[$s['siteHandle']] = $s;
        }
        foreach ($section->getSiteSettings() as $siteId => $siteSettings) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $captured = $site ? ($capturedByHandle[$site->handle] ?? null) : null;
            if ($captured) {
                $siteSettings->enabledByDefault = $captured['enabledByDefault'] ?? $siteSettings->enabledByDefault;
                $siteSettings->hasUrls = $captured['hasUrls'] ?? $siteSettings->hasUrls;
                $siteSettings->uriFormat = $captured['uriFormat'] ?? $siteSettings->uriFormat;
                $siteSettings->template = $captured['template'] ?? $siteSettings->template;
            }
        }

        if (!$entriesService->saveSection($section)) {
            throw new \Exception(implode(' ', $section->getFirstErrors()));
        }

        return "Updated settings for existing Section '{$data['handle']}'.";
    }
}
