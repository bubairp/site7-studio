<?php

namespace site7\studio\console\controllers;

use Craft;
use yii\console\Controller;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use Symfony\Component\Yaml\Yaml;
use site7\studio\Site7Studio;
use craft\fields\Matrix;

class MakeController extends Controller
{
    public $packageName;
    public $packageType = 'section';
    public $packageDescription = '';

    public $entryIds = '';
    public $globalSetIds = '';
    public $starterKitVersion = '1.0.0';
    public $starterKitCategory = '';
    public $starterKitTags = '';

    public function options($actionID)
    {
        if ($actionID === 'starter-kit') {
            return ['entryIds', 'globalSetIds', 'starterKitVersion', 'starterKitCategory', 'starterKitTags'];
        }
        return ['packageName', 'packageType', 'packageDescription'];
    }

    /**
     * Re-runs PackageManagerService::enablePackage() for a Section package -
     * useful when a Section was enabled before the Site7 Matrix field
     * existed (linkToMatrix() no-ops without a configured matrixFieldId),
     * so its Entry Type never got added to the Matrix field's own allowed
     * Entry Types list. Idempotent - safe to run even if already linked.
     * Usage: php craft site7-studio/make/relink-matrix cta-banner
     */
    public function actionRelinkMatrix(string $handle): int
    {
        $ok = Site7Studio::getInstance()->packageManager->enablePackage($handle);
        if (!$ok) {
            $this->stderr("Error: enablePackage('{$handle}') returned false.\n", Console::FG_RED);
            return 1;
        }
        $this->stdout("Re-enabled '{$handle}' (Matrix link refreshed).\n", Console::FG_GREEN);
        return 0;
    }

    /**
     * Console equivalent of SetupController::actionSave()'s "create" option -
     * creates the site7Components Matrix field and points the plugin's
     * matrixFieldId setting at it. Same effect as running the CP's
     * Site7 Studio Setup wizard with "Option A", just scriptable.
     * Usage: php craft site7-studio/make/setup-matrix-field
     */
    public function actionSetupMatrixField(): int
    {
        $fieldsService = Craft::$app->getFields();
        $existing = $fieldsService->getFieldByHandle('site7Components');

        if ($existing) {
            $fieldId = $existing->id;
            $this->stdout("Field 'site7Components' already exists (id {$fieldId}).\n", Console::FG_YELLOW);
        } else {
            $matrixField = new Matrix([
                'handle' => 'site7Components',
                'name' => 'Site7 Components',
            ]);
            // A brand new Matrix field legitimately has zero Entry Types at
            // this point - they arrive later as Section packages are
            // enabled (see PackageManagerService's entry-type injection).
            // Craft 5's default saveField() validation now rejects a Matrix
            // field with no Entry Types, so skip validation for this one
            // intentionally-transient save.
            if (!$fieldsService->saveField($matrixField, false)) {
                $this->stderr("Error: Failed to create Matrix field: " . json_encode($matrixField->getErrors()) . "\n", Console::FG_RED);
                return 1;
            }
            $fieldId = $matrixField->id;
            $this->stdout("Created Matrix field 'site7Components' (id {$fieldId}).\n", Console::FG_GREEN);
        }

        Craft::$app->getPlugins()->savePluginSettings(
            Site7Studio::getInstance(),
            ['matrixFieldId' => $fieldId]
        );
        $this->stdout("Setup complete - matrixFieldId set to {$fieldId}.\n", Console::FG_GREEN);

        return 0;
    }

    /**
     * Builds a real Starter Kit package - including blueprint.json - from
     * existing Entries/Global Sets, via the Website Starter Kit System's own
     * StarterKitBuilder (projectBuilder -> dependencyAnalyzer -> blueprintBuilder).
     * This is the one pipeline that writes blueprint.json, which
     * StarterKitCatalogService::isInstallable() requires before the Install
     * Wizard will accept a package - StarterKitBuilder was already fully
     * implemented but had no controller/console entry point calling it.
     *
     * Usage: php craft site7-studio/make/starter-kit "RP Craft Full Site" --entryIds=12816
     */
    public function actionStarterKit(string $name)
    {
        $entryIds = array_values(array_filter(array_map('intval', explode(',', (string)$this->entryIds))));
        if (empty($entryIds)) {
            $this->stderr("Error: --entryIds is required (comma-separated Entry IDs).\n", Console::FG_RED);
            return 1;
        }
        $globalSetIds = array_values(array_filter(array_map('intval', explode(',', (string)$this->globalSetIds))));

        $meta = [
            'name' => $name,
            'version' => $this->starterKitVersion,
            'category' => $this->starterKitCategory,
            'tags' => $this->starterKitTags,
        ];

        $this->stdout("Building Starter Kit '{$name}' from entries [" . implode(', ', $entryIds) . "]...\n", Console::FG_YELLOW);

        try {
            $result = Site7Studio::getInstance()->starterKitBuilder->build($entryIds, $globalSetIds, $meta);
        } catch (\Throwable $e) {
            $this->stderr("Error: {$e->getMessage()}\n", Console::FG_RED);
            return 1;
        }

        $handle = $result['project']->manifest->handle;
        $this->stdout("Starter Kit built: handle='{$handle}', blueprint.json written.\n", Console::FG_GREEN);

        return 0;
    }

    /**
     * Scaffolds a new Site7 Content Package.
     * Usage: php craft site7-studio/make/package <handle> --packageName="Name" --packageType="section"
     * 
     * @param string $handle The unique handle for the package (e.g. hero-banner)
     */
    public function actionPackage(string $handle)
    {
        if (!preg_match('/^[a-z0-9\-]+$/', $handle)) {
            $this->stderr("Error: Handle must contain only lowercase letters, numbers, and hyphens.\n", Console::FG_RED);
            return 1;
        }

        $name = $this->packageName ?: $this->prompt("Package Name:", ['required' => true]);
        $type = $this->packageType ?: $this->prompt("Package Type (section, pattern, starter-kit, theme):", ['required' => true, 'default' => 'section']);
        $description = $this->packageDescription ?: $this->prompt("Description:");

        $pluginPath = Craft::getAlias('@site7/studio');
        $packagesPath = dirname($pluginPath) . '/packages';
        
        $packageDir = $packagesPath . '/' . $handle;

        if (is_dir($packageDir)) {
            $this->stderr("Error: Package '{$handle}' already exists at {$packageDir}.\n", Console::FG_RED);
            return 1;
        }

        $this->stdout("Scaffolding package '{$handle}'...\n", Console::FG_YELLOW);

        // 1. Create directories
        $directories = [
            $packageDir,
            $packageDir . '/templates',
            $packageDir . '/resources',
            $packageDir . '/preview',
            $packageDir . '/demo',
        ];

        foreach ($directories as $dir) {
            FileHelper::createDirectory($dir);
        }

        // 2. manifest.json
        $manifest = [
            'schemaVersion' => '1',
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
            'version' => '1.0.0',
            'author' => 'Site7',
            'description' => $description,
            'dependencies' => []
        ];
        file_put_contents($packageDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($packageDir . '/README.md', "# {$name}\n\n" . ($description ?: 'Created via the site7-studio/make/package console command.') . "\n");

        // 3. fields.yaml
        $fieldsYaml = [
            'name' => "{$name} Fields",
            'fields' => [
                [
                    'handle' => 'heading',
                    'name' => 'Heading',
                    'type' => 'PlainText',
                    'instructions' => 'The main heading.'
                ]
            ]
        ];
        file_put_contents($packageDir . '/fields.yaml', Yaml::dump($fieldsYaml, 4, 2));

        // 4. matrix.yaml
        $matrixYaml = [
            'name' => "{$name} Matrix",
            'blocks' => [
                [
                    'handle' => lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $handle)))),
                    'name' => $name,
                    'fields' => [
                        'heading'
                    ]
                ]
            ]
        ];
        file_put_contents($packageDir . '/matrix.yaml', Yaml::dump($matrixYaml, 4, 2));

        // 5. template.twig
        $blockHandle = $matrixYaml['blocks'][0]['handle'];
        $templateStub = <<<TWIG
<div class="site7-component {$handle}">
    <h1>{{ block.heading }}</h1>
</div>
TWIG;
        file_put_contents($packageDir . '/template.twig', $templateStub);

        // 6. Resources
        file_put_contents($packageDir . '/resources/style.css', "/* {$name} Styles */\n.{$handle} { }\n");
        file_put_contents($packageDir . '/resources/script.js', "/* {$name} Scripts */\n");

        // 7. Demo content
        file_put_contents($packageDir . '/demo/content.yaml', Yaml::dump(['blocks' => []]));

        $this->stdout("Package successfully scaffolded at: {$packageDir}\n", Console::FG_GREEN);
        
        return 0;
    }
}
