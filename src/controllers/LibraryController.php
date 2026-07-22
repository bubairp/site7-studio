<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class LibraryController extends Controller
{
    private const PER_PAGE = 24;

    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);

        $request = Craft::$app->getRequest();
        $type = $request->getQueryParam('type', 'section');
        $q = trim((string)$request->getQueryParam('q', ''));
        $status = trim((string)$request->getQueryParam('status', ''));
        $categoryFilters = array_map('strtolower', (array)$request->getQueryParam('category', []));
        $tagFilters = array_map('strtolower', (array)$request->getQueryParam('tag', []));
        $authorFilters = array_map('strtolower', (array)$request->getQueryParam('author', []));
        // Named viewMode, not view - "view" is a reserved global in Craft's own CP
        // Twig layouts (the View service instance); reusing it as a template
        // variable shadows that global and breaks _layouts/cp.twig.
        $viewMode = $request->getQueryParam('view', 'thumbs') === 'table' ? 'table' : 'thumbs';

        $allPackages = Site7Studio::getInstance()->packageManager->getAllPackages();

        // Filter packages by requested type
        $typePackages = array_values(array_filter($allPackages, function($p) use ($type) {
            return strtolower($p->type) === strtolower($type);
        }));

        // Facet option lists are built from the type-filtered set (before search/status/
        // category/tag/author narrows it further), so checked-off options don't disappear
        // from their own menu.
        $categories = [];
        $tags = [];
        $authors = [];
        foreach ($typePackages as $p) {
            if ($p->category) {
                $categories[strtolower($p->category)] = $p->category;
            }
            if ($p->author) {
                $authors[strtolower($p->author)] = $p->author;
            }
            $pTags = $p->tags ?? [];
            if (is_string($pTags)) {
                $pTags = array_filter(array_map('trim', explode(',', $pTags)));
            }
            foreach ($pTags as $t) {
                $tags[strtolower($t)] = $t;
            }
        }
        ksort($categories);
        ksort($tags);
        ksort($authors);

        $packages = array_values(array_filter($typePackages, function($p) use ($q, $status, $categoryFilters, $tagFilters, $authorFilters) {
            if ($q !== '') {
                $haystack = strtolower($p->name . ' ' . $p->description);
                if (!str_contains($haystack, strtolower($q))) {
                    return false;
                }
            }
            if ($status !== '' && strtolower($p->status) !== strtolower($status)) {
                return false;
            }
            if (!empty($categoryFilters) && !in_array(strtolower((string)$p->category), $categoryFilters, true)) {
                return false;
            }
            if (!empty($authorFilters) && !in_array(strtolower((string)$p->author), $authorFilters, true)) {
                return false;
            }
            if (!empty($tagFilters)) {
                $pTags = $p->tags ?? [];
                if (is_string($pTags)) {
                    $pTags = array_filter(array_map('trim', explode(',', $pTags)));
                }
                $pTags = array_map('strtolower', $pTags);
                if (empty(array_intersect($tagFilters, $pTags))) {
                    return false;
                }
            }
            return true;
        }));

        $total = count($packages);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($totalPages, (int)$request->getQueryParam('page', 1)));
        $packages = array_slice($packages, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        return $this->renderTemplate('site7-studio/library/index', [
            'title' => 'Library',
            'packages' => $packages,
            'currentType' => $type,
            'isSetupComplete' => $isSetupComplete,
            'q' => $q,
            'status' => $status,
            'categories' => $categories,
            'tags' => $tags,
            'authors' => $authors,
            'categoryFilters' => $categoryFilters,
            'tagFilters' => $tagFilters,
            'authorFilters' => $authorFilters,
            'viewMode' => $viewMode,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function actionPackage(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        
        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }
        
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        [$hasPreviewImage, $hasPreviewTemplate] = $this->getPreviewFlags($package);

        return $this->renderTemplate('site7-studio/library/package', [
            'title' => $package->name,
            'package' => $package,
            'isSetupComplete' => $isSetupComplete,
            'usage' => $usage,
            'hasPreviewImage' => $hasPreviewImage,
            'hasPreviewTemplate' => $hasPreviewTemplate,
        ]);
    }

    public function actionPreview(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);

        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);

        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }

        [$hasPreviewImage, $hasPreviewTemplate] = $this->getPreviewFlags($package);

        return $this->renderTemplate('site7-studio/library/preview', [
            'title' => 'Preview: ' . $package->name,
            'package' => $package,
            'hasPreviewImage' => $hasPreviewImage,
            'hasPreviewTemplate' => $hasPreviewTemplate,
        ]);
    }

    /**
     * @return array{0: bool, 1: bool} [hasPreviewImage, hasPreviewTemplate]
     */
    private function getPreviewFlags($package): array
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($package->handle);

        $hasPreviewImage = false;
        $hasPreviewTemplate = false;
        if ($packagePath) {
            $hasPreviewImage = $this->findPreviewImagePath($packagePath) !== null;
            $hasPreviewTemplate = file_exists($packagePath . '/preview/preview.twig');
        }

        // Patterns and Templates never have their own preview.twig - actionRenderPreview()
        // composes their preview from their required Sections' templates instead, so the
        // iframe is always renderable for these types regardless of file presence.
        if (in_array($package->type, ['pattern', 'template'], true)) {
            $hasPreviewTemplate = true;
        }

        return [$hasPreviewImage, $hasPreviewTemplate];
    }

    /**
     * Renders a live preview of the pattern using mock data and returns the HTML.
     */
    public function actionRenderPreview(string $handle)
    {
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }

        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \yii\web\NotFoundHttpException("Package path not found");
        }

        $view = Craft::$app->getView();
        $originalTemplatesPath = $view->getTemplatesPath();
        $renderedContent = '';
        $packageCss = '';

        if ($package->type === 'pattern') {
            $manifest = $package->getManifest();
            if ($manifest && !empty($manifest->requires['sections'])) {
                $demoContent = $manifest->demoContent ?? [];
                foreach ($manifest->requires['sections'] as $sectionHandle) {
                    $sectionData = $demoContent[$sectionHandle] ?? $demoContent[str_replace('-', '_', $sectionHandle)] ?? [];
                    [$html, $css] = $this->renderSectionForPreview($view, $sectionHandle, $sectionData, $handle);
                    $renderedContent .= $html;
                    $packageCss .= $css;
                }
            }
        } elseif ($package->type === 'template') {
            $manifest = $package->getManifest();
            if ($manifest) {
                $templateDemoContent = $manifest->demoContent ?? [];
                $templateService = new \site7\studio\services\TemplateInsertionService();
                $sectionEntries = $templateService->resolveSectionEntries($manifest);

                foreach ($sectionEntries as $entry) {
                    $sectionHandle = $entry['handle'];
                    $snakeHandle = str_replace('-', '_', $sectionHandle);
                    $sectionData = $templateDemoContent[$sectionHandle]
                        ?? $templateDemoContent[$snakeHandle]
                        ?? $entry['fallbackDemo'][$sectionHandle]
                        ?? $entry['fallbackDemo'][$snakeHandle]
                        ?? [];
                    [$html, $css] = $this->renderSectionForPreview($view, $sectionHandle, $sectionData, $handle);
                    $renderedContent .= $html;
                    $packageCss .= $css;
                }
            }
        } else {
            // Standard section rendering
            $previewTwigPath = $packagePath . '/preview/preview.twig';
            if (!file_exists($previewTwigPath)) {
                $previewTwigPath = $packagePath . '/template.twig';
            }

            if (!file_exists($previewTwigPath)) {
                throw new \yii\web\NotFoundHttpException("Preview template not found");
            }

            $previewDataPath = $packagePath . '/preview/preview-data.yaml';
            if (!file_exists($previewDataPath)) {
                $previewDataPath = $packagePath . '/preview-data.yaml';
            }

            $data = [];
            if (file_exists($previewDataPath)) {
                $parsed = \Symfony\Component\Yaml\Yaml::parseFile($previewDataPath);
                if (isset($parsed['block'])) {
                    $data = $parsed;
                } else {
                    $data = ['block' => $parsed];
                }
            } else {
                // Generate fallback mock data from fields.yaml
                $data = ['block' => []];
                $fieldsYamlPath = $packagePath . '/fields.yaml';
                if (file_exists($fieldsYamlPath)) {
                    $fieldsData = \Symfony\Component\Yaml\Yaml::parseFile($fieldsYamlPath);
                    if (isset($fieldsData['fields']) && is_array($fieldsData['fields'])) {
                        foreach ($fieldsData['fields'] as $f) {
                            if (isset($f['handle'])) {
                                $data['block'][$f['handle']] = "Mock " . ($f['name'] ?? $f['handle']);
                            }
                        }
                    }
                }
                if (empty($data['block'])) {
                    $data['block'] = ['heading' => 'Mock ' . $package->name];
                }
            }
            
            try {
                $view->setTemplatesPath($packagePath);
                $templateToRender = (strpos($previewTwigPath, 'preview/preview.twig') !== false) ? 'preview/preview.twig' : 'template.twig';
                $renderedContent = $view->renderTemplate($templateToRender, $data);
                $packageCss = $this->getPackageStyles($packagePath);
            } catch (\Throwable $e) {
                $view->setTemplatesPath($originalTemplatesPath);
                throw $e;
            }
        }

        $view->setTemplatesPath($originalTemplatesPath);

        $dynamicCss = '';
        $originalMode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_SITE);
            if ($view->doesTemplateExist('_dynamic-css')) {
                $dynamicCss = $view->renderTemplate('_dynamic-css');
            }
        } catch (\Throwable $e) {
            Craft::error("Could not render dynamic css: " . $e->getMessage(), __METHOD__);
        }
        $view->setTemplateMode($originalMode);

        // Render preview content in our sandboxed frame shell
        return $this->renderTemplate('site7-studio/library/live-preview-shell', [
            'content' => $renderedContent,
            'dynamicCss' => $dynamicCss,
            'packageCss' => $packageCss,
        ]);
    }

    /**
     * Reads a package's resources/style.css, if present.
     */
    private function getPackageStyles(string $packagePath): string
    {
        $stylePath = $packagePath . '/resources/style.css';
        if (file_exists($stylePath)) {
            return file_get_contents($stylePath) . "\n";
        }
        return '';
    }

    /**
     * Renders a single Section's template.twig with the given demo data for use inside a
     * composed preview (Pattern or Template), returning its HTML and CSS. Errors are logged
     * and swallowed so one broken Section doesn't blank out the rest of the preview.
     *
     * @return array{0: string, 1: string} [html, css]
     */
    private function renderSectionForPreview(\craft\web\View $view, string $sectionHandle, array $sectionData, string $composedFromHandle): array
    {
        $sectionPath = Site7Studio::getInstance()->packageManager->getPackagePath($sectionHandle);
        if (!$sectionPath || !file_exists($sectionPath . '/template.twig')) {
            return ['', ''];
        }

        try {
            $view->setTemplatesPath($sectionPath);
            $html = $view->renderTemplate('template.twig', ['block' => $sectionData]);
            $css = $this->getPackageStyles($sectionPath);
            return [$html, $css];
        } catch (\Throwable $e) {
            Craft::error("Error rendering section {$sectionHandle} for {$composedFromHandle}: " . $e->getMessage(), __METHOD__);
            return ['', ''];
        }
    }

    /**
     * Serves a package preview image directly.
     */
    public function actionPreviewImage(string $handle)
    {
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);
        if (!$packagePath) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }

        $imagePath = $this->findPreviewImagePath($packagePath);
        if (!$imagePath) {
            throw new \yii\web\NotFoundHttpException("Preview image not found");
        }

        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', $mimeTypes[$extension] ?? 'application/octet-stream');
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->data = file_get_contents($imagePath);
        return $response;
    }

    /**
     * Finds the on-disk preview image for a package, whatever extension it
     * was uploaded with (see PackageAuthoringService::savePreviewImage()).
     */
    private function findPreviewImagePath(string $packagePath): ?string
    {
        foreach (\site7\studio\services\PackageAuthoringService::PREVIEW_IMAGE_EXTENSIONS as $extension) {
            $path = $packagePath . '/preview/preview.' . $extension;
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }
}
