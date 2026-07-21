<?php

namespace site7\studio\console\controllers;

use yii\console\Controller;
use site7\studio\Site7Studio;
use Craft;
use craft\fields\Matrix;
use craft\elements\Entry;
use craft\models\EntryType;

class TestLifecycleController extends Controller
{
    public function actionIndex()
    {
        echo "Starting Phase 7.1/7.2 Lifecycle Test...\n";
        echo "New behavior: Install does NOT link to Matrix. Enable does.\n\n";

        $handle = 'test-hero';
        $pm = Site7Studio::getInstance()->packageManager;

        // Reset package to available
        $pm->removePackage($handle);

        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            echo "ERROR: matrixFieldId is not set. Run Setup Wizard first.\n";
            return 1;
        }

        // =========================================
        // STEP 1: INSTALL (should NOT link to Matrix)
        // =========================================
        echo "1. Installing package...\n";
        $success = $pm->installPackage($handle);
        if (!$success) {
            echo "FAIL: Install failed.\n";
            return 1;
        }

        // Verify Entry Type was created but NOT linked to Matrix
        $blockEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testHeroBlock');
        if (!$blockEntryType) {
            echo "FAIL: Entry Type was not created during Install.\n";
            return 1;
        }

        $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        $entryTypeIds = array_map(fn($et) => $et->id, $matrixField->getEntryTypes());

        if (in_array($blockEntryType->id, $entryTypeIds)) {
            echo "FAIL: Install should NOT link Entry Type to Matrix. But it did.\n";
            return 1;
        }
        echo "   ✓ Install created resources but did NOT link to Matrix.\n";

        // =========================================
        // STEP 2: ENABLE (should link to Matrix)
        // =========================================
        echo "2. Enabling package...\n";
        $pm->enablePackage($handle);

        // Verify Matrix Registration
        $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        $entryTypeIds = array_map(fn($et) => $et->id, $matrixField->getEntryTypes());

        if (!in_array($blockEntryType->id, $entryTypeIds)) {
            echo "FAIL: Enable did not link Entry Type to Matrix.\n";
            return 1;
        }
        echo "   ✓ Enable linked Entry Type to Matrix.\n";

        // =========================================
        // STEP 3: PUT PACKAGE IN USE
        // =========================================
        echo "3. Creating content using the package...\n";
        $section = Craft::$app->getEntries()->getSectionByHandle('testPages');
        $sectionEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testPage');

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $sectionEntryType->id;
        $entry->title = "Lifecycle Test";
        $entry->slug = "lifecycle-test-" . time();
        $entry->authorId = 1;

        $blockEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testHeroBlock');
        echo "DEBUG: Saving content with type: " . $blockEntryType->handle . " (ID: " . $blockEntryType->id . ")\n";

        $entry->setFieldValue('site7Components', [
            'new1' => [
                'type' => $blockEntryType->handle,
                'fields' => [
                    'heroHeading' => 'Test',
                    'heroSubheading' => 'Test',
                ]
            ]
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            echo "FAIL: Could not save entry. Errors: " . json_encode($entry->getErrors()) . "\n";
            return 1;
        }
        echo "   ✓ Entry saved. ID: " . $entry->id . "\n";

        // Query database directly for blocks owned by this entry
        $rows = (new \craft\db\Query())
            ->from('{{%entries}}')
            ->where(['primaryOwnerId' => $entry->id])
            ->all();
        echo "DEBUG: Rows with primaryOwnerId " . $entry->id . " count = " . count($rows) . "\n";
        foreach ($rows as $row) {
            echo "DEBUG: Row ID " . $row['id'] . " has typeId " . ($row['typeId'] ?? 'NULL') . "\n";
        }

        // =========================================
        // STEP 4: VERIFY USAGE DETECTION
        // =========================================
        echo "4. Verifying usage detection...\n";
        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (empty($usage)) {
            echo "FAIL: PackageUsageService did not detect usage.\n";
            return 1;
        }
        echo "   ✓ Usage detected: " . count($usage) . " entries.\n";

        // =========================================
        // STEP 5: CLEANUP USAGE
        // =========================================
        echo "5. Removing usage entries...\n";
        foreach ($usage as $u) {
            Craft::$app->getElements()->deleteElement($u);
        }

        $usage = Site7Studio::getInstance()->packageUsage->getUsage($handle);
        if (!empty($usage)) {
            echo "FAIL: Package still in use after deleting entries.\n";
            return 1;
        }
        echo "   ✓ Usage cleared.\n";

        // =========================================
        // STEP 6: DISABLE (should unlink from Matrix)
        // =========================================
        echo "6. Disabling package...\n";
        $pm->disablePackage($handle);

        $matrixField = Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        $entryTypeIds = array_map(fn($et) => $et->id, $matrixField->getEntryTypes());
        if (in_array($blockEntryType->id, $entryTypeIds)) {
            echo "FAIL: Disable did not unlink Entry Type from Matrix.\n";
            return 1;
        }
        echo "   ✓ Disable unlinked Entry Type from Matrix.\n";

        // =========================================
        // STEP 7: REMOVE (should delete resources)
        // =========================================
        echo "7. Removing package...\n";
        $pm->removePackage($handle);

        $checkEntryType = Craft::$app->getEntries()->getEntryTypeByHandle('testHeroBlock');
        if ($checkEntryType) {
            echo "FAIL: Entry Type was not deleted during Remove.\n";
            return 1;
        }
        echo "   ✓ Remove deleted all generated resources.\n";

        echo "\n✅ SUCCESS: Full lifecycle test passed.\n";
        echo "   Install → Enable → Use → Detect Usage → Clear Usage → Disable → Remove\n";
        return 0;
    }
}
