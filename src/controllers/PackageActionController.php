<?php

namespace site7\studio\controllers;

use Craft;
use craft\web\Controller;
use site7\studio\Site7Studio;

class PackageActionController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'install';

    /**
     * Installs a package.
     */
    public function actionInstall()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $success = Site7Studio::getInstance()->packageManager->installPackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package installed successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not install package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Enables a package.
     */
    public function actionEnable()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');
        $success = Site7Studio::getInstance()->packageManager->enablePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package enabled successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not enable package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Disables a package.
     */
    public function actionDisable()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        // Phase 7.2 Safety Check
        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot disable package. It is currently in use by ' . count($usage) . ' entries.'));
            return $this->redirectToPostedUrl();
        }

        $success = Site7Studio::getInstance()->packageManager->disablePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package disabled successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not disable package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Removes a package.
     */
    public function actionRemove()
    {
        $this->requirePostRequest();
        
        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        // Phase 7.2 Safety Check
        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot remove package. It is currently in use by ' . count($usage) . ' entries.'));
            return $this->redirectToPostedUrl();
        }

        $success = Site7Studio::getInstance()->packageManager->removePackage($handle);
        
        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package removed successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not remove package.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Gets the serialized block structures for a pattern to inject into Matrix.
     */
    public function actionGetPatternBlocks()
    {
        $this->requireAcceptsJson();
        
        $handle = Craft::$app->getRequest()->getRequiredParam('handle');
        
        $service = new \site7\studio\services\PatternInsertionService();
        $blocks = $service->getPatternBlocks($handle);

        return $this->asJson([
            'success' => true,
            'blocks' => $blocks
        ]);
    }

    /**
     * Gets the serialized block structures for a template to inject into Matrix.
     */
    public function actionGetTemplateBlocks()
    {
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getRequiredParam('handle');

        $service = new \site7\studio\services\TemplateInsertionService();
        $blocks = $service->getTemplateBlocks($handle);

        return $this->asJson([
            'success' => true,
            'blocks' => $blocks
        ]);
    }

    /**
     * Gets the data for the Pattern Browser UI.
     */
    public function actionGetBrowserData()
    {
        $this->requireAcceptsJson();
        
        $type = Craft::$app->getRequest()->getParam('type', 'pattern');
        
        $allPackages = Site7Studio::getInstance()->packageManager->getAllPackages();
        $packages = array_filter($allPackages, function($p) use ($type) {
            // Only display enabled packages in the Content Browser
            if ($p->status !== 'enabled') {
                return false;
            }
            if (strtolower($type) === 'all') {
                return true;
            }
            return strtolower($p->type) === strtolower($type);
        });
        
        $data = [];
        foreach ($packages as $pkg) {
            $manifest = $pkg->getManifest();
            
            $tags = $pkg->tags ?? [];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            
            $blockTypeHandle = null;
            if (strtolower($pkg->type) === 'section') {
                $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($pkg->handle);
                if ($packagePath) {
                    $matrixYamlPath = $packagePath . '/matrix.yaml';
                    if (file_exists($matrixYamlPath)) {
                        $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
                        if (isset($matrixData['blocks'][0]['handle'])) {
                            $blockTypeHandle = $matrixData['blocks'][0]['handle'];
                        }
                    }
                }
            }
            
            $data[] = [
                'handle' => $pkg->handle,
                'name' => $pkg->name,
                'type' => $pkg->type,
                'status' => $pkg->status,
                'description' => $pkg->description,
                'category' => $pkg->category ?? 'Uncategorized',
                'tags' => $tags,
                'version' => $pkg->version,
                'author' => $pkg->author ?? 'Unknown',
                'requires' => $manifest->requires ?? [],
                'previewImageUrl' => \craft\helpers\UrlHelper::cpUrl('site7-studio/library/package/' . $pkg->handle . '/preview-image'),
                'renderUrl' => \craft\helpers\UrlHelper::cpUrl('site7-studio/library/package/' . $pkg->handle . '/render-preview'),
                'blockTypeHandle' => $blockTypeHandle
            ];
        }

        return $this->asJson([
            'success' => true,
            'packages' => $data
        ]);
    }
}
