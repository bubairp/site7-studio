<?php

namespace site7\studio\console\controllers;

use yii\console\Controller;
use site7\studio\Site7Studio;
use Craft;
use craft\elements\Entry;

class TestPatternController extends Controller
{
    public function actionIndex()
    {
        echo "Starting Pattern Engine Integration Test...\n\n";

        $pm = Site7Studio::getInstance()->packageManager;

        // Reset state for clean run
        echo "1. Resetting test states...\n";
        $pm->removePackage('about-company');
        $pm->removePackage('hero-banner');
        $pm->removePackage('team');
        
        $this->syncAndCleanDb();
        echo "   ✓ Reset complete.\n";

        // =========================================
        // STEP 1: DISCOVERY
        // =========================================
        echo "2. Running package discovery...\n";
        $pm->discoverPackages();
        
        $pattern = $pm->getPackageByHandle('about-company');
        if (!$pattern) {
            echo "FAIL: about-company Pattern was not discovered.\n";
            return 1;
        }
        if ($pattern->type !== 'pattern') {
            echo "FAIL: Discovered about-company type is '{$pattern->type}', expected 'pattern'.\n";
            return 1;
        }
        echo "   ✓ Pattern discovered successfully.\n";

        // =========================================
        // STEP 2: INSTALLATION WITH AUTO-DEPS
        // =========================================
        echo "3. Installing about-company Pattern (should trigger auto-install for hero-banner and team Sections)...\n";
        $success = $pm->installPackage('about-company');
        if (!$success) {
            echo "FAIL: Pattern installation failed.\n";
            return 1;
        }

        // Verify dependencies were installed and enabled
        $heroRecord = $pm->getPackageByHandle('hero-banner');
        $teamRecord = $pm->getPackageByHandle('team');
        
        if (!$heroRecord || $heroRecord->status !== 'enabled') {
            echo "FAIL: Required section 'hero-banner' was not automatically enabled.\n";
            return 1;
        }
        if (!$teamRecord || $teamRecord->status !== 'enabled') {
            echo "FAIL: Required section 'team' was not automatically enabled.\n";
            return 1;
        }
        echo "   ✓ Required section packages automatically installed and enabled.\n";

        // Enable the pattern package itself to register it in the matrix field
        echo "4. Enabling the Pattern package...\n";
        $pm->enablePackage('about-company');

        // =========================================
        // STEP 3: MATRIX REGISTRATION
        // =========================================
        $settings = Site7Studio::getInstance()->getSettings();
        $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        
        $blockEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('aboutCompany');
        if (!$blockEntryType) {
            echo "FAIL: Pattern entry type 'aboutCompany' was not registered.\n";
            return 1;
        }

        $entryTypeIds = array_map(fn($et) => $et->id, $matrixField->getEntryTypes());
        if (!in_array($blockEntryType->id, $entryTypeIds)) {
            echo "FAIL: Pattern entry type was not linked to Matrix.\n";
            return 1;
        }
        echo "   ✓ Pattern block registered and linked to Matrix Page Builder.\n";

        // =========================================
        // STEP 4: PAGE BUILDER RUNTIME INSERTION
        // =========================================
        echo "5. Simulating Page Builder runtime insertion...\n";
        $section = Craft::$app->getEntries()->getSectionByHandle('testPages');
        $sectionEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testPage');

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $sectionEntryType->id;
        $entry->title = "Pattern Test Page";
        $entry->slug = "pattern-test-" . time();
        $entry->authorId = 1;

        $entry->setFieldValue('site7Components', [
            'new1' => [
                'type' => 'aboutCompany',
                'fields' => []
            ]
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            echo "FAIL: Could not save entry with Pattern block.\n";
            return 1;
        }
        echo "   ✓ Entry successfully created with Pattern block inserted.\n";

        // =========================================
        // STEP 5: CLEANUP
        // =========================================
        echo "6. Cleaning up test entry...\n";
        Craft::$app->getElements()->deleteElement($entry);
        
        echo "7. Removing test packages...\n";
        $pm->removePackage('about-company');
        $pm->removePackage('hero-banner');
        $pm->removePackage('team');
        
        echo "\n✅ SUCCESS: Pattern Engine Integration Test passed!\n";
        return 0;
    }

    private function syncAndCleanDb()
    {
        // Make sure database is clean of temporary states
        $pm = Site7Studio::getInstance()->packageManager;
        $pm->discoverPackages();
    }
}
