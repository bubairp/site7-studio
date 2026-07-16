<?php

namespace site7\studio\console\controllers;

use yii\console\Controller;
use site7\studio\Site7Studio;
use Craft;
use craft\fields\Matrix;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\fieldlayoutelements\CustomField;

class TestRuntimeController extends Controller
{
    public function actionIndex()
    {
        echo "Starting Phase 5.5 Runtime Test...\n";

        // 1. Uninstall and Install package
        $handle = 'test-hero';
        $pm = Site7Studio::getInstance()->packageManager;
        
        $package = $pm->getPackageByHandle($handle);
        if ($package) {
            $package->status = 'available';
            $package->save();
        }

        // Clean up previously generated things so we start fresh
        // (For simplicity in this test, we assume they were already removed or we just reuse them if they exist)
        
        echo "1. Installing package...\n";
        $success = $pm->installPackage($handle);
        if (!$success) {
            echo "Failed to install package.\n";
            return 1;
        }

        $entriesService = Craft::$app->getEntries();
        $fieldsService = Craft::$app->getFields();
        $sectionsService = Craft::$app->getEntries(); // Sections are handled by getEntries() in Craft 5? 
        // Wait, sections are still getSections() in Craft 5? Let me verify.
        // I will use Craft::$app->getEntries()->getSectionByHandle() or Craft::$app->getSections()->getSectionByHandle().
        // I'll check method_exists.
        $sectionsService = method_exists(Craft::$app, 'getSections') ? Craft::$app->getSections() : Craft::$app->getEntries();

        // 2. Ensure EntryType 'testHeroBlock' exists
        $blockEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testHeroBlock');
        if (!$blockEntryType) {
            echo "Failed to find testHeroBlock EntryType.\n";
            return 1;
        }

        // 3. Create a Matrix Field
        echo "2. Creating Matrix Field...\n";
        $matrixField = $fieldsService->getFieldByHandle('site7Components');
        if (!$matrixField) {
            $matrixField = new Matrix([
                'handle' => 'site7Components',
                'name' => 'Site7 Components',
            ]);
            $matrixField->setEntryTypes([$blockEntryType->id]); // Link the entry type
            if (!$fieldsService->saveField($matrixField)) {
                echo "Failed to save Matrix field.\n";
                return 1;
            }
        } else {
            // Update the existing matrix field just in case
            $matrixField->setEntryTypes([$blockEntryType->id]);
            $fieldsService->saveField($matrixField);
        }

        // 4. Create Entry Type for the Section
        echo "3. Creating Section Entry Type...\n";
        $sectionEntryType = $entriesService->getEntryTypeByHandle('testPage');
        if (!$sectionEntryType) {
            $sectionEntryType = new EntryType([
                'name' => 'Test Page',
                'handle' => 'testPage',
                'hasTitleField' => true,
            ]);
            
            // Layout with Matrix field
            $layout = new FieldLayout();
            $tab = new FieldLayoutTab(['name' => 'Content', 'sortOrder' => 1]);
            $tab->setLayout($layout);
            $tab->setElements([
                new CustomField($matrixField)
            ]);
            $layout->setTabs([$tab]);
            $sectionEntryType->setFieldLayout($layout);

            if (!$entriesService->saveEntryType($sectionEntryType)) {
                echo "Failed to save Section Entry Type. Errors: " . json_encode($sectionEntryType->getErrors()) . "\n";
                return 1;
            }
        }

        // 5. Create a Section
        echo "4. Creating Section...\n";
        $section = $sectionsService->getSectionByHandle('testPages');
        if (!$section) {
            $section = new Section([
                'name' => 'Test Pages',
                'handle' => 'testPages',
                'type' => Section::TYPE_CHANNEL,
            ]);
            // Set site settings for section
            $siteSettings = new Section_SiteSettings([
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'hasUrls' => true,
                'uriFormat' => 'test-pages/{slug}',
                'template' => 'test-pages/_entry'
            ]);
            $section->setSiteSettings([Craft::$app->getSites()->getPrimarySite()->id => $siteSettings]);
            
            $section->setEntryTypes([$sectionEntryType]);

            if (!$sectionsService->saveSection($section)) {
                echo "Failed to save Section. Errors: " . json_encode($section->getErrors()) . "\n";
                return 1;
            }
        }

        // 6. Create Demo Entry
        echo "4. Creating Demo Entry...\n";
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $sectionEntryType->id;
        $entry->title = "Site7 Runtime Test";
        $entry->slug = "site7-runtime-test";
        $entry->authorId = 1;

        // Populate Matrix
        // In Craft 5, Matrix fields are populated by passing an array of Entry elements or serialized data
        $entry->setFieldValue('site7Components', [
            [
                'type' => $blockEntryType->handle,
                'fields' => [
                    'heroHeading' => 'Hello Site7',
                    'heroSubheading' => 'The Component Runtime is ALIVE!',
                ]
            ]
        ]);

        $elementsService = Craft::$app->getElements();
        if (!$elementsService->saveElement($entry)) {
            echo "Failed to save Entry. Errors: " . json_encode($entry->getErrors()) . "\n";
            return 1;
        }

        echo "5. Rendering Frontend Template...\n";
        // Let's mock a frontend render of the matrix block
        $view = Craft::$app->getView();
        $view->setTemplateMode($view::TEMPLATE_MODE_SITE);
        
        $templateStr = "{% for block in entry.site7Components %}{% include 'site7-components/' ~ block.type.handle %}{% endfor %}";
        try {
            $rendered = $view->renderString($templateStr, ['entry' => $entry]);
            echo "\n--- RENDERED OUTPUT ---\n";
            echo trim($rendered) . "\n";
            echo "-----------------------\n";
            
            if (strpos($rendered, 'Hello Site7') !== false && strpos($rendered, 'The Component Runtime is ALIVE!') !== false) {
                echo "\nSUCCESS: The full Component Runtime pipeline works!\n";
            } else {
                echo "\nFAILED: Template rendered, but content missing.\n";
            }
        } catch (\Exception $e) {
            echo "Failed to render template: " . $e->getMessage() . "\n";
            return 1;
        }

        return 0;
    }

    private function getOrCreateFieldGroup(string $name)
    {
        // Field groups do not exist in Craft 5. Just return null.
        return null;
    }
}
