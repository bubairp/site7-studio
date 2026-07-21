<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;
use Symfony\Component\Yaml\Yaml;

class LibraryController extends Controller
{
    public function actionIndex()
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $type = Craft::$app->getRequest()->getQueryParam('type', 'section');
        
        $allPackages = Site7Studio::getInstance()->packageManager->getAllPackages();
        
        // Filter packages by requested type
        $packages = array_filter($allPackages, function($p) use ($type) {
            return strtolower($p->type) === strtolower($type);
        });
        
        $settings = \site7\studio\Site7Studio::getInstance()->getSettings();
        $isSetupComplete = !empty($settings->matrixFieldId);

        return $this->renderTemplate('site7-studio/library/index', [
            'title' => 'Library',
            'packages' => $packages,
            'currentType' => $type,
            'isSetupComplete' => $isSetupComplete,
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

        return $this->renderTemplate('site7-studio/library/package', [
            'title' => $package->name,
            'package' => $package,
            'isSetupComplete' => $isSetupComplete,
            'usage' => $usage,
        ]);
    }

    public function actionPreview(string $handle)
    {
        $this->view->registerAssetBundle(\site7\studio\assetbundles\LibraryBundle::class);
        
        $package = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        
        if (!$package) {
            throw new \yii\web\NotFoundHttpException("Package not found");
        }
        
        $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($handle);

        $hasPreviewImage = false;
        $hasPreviewTemplate = false;
        if ($packagePath) {
            $hasPreviewImage = file_exists($packagePath . '/preview/preview.png');
            $hasPreviewTemplate = file_exists($packagePath . '/preview/preview.twig');
        }

        return $this->renderTemplate('site7-studio/library/preview', [
            'title' => 'Preview: ' . $package->name,
            'package' => $package,
            'hasPreviewImage' => $hasPreviewImage,
            'hasPreviewTemplate' => $hasPreviewTemplate,
        ]);
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

        if ($package->type === 'pattern') {
            $manifest = $package->getManifest();
            if ($manifest && !empty($manifest->requires['sections'])) {
                $demoContent = $manifest->demoContent ?? [];
                foreach ($manifest->requires['sections'] as $sectionHandle) {
                    $sectionPath = Site7Studio::getInstance()->packageManager->getPackagePath($sectionHandle);
                    if ($sectionPath && file_exists($sectionPath . '/template.twig')) {
                        $sectionData = $demoContent[$sectionHandle] ?? $demoContent[str_replace('-', '_', $sectionHandle)] ?? [];
                        try {
                            $view->setTemplatesPath($sectionPath);
                            $renderedContent .= $view->renderTemplate('template.twig', ['block' => $sectionData]);
                        } catch (\Throwable $e) {
                            Craft::error("Error rendering section {$sectionHandle} for pattern {$handle}: " . $e->getMessage(), __METHOD__);
                        }
                    }
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
        ]);
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

        $imagePath = $packagePath . '/preview/preview.png';
        if (!file_exists($imagePath)) {
            throw new \yii\web\NotFoundHttpException("Preview image not found");
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->data = file_get_contents($imagePath);
        return $response;
    }
}
