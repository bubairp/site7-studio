<?php

namespace site7\studio\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use site7\studio\Site7Studio;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP Site Kits screen (docs/48_FULL_SITE_KIT.md §11, Dev Mode only): build a Full Site Kit
 * from this site, or install one onto this fresh site. Building and
 * installing run as background jobs (SiteKitJobs) with a live progress page.
 * Admins only; installing also needs allowAdminChanges, since it replaces
 * the project config.
 */
class SiteKitsController extends Controller
{
    /**
     * Full Site Kits are an internal tool (docs/48): Dev Mode only. The job
     * pages stay open - Library Starter Kit installs (docs/51) use them.
     */
    public function beforeAction($action): bool
    {
        if (!in_array($action->id, ['job', 'job-status'], true) && !Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new \yii\web\ForbiddenHttpException('Site Kits are available in Dev Mode only. Set up a site from a Library Starter Kit on the Install screen.');
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('site7-studio/site-kits/index', [
            'kits' => $this->kits(),
            'isFresh' => $this->isFresh(),
            'jobs' => Site7Studio::getInstance()->siteKitJobs->recent(),
            'validation' => null,
            'validatedKit' => null,
        ]);
    }

    public function actionBuild(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $name = trim((string)$this->request->getRequiredBodyParam('name'));
        if ($name === '') {
            $this->setFailFlash('Give the kit a name.');
            return $this->redirect('site7-studio/site-kits');
        }

        $args = ['site7-studio/site-kit/build', $name];
        if (!$this->request->getBodyParam('includeContent')) {
            $args[] = '--no-content';
        }
        $id = Site7Studio::getInstance()->siteKitJobs->start("Build \"{$name}\"", $args);

        return $this->redirect("site7-studio/site-kits/job/{$id}");
    }

    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $file = UploadedFile::getInstanceByName('kit');
        if (!$file || strtolower($file->getExtension()) !== 'zip') {
            $this->setFailFlash('Choose a site kit .zip file.');
            return $this->redirect('site7-studio/site-kits');
        }
        $file->saveAs($this->kitsDir() . '/' . FileHelper::sanitizeFilename($file->name));
        $this->setSuccessFlash("Uploaded {$file->name}.");

        return $this->redirect('site7-studio/site-kits');
    }

    public function actionValidate(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $file = $this->kitPath((string)$this->request->getRequiredBodyParam('kit'));

        return $this->renderTemplate('site7-studio/site-kits/index', [
            'kits' => $this->kits(),
            'isFresh' => $this->isFresh(),
            'jobs' => Site7Studio::getInstance()->siteKitJobs->recent(),
            'validation' => Site7Studio::getInstance()->siteKitInstaller->validateKit($file),
            'validatedKit' => basename($file),
        ]);
    }

    public function actionInstall(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $file = $this->kitPath((string)$this->request->getRequiredBodyParam('kit'));
        $validation = Site7Studio::getInstance()->siteKitInstaller->validateKit($file);
        if ($validation['errors']) {
            $this->setFailFlash('This kit can\'t be installed here: ' . $validation['errors'][0]);
            return $this->redirect('site7-studio/site-kits');
        }

        $id = Site7Studio::getInstance()->siteKitJobs->start('Install ' . basename($file), ['site7-studio/site-kit/install', $file]);

        return $this->redirect("site7-studio/site-kits/job/{$id}");
    }

    public function actionJob(string $id): Response
    {
        $this->requireAdmin(false);
        $job = Site7Studio::getInstance()->siteKitJobs->status($id) ?? throw new NotFoundHttpException('Job not found');

        return $this->renderTemplate('site7-studio/site-kits/job', ['job' => $job]);
    }

    /** Polled by the job page. Kept lean: during an install, vendor/ is being replaced. */
    public function actionJobStatus(string $id): Response
    {
        $this->requireAdmin(false);
        $this->requireAcceptsJson();

        return $this->asJson(Site7Studio::getInstance()->siteKitJobs->status($id) ?? ['error' => 'Job not found']);
    }

    public function actionDownload(string $kit): Response
    {
        $this->requireAdmin(false);

        return $this->response->sendFile($this->kitPath($kit), basename($kit));
    }

    /**
     * @return array[] name, size, modified, manifest summary
     */
    private function kits(): array
    {
        $kits = [];
        foreach (glob($this->kitsDir() . '/*.zip') ?: [] as $path) {
            $manifest = null;
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $manifest = json_decode((string)$zip->getFromName('site-kit.json'), true);
                $zip->close();
            }
            $kits[] = [
                'file' => basename($path),
                'size' => filesize($path),
                'modified' => filemtime($path),
                'manifest' => is_array($manifest) ? $manifest : null,
            ];
        }
        usort($kits, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $kits;
    }

    private function kitPath(string $file): string
    {
        $path = $this->kitsDir() . '/' . basename($file);
        if (!str_ends_with($path, '.zip') || !is_file($path)) {
            throw new NotFoundHttpException('Kit not found');
        }

        return $path;
    }

    private function kitsDir(): string
    {
        $dir = Craft::getAlias('@storage') . '/site7-studio/site-kits';
        FileHelper::createDirectory($dir);
        return $dir;
    }

    /** Largest file a browser upload can carry here (PHP's upload/post limits). */
    public static function uploadLimit(): int
    {
        $limits = array_filter(array_map(
            fn(string $key) => \craft\helpers\ConfigHelper::sizeInBytes(ini_get($key) ?: '0'),
            ['upload_max_filesize', 'post_max_size']
        ));

        return $limits ? min($limits) : 0;
    }

    private function isFresh(): bool
    {
        return count(Craft::$app->getEntries()->getAllSections()) === 0 && Entry::find()->status(null)->count() === 0;
    }
}
