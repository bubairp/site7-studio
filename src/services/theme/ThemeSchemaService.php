<?php

namespace site7\studio\services\theme;

use Craft;
use craft\base\Component;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * The structure half of a Theme package (docs/49_THEME_PACKAGE.md): a site's
 * sections, entry types, fields, volumes, filesystems, category/tag groups,
 * global sets and CKEditor configs, as Craft's own project config with UIDs -
 * the format v2 idea (SectionSchemaService) widened from one block to a site.
 *
 * Captured as everything reachable from the site's roots (sections, groups,
 * global sets, volumes), except the page-builder field's blocks: that field
 * is captured with no entry types, and each Section package links its own
 * block in when installed - so blocks stay separate Library packages.
 *
 * Installed through ProjectConfig::set() in dependency order, then re-applied
 * so cycles resolve - existing items (matched by path/UID) are reused and
 * never modified; a different item with the same handle stops the install
 * before anything changes. The source site's UID is replaced with the target
 * site's own (sections' and groups' site settings are keyed by site UID):
 * a Theme installs into a site, it doesn't replace it.
 */
class ThemeSchemaService extends Component
{
    public const FILE = 'schema.json';
    public const FORMAT_VERSION = 1;

    /** Project config paths whose children are structure, keyed by UID (fs: by handle). */
    public const KINDS = ['fs', 'volumes', 'sections', 'entryTypes', 'fields', 'categoryGroups', 'tagGroups', 'globalSets', 'ckeditor.configs'];

    /**
     * Kinds a theme starts from; everything else is pulled in through
     * references. Fields and entry types are roots too: templates read
     * fields by handle (`entry.formBorderRadius ?? false`), and in Craft
     * that's only safe while some field or layout has that handle - rp-craft's
     * sitemap reads handles of the contact block's layout. Blocks still
     * come from Section packages: the page-builder field ships without
     * them, and a Section package links its block in (reusing the entry
     * type and fields already here, same UID) and installs its template.
     */
    private const ROOT_KINDS = ['fs', 'volumes', 'sections', 'categoryGroups', 'tagGroups', 'globalSets', 'fields', 'entryTypes'];

    private const UID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /**
     * @return array{formatVersion: int, sourceSites: string[], sourceSiteGroups: string[], pageBuilderField: ?string, pageBuilderEntryTypes: string[], items: array}
     */
    public function capture(?string $pageBuilderFieldUid): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $index = $this->index();

        $pageBuilderEntryTypes = [];
        $items = [];
        $visited = [];
        $visit = function(string $path) use (&$visit, &$items, &$visited, $index, $projectConfig, $pageBuilderFieldUid, &$pageBuilderEntryTypes) {
            if (isset($visited[$path])) {
                return;
            }
            $visited[$path] = true;
            $config = $projectConfig->get($path);
            if (!is_array($config)) {
                return;
            }

            if ($pageBuilderFieldUid && $path === "fields.{$pageBuilderFieldUid}") {
                $unpacked = ProjectConfigHelper::unpackAssociativeArrays(['x' => $config['settings']['entryTypes'] ?? []])['x'];
                $pageBuilderEntryTypes = array_values(array_filter(array_map(fn($et) => is_array($et) ? ($et['uid'] ?? null) : $et, (array)$unpacked)));
                $config['settings']['entryTypes'] = [];
            }

            foreach (self::references($path, $config, $index) as $ref) {
                $visit($ref);
            }
            $items[] = ['path' => $path, 'handle' => (string)($config['handle'] ?? $config['name'] ?? ''), 'config' => $config];
        };

        foreach (self::ROOT_KINDS as $kind) {
            foreach (array_keys($projectConfig->get($kind) ?? []) as $key) {
                $visit("{$kind}.{$key}");
            }
        }

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'sourceSites' => array_keys($projectConfig->get('sites') ?? []),
            'sourceSiteGroups' => array_keys($projectConfig->get('siteGroups') ?? []),
            'pageBuilderField' => $pageBuilderFieldUid,
            'pageBuilderEntryTypes' => $pageBuilderEntryTypes,
            'items' => $items,
        ];
    }

    /**
     * Paths of the structure items a config points at: any UID of an indexed
     * item inside any string value ("section:<uid>", fieldUid, a Matrix
     * field's entry types...), plus a volume's filesystems, which are keyed
     * by handle.
     *
     * @param array<string, string> $index UID/fs handle => path
     * @return string[]
     */
    public static function references(string $path, array $config, array $index): array
    {
        $refs = [];
        array_walk_recursive($config, function($value) use (&$refs, $index) {
            if (is_string($value) && preg_match_all(self::UID_PATTERN, $value, $m)) {
                foreach ($m[0] as $uid) {
                    if (isset($index[$uid])) {
                        $refs[$index[$uid]] = true;
                    }
                }
            }
        });
        if (str_starts_with($path, 'volumes.')) {
            foreach (['fs', 'transformFs'] as $key) {
                if (is_string($config[$key] ?? null) && isset($index["fs:{$config[$key]}"])) {
                    $refs[$index["fs:{$config[$key]}"]] = true;
                }
            }
        }
        unset($refs[$path]);

        return array_keys($refs);
    }

    /** @return array<string, string> UID (or "fs:<handle>") => path */
    private function index(): array
    {
        $index = [];
        foreach (self::KINDS as $kind) {
            foreach (array_keys(Craft::$app->getProjectConfig()->get($kind) ?? []) as $key) {
                $index[$kind === 'fs' ? "fs:{$key}" : $key] = "{$kind}.{$key}";
            }
        }
        return $index;
    }

    /**
     * The schema with the source site's UIDs replaced by this site's primary site.
     */
    public function forThisSite(array $schema): array
    {
        $primary = Craft::$app->getSites()->getPrimarySite();
        $map = [];
        foreach ($schema['sourceSites'] ?? [] as $uid) {
            $map[$uid] = $primary->uid;
        }
        foreach ($schema['sourceSiteGroups'] ?? [] as $uid) {
            $map[$uid] = $primary->getGroup()->uid;
        }

        return $map ? json_decode(strtr(json_encode($schema), $map), true) : $schema;
    }

    /**
     * @return array{create: array, reuse: array, replace: string[], conflicts: string[], missingTypes: string[]}
     *   replace: UIDs of Setup placeholder fields the Theme's own field takes over
     */
    public function plan(array $schema): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $plan = ['create' => [], 'reuse' => [], 'replace' => [], 'conflicts' => [], 'missingTypes' => []];

        foreach ($schema['items'] as $item) {
            if ($projectConfig->get($item['path']) !== null) {
                $plan['reuse'][] = $item;
                continue;
            }
            [$kind, $key] = $this->split($item['path']);
            $handle = $item['config']['handle'] ?? null;
            if ($handle !== null && $kind !== 'fs') {
                foreach ($projectConfig->get($kind) ?? [] as $otherKey => $other) {
                    if (($other['handle'] ?? null) === $handle && $otherKey !== $key) {
                        if ($kind === 'fields' && self::isSetupPlaceholder((string)$otherKey, $other)) {
                            $plan['replace'][] = (string)$otherKey;
                            break;
                        }
                        $plan['conflicts'][] = "{$kind} '{$handle}' already exists here with a different UID.";
                        continue 2;
                    }
                }
            }
            $type = $item['config']['type'] ?? null;
            if ($kind === 'fields' && is_string($type) && !class_exists($type)) {
                $plan['missingTypes'][] = "Field '{$handle}' needs {$type} - install its plugin first.";
                continue;
            }
            $plan['create'][] = $item;
        }

        return $plan;
    }

    /**
     * @return array{created: int, reused: int}
     * @throws \Exception on conflicts or missing field types, before changing anything
     */
    public function install(array $schema): array
    {
        $schema = $this->forThisSite($schema);
        $plan = $this->plan($schema);
        if ($plan['conflicts'] || $plan['missingTypes']) {
            throw new \Exception(implode(' ', array_merge($plan['conflicts'], $plan['missingTypes'])));
        }

        // Setup's empty site7Components field gives way to the Theme's own.
        foreach ($plan['replace'] as $uid) {
            $field = Craft::$app->getFields()->getFieldByUid($uid);
            if ($field && !Craft::$app->getFields()->deleteField($field)) {
                throw new \Exception("Could not remove the empty '{$field->handle}' field Setup created.");
            }
            $plugin = \site7\studio\Site7Studio::getInstance();
            if ($plugin->getSettings()->matrixFieldUid === $uid) {
                Craft::$app->getPlugins()->savePluginSettings($plugin, \site7\studio\models\Settings::mergeWithStored(['matrixFieldUid' => null]));
            }
        }

        $projectConfig = Craft::$app->getProjectConfig();
        foreach ($plan['create'] as $item) {
            $projectConfig->set($item['path'], $item['config'], "Site7 Studio theme: {$item['path']}");
        }
        foreach ($plan['create'] as $item) {
            $projectConfig->set($item['path'], $item['config'], null, true, true);
        }

        $missing = array_filter($plan['create'], fn($item) => $projectConfig->get($item['path']) === null);
        if ($missing) {
            throw new \Exception('Craft did not create: ' . implode(', ', array_column($missing, 'path')));
        }

        return ['created' => count($plan['create']), 'reused' => count($plan['reuse'])];
    }

    /**
     * The Matrix field Site7 Studio's Setup creates on a fresh site
     * (SetupController, make/setup-matrix-field): site7Components, still
     * without any block type and not used in any field layout. A Theme
     * replaces it with its own field of that handle - otherwise "complete
     * Setup, then install a Starter Kit" stops on a handle conflict.
     */
    public static function isSetupPlaceholder(string $uid, array $config): bool
    {
        if (($config['handle'] ?? null) !== 'site7Components' || ($config['type'] ?? null) !== \craft\fields\Matrix::class
            || !empty($config['settings']['entryTypes'])) {
            return false;
        }
        $field = Craft::$app->getFields()->getFieldByUid($uid);

        return $field !== null && Craft::$app->getFields()->findFieldUsages($field) === [];
    }

    /** @return array{0: string, 1: string} kind, key */
    private function split(string $path): array
    {
        $dot = strrpos($path, '.');
        return [substr($path, 0, $dot), substr($path, $dot + 1)];
    }
}
