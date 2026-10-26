<?php

namespace site7\studio\tests\unit\services\library;

use Codeception\Test\Unit;
use site7\studio\services\library\FileBaselineStore;
use site7\studio\services\library\PackageFileUpdater as U;
use site7\studio\services\synchronization\PackageUpdatePlanner;

/**
 * Library updates of a Section package's files on real files in a temporary
 * folder: the block template and manifest-owned files (docs/53 §3).
 */
class PackageFileUpdaterTest extends Unit
{
    protected \UnitTester $tester;

    private string $tmp;
    private MemoryStore $store;

    protected function _before(): void
    {
        $this->tmp = sys_get_temp_dir() . '/s7-file-updater-' . uniqid();
        mkdir("{$this->tmp}/site/templates/_blocks", 0777, true);
        $this->store = new MemoryStore();
    }

    protected function _after(): void
    {
        $this->rm($this->tmp);
    }

    // ------------------------------------------------------------ the rule

    public function testTheRuleForOneFile(): void
    {
        $this->assertSame(U::UPDATE, U::action('a', 'a', 'b', true), 'unchanged here: new version');
        $this->assertSame(U::KEEP, U::action('a', 'mine', 'b', true), 'edited here, changed upstream: kept');
        $this->assertSame(U::KEEP, U::action('a', 'mine', 'a', true), 'edited here, unchanged upstream: kept');
        $this->assertSame(U::REFRESH, U::action('a', 'b', 'b', true), 'already the new version: tracking brought up to date');
        $this->assertSame(U::REFRESH, U::action('a', 'a', 'a', true), 'nothing changed: tracking brought up to date');
        $this->assertSame(U::ADD, U::action(null, null, 'b', false), 'new upstream, nothing here: added');
        $this->assertSame(U::KEEP, U::action(null, 'other', 'b', false), 'new upstream, something else here: left alone');
        $this->assertSame(U::REMOVE, U::action('a', 'a', null, true), 'removed upstream, unchanged here: deleted');
        $this->assertSame(U::KEEP_REMOVED, U::action('a', 'mine', null, true), 'removed upstream, edited here: kept');
        $this->assertSame(U::DELETED_HERE, U::action('a', null, 'b', true), 'deleted here: not recreated');
        $this->assertSame(U::DELETED_HERE, U::action(null, null, 'b', true), 'deleted here, only the tracking row knows: not recreated');
        $this->assertSame(U::FORGET, U::action('a', null, null, true), 'gone on both sides: the row goes');
    }

    // ------------------------------------------------- the block template

    public function testAnUnchangedTemplateIsReplacedAndTrackedUnderTemplates(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertSame('v2', $this->live('templates/_blocks/testimonials.twig'));
        $this->assertSame(['template _blocks/testimonials.twig'], $report['updated']);
        $row = $this->store->rows[7]['templates/_blocks/testimonials.twig'];
        $this->assertSame(hash('sha256', 'v2'), $row['checksum']);
        $this->assertSame('1.0.1', $row['installedVersion']);
        $this->assertSame('testimonials', $row['resourceHandle']);
        $this->assertArrayNotHasKey('_blocks/testimonials.twig', $this->store->rows[7], 'never the old path without templates/');
        $this->assertSame([$this->path('templates/_blocks/testimonials.twig')], $report['templates']);
    }

    public function testAnEditedTemplateIsKeptAndItsTrackingRowUntouched(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1', 'customer edit');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertSame('customer edit', $this->live('templates/_blocks/testimonials.twig'));
        $this->assertSame(['template _blocks/testimonials.twig'], $report['kept']);
        $this->assertSame(hash('sha256', 'v1'), $this->store->rows[7]['templates/_blocks/testimonials.twig']['checksum']);
        $this->assertSame('1.0.0', $this->store->rows[7]['templates/_blocks/testimonials.twig']['installedVersion']);
    }

    public function testATemplateAlreadyAtTheNewVersionGetsItsRowBroughtUpToDate(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1', 'v2');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertSame(1, $report['unchanged']);
        $this->assertSame(hash('sha256', 'v2'), $this->store->rows[7]['templates/_blocks/testimonials.twig']['checksum']);
        $this->assertSame('1.0.1', $this->store->rows[7]['templates/_blocks/testimonials.twig']['installedVersion']);
    }

    public function testADeletedTemplateIsNotRecreated(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->store->record(7, 'testimonials', 'templates/_blocks/testimonials.twig', '1.0.0', hash('sha256', 'v1'));

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertFileDoesNotExist($this->path('templates/_blocks/testimonials.twig'));
        $this->assertSame(['template _blocks/testimonials.twig'], $report['deletedHere']);
        $this->assertSame('1.0.0', $this->store->rows[7]['templates/_blocks/testimonials.twig']['installedVersion'], 'the row still remembers the deletion');
    }

    public function testARenamedBlockRemovesTheOldUnchangedTemplateOnly(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2', 'handle' => 'quotes']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertFileDoesNotExist($this->path('templates/_blocks/testimonials.twig'));
        $this->assertSame('v2', $this->live('templates/_blocks/quotes.twig'));
        $this->assertSame(['template _blocks/testimonials.twig'], $report['removed']);
        $this->assertSame(['template _blocks/quotes.twig'], $report['added']);
        $this->assertArrayNotHasKey('templates/_blocks/testimonials.twig', $this->store->rows[7]);
        $this->assertArrayHasKey('templates/_blocks/quotes.twig', $this->store->rows[7]);
    }

    public function testATemplateRemovedUpstreamButEditedHereIsKept(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => null]);
        $this->installed('templates/_blocks/testimonials.twig', 'v1', 'customer edit');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertSame('customer edit', $this->live('templates/_blocks/testimonials.twig'));
        $this->assertSame(['template _blocks/testimonials.twig (removed upstream, edited here)'], $report['kept']);
        $this->assertArrayHasKey('templates/_blocks/testimonials.twig', $this->store->rows[7]);
    }

    // ------------------------------------------------------ owned files

    public function testOwnedFilesFollowTheSameRule(): void
    {
        [$old, $new] = $this->versions(
            ['template' => 'v1', 'owned' => ['frontend/src/css/a.css' => 'a1', 'frontend/src/css/b.css' => 'b1', 'frontend/src/css/gone.css' => 'g1', 'frontend/src/css/gone-edited.css' => 'e1']],
            ['template' => 'v1', 'owned' => ['frontend/src/css/a.css' => 'a2', 'frontend/src/css/b.css' => 'b2', 'frontend/src/css/new.css' => 'n1']],
        );
        $this->installed('templates/_blocks/testimonials.twig', 'v1');
        $this->installed('frontend/src/css/a.css', 'a1');
        $this->installed('frontend/src/css/b.css', 'b1', 'b edited');
        $this->installed('frontend/src/css/gone.css', 'g1');
        $this->installed('frontend/src/css/gone-edited.css', 'e1', 'e edited');

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertSame('a2', $this->live('frontend/src/css/a.css'), 'unchanged: updated');
        $this->assertSame('b edited', $this->live('frontend/src/css/b.css'), 'edited: kept');
        $this->assertSame('n1', $this->live('frontend/src/css/new.css'), 'new: added');
        $this->assertFileDoesNotExist($this->path('frontend/src/css/gone.css'), 'removed upstream, unchanged: deleted');
        $this->assertSame('e edited', $this->live('frontend/src/css/gone-edited.css'), 'removed upstream, edited: kept');

        $this->assertSame(['file frontend/src/css/a.css'], $report['updated']);
        $this->assertSame(['file frontend/src/css/new.css'], $report['added']);
        $this->assertSame(['file frontend/src/css/gone.css'], $report['removed']);
        $this->assertEqualsCanonicalizing(['file frontend/src/css/b.css', 'file frontend/src/css/gone-edited.css (removed upstream, edited here)'], $report['kept']);

        $rows = $this->store->rows[7];
        $this->assertSame(hash('sha256', 'a2'), $rows['frontend/src/css/a.css']['checksum']);
        $this->assertSame(hash('sha256', 'b1'), $rows['frontend/src/css/b.css']['checksum'], 'kept file: row untouched');
        $this->assertSame('testimonials', $rows['frontend/src/css/new.css']['resourceHandle'], 'owned files are tracked under the package handle, as install does');
        $this->assertArrayNotHasKey('frontend/src/css/gone.css', $rows);
    }

    public function testOwnedFilesOutsideTheFrontendOrOwnedByAnotherPackageAreLeftAlone(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v1', 'owned' => ['config/evil.php' => '<?php', 'frontend/src/css/shared.css' => 's1']]);
        $this->installed('templates/_blocks/testimonials.twig', 'v1');
        $this->store->owners['frontend/src/css/shared.css'] = 'services';

        $report = $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $this->assertFileDoesNotExist($this->path('config/evil.php'));
        $this->assertFileDoesNotExist($this->path('frontend/src/css/shared.css'));
        $this->assertContains('owned file config/evil.php is outside the frontend source folders - not written', $report['notes']);
        $this->assertContains('file frontend/src/css/shared.css belongs to package services - left alone', $report['notes']);
    }

    // ----------------------------------------------------- failed writes

    public function testAFailedWriteThrowsAndRecordsNothing(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1');
        $broken = function(string $source, string $target): void {
            file_put_contents($target, 'half a fi');
        };

        try {
            (new U("{$this->tmp}/site", $this->store, $broken))->apply(7, 'testimonials', '1.0.1', $old, $new);
            $this->fail('a failed write must not pass');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("doesn't match the new version", $e->getMessage());
        }
        $this->assertSame(hash('sha256', 'v1'), $this->store->rows[7]['templates/_blocks/testimonials.twig']['checksum'], 'the row still describes the installed version');
        $this->assertSame('1.0.0', $this->store->rows[7]['templates/_blocks/testimonials.twig']['installedVersion']);
    }

    // ------------------------------------------------ rollback compatibility

    public function testRowsWrittenByAnUpdateAreWhatRollbackReads(): void
    {
        [$old, $new] = $this->versions(['template' => 'v1'], ['template' => 'v2']);
        $this->installed('templates/_blocks/testimonials.twig', 'v1');
        $this->updater()->apply(7, 'testimonials', '1.0.1', $old, $new);

        $planner = new PackageUpdatePlanner();
        foreach ($this->store->rows[7] as $path => $row) {
            // Rollback maps each tracked path to its archive entry, then classifies.
            $this->assertSame('packages/testimonials/template.twig', $planner->resolveArchiveEntryName('testimonials', '/no/archive.s7pkg', $path));
            $result = $planner->classify(['checksum' => $row['checksum'], 'targetPath' => $path, 'resourceHandle' => $row['resourceHandle']], hash_file('sha256', $this->path($path)), hash('sha256', 'v2'));
            $this->assertSame(PackageUpdatePlanner::RESULT_UNCHANGED, $result['result'], 'the row matches the file on disk');
        }
    }

    // ------------------------------------------------------------ helpers

    private function updater(): U
    {
        return new U("{$this->tmp}/site", $this->store, function(string $source, string $target): void {
            @mkdir(dirname($target), 0777, true);
            copy($source, $target);
        });
    }

    /** Two package versions; template null = the version has none. */
    private function versions(array $old, array $new): array
    {
        return [$this->package('old', $old), $this->package('new', $new)];
    }

    private function package(string $name, array $spec): string
    {
        $dir = "{$this->tmp}/{$name}";
        mkdir($dir, 0777, true);
        $handle = $spec['handle'] ?? 'testimonials';
        file_put_contents("{$dir}/schema.json", json_encode(['entryType' => 'uid-et', 'items' => [['kind' => 'entryTypes', 'uid' => 'uid-et', 'handle' => $handle]]]));
        if (($spec['template'] ?? null) !== null) {
            file_put_contents("{$dir}/template.twig", $spec['template']);
        }
        $owned = [];
        foreach ($spec['owned'] ?? [] as $target => $content) {
            $source = 'files/' . basename($target);
            @mkdir("{$dir}/files", 0777, true);
            file_put_contents("{$dir}/{$source}", $content);
            $owned[] = ['sourcePath' => $source, 'targetPath' => $target];
        }
        file_put_contents("{$dir}/manifest.json", json_encode(['ownedFiles' => $owned]));

        return $dir;
    }

    /** A file installed at 1.0.0 with $installed; $live = what the site has now (default: unchanged). */
    private function installed(string $path, string $installed, ?string $live = null): void
    {
        @mkdir(dirname($this->path($path)), 0777, true);
        file_put_contents($this->path($path), $live ?? $installed);
        $handle = str_starts_with($path, 'templates/_blocks/') ? basename($path, '.twig') : 'testimonials';
        $this->store->record(7, $handle, $path, '1.0.0', hash('sha256', $installed));
    }

    private function path(string $path): string
    {
        return "{$this->tmp}/site/{$path}";
    }

    private function live(string $path): string
    {
        return (string)file_get_contents($this->path($path));
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

/** In-memory FileBaselineStore for the tests. */
class MemoryStore implements FileBaselineStore
{
    /** @var array<int, array<string, array>> packageId => targetPath => row */
    public array $rows = [];
    /** @var array<string, string> targetPath => other package handle */
    public array $owners = [];

    public function find(int $packageId, string $targetPath): ?array
    {
        return $this->rows[$packageId][$targetPath] ?? null;
    }

    public function record(int $packageId, string $resourceHandle, string $targetPath, string $version, string $checksum): void
    {
        $this->rows[$packageId][$targetPath] = ['resourceHandle' => $resourceHandle, 'installedVersion' => $version, 'checksum' => $checksum];
    }

    public function remove(int $packageId, string $targetPath): void
    {
        unset($this->rows[$packageId][$targetPath]);
    }

    public function otherOwner(int $packageId, string $targetPath): ?string
    {
        return $this->owners[$targetPath] ?? null;
    }
}
