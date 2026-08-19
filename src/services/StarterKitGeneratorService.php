<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\Section;
use craft\web\UploadedFile;
use site7\studio\services\import\PageImportService;
use site7\studio\Site7Studio;
use site7\studio\records\PackageRecord;
use site7\studio\repositories\PageImportSourceRepository;
use site7\studio\repositories\WebsiteImportSourceRepository;

/**
 * Generates a Starter Kit package from a set of existing Entries ("Save Current
 * Site as Starter Kit"). Phase 10 scope: Pages + Templates only - Navigation,
 * Globals, Categories, Assets, and SEO are deferred to later increments.
 *
 * A Starter Kit never stores page content itself - each selected Entry is run
 * through the existing TemplateGeneratorService (the same "Save as Template"
 * path used elsewhere), and the Starter Kit's manifest records only a reference
 * to the resulting Template handle plus the page's own structural identity
 * (title/slug/section/entry type). Installing a Starter Kit replays that list
 * through the existing Create-from-Template mechanism.
 *
 * Single-section "site structure" content (Header/Footer/General/Theme-style
 * pages - see PackageManifest::$siteStructure) is captured separately and
 * unconditionally by captureSiteStructure(), regardless of which pages were
 * selected - it is not part of the opt-in page checklist at all. This is
 * intentionally distinct from manifest->globals, which captures actual Craft
 * GlobalSet elements via a different flow (WebsiteImportService); Single
 * sections in this project are ordinary Sections with exactly one Entry, not
 * GlobalSets, so they need their own capture path.
 */
class StarterKitGeneratorService extends Component
{
    /**
     * Single sections whose content is genuinely page-like (has its own navigable
     * content, conceptually a real "page" a visitor lands on) rather than pure
     * structural chrome (Header/Footer/General/etc., which only ever configures how
     * OTHER pages render). Excluded from captureSiteStructure()'s automatic,
     * always-on capture - these are handled through the normal Template/page
     * system instead (the "Pages to Include" picker), exactly like any
     * Channel/Structure page, per explicit decision - see
     * docs/32_STARTER_KIT_SYSTEM.md 14.1.12. Referenced by
     * StarterKitGeneratorController to keep these Singles visible/selectable in the
     * picker even when they currently have no Site7 Matrix content of their own
     * (without this, they'd have no capture path at all once excluded here).
     *
     * Site-specific by nature (this project's actual Section handles) - not a
     * general rule Craft or this plugin can infer automatically, since nothing
     * about a Single's own data distinguishes "real page" from "structural
     * settings." Revisit this list directly if this project's Section handles
     * for Home/Contact-equivalent pages ever change, or extend it for other sites.
     *
     * @var string[]
     */
    public const PAGE_LIKE_SINGLE_SECTIONS = ['home', 'contact'];

    /**
     * @param Entry[] $entries The Entries to capture as pages.
     * @param array $meta {name, description, version?, category, tags, previewImage?: UploadedFile}
     * @param string|null $existingStarterKitHandle When given, overwrites the existing
     *   Starter Kit package at this handle in place ("Update Starter Kit") instead of
     *   minting a new one via generateUniqueHandle(). The package must already exist
     *   and be a starter-kit. Any Template packages referenced by the OLD manifest but
     *   no longer selected are left on disk untouched (never auto-deleted) - they
     *   simply stop being referenced by the updated manifest's pages/requires.templates.
     *   Named distinctly from the per-entry loop's own local reuse-handle variable
     *   (formerly both were called $existingHandle, silently shadowing this parameter
     *   inside the loop and corrupting the later "was this an Update" check below -
     *   see docs/32_STARTER_KIT_SYSTEM.md 14.1.8).
     * @return array{0: PackageRecord, 1: string[]} [the new/updated Starter Kit package, per-entry skip reasons]
     * @throws \Exception if none of the given entries could be captured, or if
     *   $existingStarterKitHandle doesn't resolve to an existing Starter Kit package.
     */
    public function generateFromEntries(array $entries, array $meta, ?string $existingStarterKitHandle = null): array
    {
        $packageManager = Site7Studio::getInstance()->packageManager;
        $isUpdate = $existingStarterKitHandle !== null;

        if ($isUpdate) {
            $existing = $packageManager->getPackageByHandle($existingStarterKitHandle);
            if (!$existing || $existing->type !== 'starter-kit') {
                throw new \Exception("Starter Kit '{$existingStarterKitHandle}' was not found, so it can't be updated.");
            }
            // Refuse to overwrite a Starter Kit that was actually produced by the
            // separate, incompatible "Import Existing Website" flow
            // (WebsiteImportService) - confirmed live as real data corruption:
            // overwriting one's manifest.json with this method's own shape while
            // leaving WebsiteImportSourceRepository's tracking record (which
            // authoring/edit.twig's read-only view for THAT flow relies on) stale
            // and pointing at the wrong content. The CP UI already hides "Update
            // Starter Kit" for these (see library/package.twig's
            // isWebsiteImportedStarterKit guard); this is the defense-in-depth
            // guard so no other caller can trigger the same corruption. See
            // docs/32_STARTER_KIT_SYSTEM.md 14.1.10.
            if ((new WebsiteImportSourceRepository())->findByPackageId($existing->id) !== null) {
                throw new \Exception("Starter Kit '{$existingStarterKitHandle}' was created via \"Import Existing Website\" and can't be updated here - use Synchronize Starter Kit on its Package Editor page instead.");
            }
            $handle = $existingStarterKitHandle;
        } else {
            $handle = $this->generateUniqueHandle($meta['name']);
        }

        $packagePath = rtrim(Craft::getAlias('@packages'), '/') . '/' . $handle;
        FileHelper::createDirectory($packagePath);

        $templateGenerator = new TemplateGeneratorService();

        $pages = [];
        $requiresTemplates = [];
        $skipped = [];

        $sourceRepo = new PageImportSourceRepository();

        foreach ($entries as $entry) {
            // Reuse an existing Template package for this exact Entry ONLY when it
            // was genuinely captured elsewhere already (e.g. via "Import Existing
            // Page" - a read-only lookup, never a write, into
            // PageImportSourceRepository). Deliberately NOT recorded back into that
            // same repository for a freshly-generated Starter Kit Template -
            // PackageAuthoringService::isLockedImportedPage() treats ANY row there as
            // "this package is a read-only mirror of live content, Name/Author stay
            // locked", which is correct for genuine Resource-Importer imports but
            // was WRONG for a plain Starter-Kit-generated Template (confirmed live: a
            // Template captured this way became incorrectly Name-locked after an
            // earlier version of this fix started writing here too - see
            // docs/32_STARTER_KIT_SYSTEM.md 14.1.8). The accepted trade-off: a page
            // captured into a Starter Kit that was never separately imported still
            // mints a fresh Template handle on every capture - see 14.1.8's "For
            // Future Work" note for why that's preferable to reopening this bug.
            $reusableHandle = $this->findExistingTemplateHandle($entry, $sourceRepo);
            if ($reusableHandle !== null) {
                $pages[] = [
                    'title' => $entry->title,
                    'slug' => $entry->slug,
                    'sectionHandle' => $entry->getSection()?->handle,
                    'entryTypeHandle' => $entry->getType()->handle,
                    'templateHandle' => $reusableHandle,
                ];
                $requiresTemplates[] = $reusableHandle;
                continue;
            }

            try {
                $templateRecord = $templateGenerator->generateFromEntry($entry, [
                    'name' => $entry->title,
                    'description' => 'Captured from "' . $entry->title . '" as part of the "' . $meta['name'] . '" Starter Kit.',
                    'category' => $meta['category'] ?? '',
                    'tags' => '',
                ]);
            } catch (\Throwable $e) {
                // No Site7 Matrix content (the common case for a page-like Single
                // like Home/Contact, per PAGE_LIKE_SINGLE_SECTIONS - see this
                // method's per-loop comment above and docs/32_STARTER_KIT_SYSTEM.md
                // 14.1.12) - fall through to a native-field-only capture rather than
                // skipping the page outright, reusing PageImportService::
                // importNativeContent() directly (not importFromEntry(), which would
                // wrongly guard against/lock this the same way findExistingTemplateHandle()'s
                // docblock above already explains for the read half of this same
                // concern). Genuinely empty pages (no Site7 content AND no
                // capturable native fields) still land in $skipped via this same
                // inner catch, unchanged from before.
                try {
                    $templateRecord = (new PageImportService())->importNativeContent($entry, [
                        'name' => $entry->title,
                        'description' => 'Captured from "' . $entry->title . '" as part of the "' . $meta['name'] . '" Starter Kit.',
                        'category' => $meta['category'] ?? '',
                        'tags' => '',
                    ], $this->getMatrixFieldHandle());
                } catch (\Throwable $nativeException) {
                    $skipped[] = $entry->title . ': ' . $nativeException->getMessage();
                    continue;
                }
            }

            $pages[] = [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'sectionHandle' => $entry->getSection()?->handle,
                'entryTypeHandle' => $entry->getType()->handle,
                'templateHandle' => $templateRecord->handle,
            ];
            $requiresTemplates[] = $templateRecord->handle;
        }

        if (empty($pages)) {
            // Clean up a freshly-created (but never previously existing) directory
            // rather than leaving an empty package behind - matches
            // TemplateGeneratorService::generateFromEntry()'s "never leave a
            // half-written package directory on failure" convention. An UPDATE that
            // captured zero pages is left alone (its prior content stays intact) -
            // only a brand-new, now-empty attempt is removed.
            if (!$isUpdate) {
                FileHelper::removeDirectory($packagePath);
            }
            throw new \Exception('None of the selected pages have Site7 content, so no Templates could be captured.');
        }

        // Single-section "site structure" content (Header/Footer/General/Theme-style)
        // is always re-captured fresh, on every generate AND every update - it is
        // never part of the opt-in page selection above.
        $siteStructure = $this->captureSiteStructure($packagePath);

        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($meta['tags'] ?? '')))));

        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $meta['name'],
            'type' => 'starter-kit',
            'version' => $meta['version'] ?? '1.0.0',
            'author' => $meta['author'] ?? (Craft::$app->getUser()->getIdentity()?->friendlyName ?? 'Site7'),
            'description' => $meta['description'] ?? '',
            'category' => $meta['category'] ?? null,
            'tags' => $tags,
            'requires' => array_filter([
                'templates' => array_values(array_unique($requiresTemplates)),
            ]),
            'pages' => $pages,
            'siteStructure' => $siteStructure,
            'dependencies' => [],
        ];

        file_put_contents($packagePath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packagePath . '/README.md', $this->buildReadme($meta['name'], $pages));

        FileHelper::createDirectory($packagePath . '/preview');

        /** @var UploadedFile|null $previewImage */
        $previewImage = $meta['previewImage'] ?? null;
        if ($previewImage instanceof UploadedFile && $previewImage->tempName) {
            copy($previewImage->tempName, $packagePath . '/preview/preview.png');
        }

        $packageManager->discoverPackages();
        $packageManager->installPackage($handle);
        $packageManager->enablePackage($handle);

        $record = $packageManager->getPackageByHandle($handle);
        if (!$record) {
            throw new \Exception('Starter Kit was generated but could not be registered.');
        }

        return [$record, $skipped];
    }

    /**
     * Captures every Single-section's one Entry (Header/Footer/General/Theme-style
     * "site structure" content) into the manifest's siteStructure array,
     * unconditionally - this is never opt-in like the page checklist. Reuses
     * PageImportService::captureNativeFields() (the same field-capture logic "Import
     * Existing Page" uses for pages with no Site7 content) rather than reimplementing
     * field capture, since a Single section's one Entry is exactly that shape: a page
     * whose content lives entirely in its own native field layout, not in Site7
     * Matrix blocks.
     *
     * @return array<int, array{sectionHandle: string, entryTypeHandle: string, title: string, fields: array}>
     */
    private function captureSiteStructure(string $packagePath): array
    {
        $entriesService = Craft::$app->getEntries();
        $matrixHandle = $this->getMatrixFieldHandle();
        $pageImportService = new PageImportService();

        $structure = [];
        foreach ($entriesService->getAllSections() as $section) {
            if ($section->type !== Section::TYPE_SINGLE) {
                continue;
            }
            if (in_array($section->handle, self::PAGE_LIKE_SINGLE_SECTIONS, true)) {
                // Page-like Singles (Home/Contact) are handled through the normal
                // Template/page system instead - see PAGE_LIKE_SINGLE_SECTIONS'
                // docblock and docs/32_STARTER_KIT_SYSTEM.md 14.1.12.
                continue;
            }

            $entry = Entry::find()->sectionId($section->id)->status(null)->one();
            if (!$entry) {
                // A Single section with no Entry yet (e.g. Craft hasn't auto-created
                // it) has nothing to capture - skip rather than fail the whole
                // Starter Kit generation.
                continue;
            }

            [, $entryFields] = $pageImportService->captureNativeFields($entry, $matrixHandle, $packagePath);

            // A Single's one Entry is usually pre-titled to match its Section when
            // Craft creates it, but that's only a default - it can end up blank
            // (confirmed live: "Google Structure Data" has a blank Entry title on
            // this project). Falls back to the Section's own name, exactly the same
            // rule WebsiteTreeService::describeEntry() already applies for the
            // "Pages to Include" picker (see docs/32_STARTER_KIT_SYSTEM.md 14.1.6) -
            // this is the same fix, just needed independently here since
            // captureSiteStructure() never goes through that method.
            $title = $entry->title;
            if ($title === null || trim((string)$title) === '') {
                $title = $section->name;
            }

            $structure[] = [
                'sectionHandle' => $section->handle,
                'entryTypeHandle' => $entry->getType()->handle,
                'title' => $title,
                'fields' => $entryFields,
            ];
        }

        return $structure;
    }

    /**
     * @return string|null The handle of an existing package already tracked against
     *   this Entry's uid (via PageImportSourceRepository - shared with "Import
     *   Existing Page" and any prior Starter Kit capture of this same Entry), or
     *   null if this Entry has never been captured into a package before.
     */
    private function findExistingTemplateHandle(Entry $entry, PageImportSourceRepository $sourceRepo): ?string
    {
        $sourceRecord = $sourceRepo->findBySourceUid($entry->uid);
        if (!$sourceRecord) {
            return null;
        }
        $package = PackageRecord::findOne($sourceRecord->packageId);
        return $package?->handle;
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

    private function buildReadme(string $name, array $pages): string
    {
        $list = implode("\n", array_map(fn($p) => "- {$p['title']} ({$p['templateHandle']})", $pages));
        return "# {$name}\n\nGenerated via \"Save Current Site as Starter Kit\".\n\nPages:\n\n{$list}\n";
    }
}
