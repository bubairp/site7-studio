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
     * Permanently deletes a package (its DB record and its folder on disk).
     */
    public function actionDelete()
    {
        $this->requirePostRequest();

        $handle = Craft::$app->getRequest()->getRequiredBodyParam('handle');

        // Deleting is normally Dev-Mode-only (Package Authoring), but a user
        // who captured a Template themselves via "Save as Template" (open
        // to everyone) can also delete that specific Template in production.
        if (!Site7Studio::isDevMode()) {
            $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
            $isOwnTemplate = $record
                && $record->type === 'template'
                && $record->creatorId !== null
                && (int)$record->creatorId === (int)Craft::$app->getUser()->getId();
            if (!$isOwnTemplate) {
                throw new \yii\web\ForbiddenHttpException('You are not permitted to delete this package.');
            }
        }

        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Cannot delete package. It is currently in use by ' . count($usage) . ' entries.'));
            return $this->redirectToPostedUrl();
        }

        // Grab the type before deleting - once the record's gone there's
        // nothing left to look it up from, and the caller needs it to land
        // back on the right type-filtered Library view (Sections stay on
        // Sections, Patterns stay on Patterns, etc.) instead of the default.
        $record = Site7Studio::getInstance()->packageManager->getPackageByHandle($handle);
        $type = $record->type ?? 'section';

        $success = Site7Studio::getInstance()->packageManager->deletePackage($handle);

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('site7-studio', 'Package deleted successfully.'));
            return $this->redirect('site7-studio/library?type=' . $type);
        }

        Craft::$app->getSession()->setError(Craft::t('site7-studio', 'Could not delete package.'));
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
            $blockTypeId = null;
            if (strtolower($pkg->type) === 'section') {
                $packagePath = Site7Studio::getInstance()->packageManager->getPackagePath($pkg->handle);
                if ($packagePath) {
                    $matrixYamlPath = $packagePath . '/matrix.yaml';
                    if (file_exists($matrixYamlPath)) {
                        $matrixData = \Symfony\Component\Yaml\Yaml::parseFile($matrixYamlPath);
                        if (isset($matrixData['blocks'][0]['handle'])) {
                            $blockTypeHandle = $matrixData['blocks'][0]['handle'];
                            // The entry type's real ID, so the client can match it exactly
                            // instead of relying on fuzzy label/handle string matching -
                            // see pattern-matrix.js's resolveCreateAttributes().
                            $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($blockTypeHandle);
                            $blockTypeId = $entryType?->id;
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
                'blockTypeHandle' => $blockTypeHandle,
                'blockTypeId' => $blockTypeId,
            ];
        }

        return $this->asJson([
            'success' => true,
            'packages' => $data
        ]);
    }
}
