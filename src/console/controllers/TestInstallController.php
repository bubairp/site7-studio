<?php

namespace site7\studio\console\controllers;

use yii\console\Controller;
use site7\studio\Site7Studio;
use Craft;
use craft\elements\Entry;

class TestInstallController extends Controller
{
    public function actionIndex()
    {
        $handle = 'team';
        $pm = Site7Studio::getInstance()->packageManager;
        $pm->discoverPackages();

        echo "Packages discovered: \n";
        foreach ($pm->getAllPackages() as $p) {
            echo "- " . $p->handle . "\n";
        }

        echo "Installing package...\n";
        $success = $pm->installPackage($handle);
        if (!$success) {
            echo "Failed to install package.\n";
            return 1;
        }

        echo "Putting package in use...\n";
        $section = Craft::$app->getEntries()->getSectionByHandle('testPages');
        $sectionEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testPage');
        $blockEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('team');
        
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $sectionEntryType->id;
        $entry->title = "Team Usage";
        $entry->slug = "team-usage";
        $entry->authorId = 1;

        $entry->setFieldValue('site7Components', [
            [
                'type' => $blockEntryType->handle,
                'fields' => [
                    'heading' => 'Test',
                ]
            ]
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            echo "Failed to save usage entry.\n";
            return 1;
        }

        echo "Done.\n";
        return 0;
    }
}
