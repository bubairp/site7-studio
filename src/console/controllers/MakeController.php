<?php

namespace site7\studio\console\controllers;

use Craft;
use yii\console\Controller;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use Symfony\Component\Yaml\Yaml;

class MakeController extends Controller
{
    public $packageName;
    public $packageType = 'section';
    public $packageDescription = '';

    public function options($actionID)
    {
        return ['packageName', 'packageType', 'packageDescription'];
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
