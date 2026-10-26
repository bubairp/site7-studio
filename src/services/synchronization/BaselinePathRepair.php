<?php

namespace site7\studio\services\synchronization;

/**
 * Repairs site7_installed_files rows a Library update saved as
 * "_blocks/<handle>.twig" instead of "templates/_blocks/<handle>.twig"
 * (fixed in LibraryUpdater with PackageFileUpdater, docs/43 #67). Such a row
 * points at <root>/_blocks/…, a file that doesn't exist, while the row install
 * wrote under the right path kept the version from before the update.
 *
 * For each wrong row, per package:
 * - no row under the right path: the wrong row moves there;
 * - both rows: the one whose checksum matches the file on disk wins; if both
 *   or neither match, the more recently written one wins, and on a tie the
 *   row already under the right path stays. An empty checksum (a failed
 *   write) never wins over a real one. The losing row is deleted, so a stale
 *   row never overwrites a correct one.
 *
 * Pure: it returns the changes, the migration applies them; running it again
 * finds nothing to do.
 */
class BaselinePathRepair
{
    private const WRONG = '#^_blocks/[^/]+\.twig$#';

    /**
     * @param array<int, array{id: int, packageId: int, targetPath: string, checksum: ?string, installedVersion: ?string, resourceHandle: ?string, dateUpdated: ?string}> $rows every row
     * @param callable(string $targetPath): ?string $liveChecksum checksum of the file at a Craft-root-relative path, null if missing
     * @return array<int, array{op: string, id: int, set?: array}> op: update | delete
     */
    public static function plan(array $rows, callable $liveChecksum): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['packageId'] . "\0" . $row['targetPath']] = $row;
        }

        $ops = [];
        foreach ($rows as $wrong) {
            if (!preg_match(self::WRONG, $wrong['targetPath'])) {
                continue;
            }
            $path = 'templates/' . $wrong['targetPath'];
            $right = $byKey[$wrong['packageId'] . "\0" . $path] ?? null;
            if ($right === null) {
                $ops[] = ['op' => 'update', 'id' => (int)$wrong['id'], 'set' => ['targetPath' => $path]];
                continue;
            }
            if (self::wrongWins($wrong, $right, $liveChecksum($path))) {
                $ops[] = ['op' => 'update', 'id' => (int)$right['id'], 'set' => [
                    'checksum' => $wrong['checksum'],
                    'installedVersion' => $wrong['installedVersion'],
                    'resourceHandle' => $wrong['resourceHandle'] ?: $right['resourceHandle'],
                ]];
            }
            $ops[] = ['op' => 'delete', 'id' => (int)$wrong['id']];
        }

        return $ops;
    }

    /**
     * Rows left at an older version: an update that found the site already at
     * the new version didn't refresh its row. A row is brought up to the
     * installed version only when the file on disk is exactly that version's
     * own file (so it's ours and unedited); anything else is left alone.
     *
     * @param array<int, array{id: int, packageId: int, targetPath: string, checksum: ?string, installedVersion: ?string}> $rows rows after plan()'s changes
     * @param callable(string $targetPath): ?string $liveChecksum
     * @param callable(int $packageId, string $targetPath): ?array{checksum: string, version: string} $installed the installed version's own file, null if unknown
     * @return array<int, array{op: string, id: int, set: array}>
     */
    public static function refreshPlan(array $rows, callable $liveChecksum, callable $installed): array
    {
        $ops = [];
        foreach ($rows as $row) {
            if (!preg_match('#^templates/_blocks/[^/]+\.twig$#', $row['targetPath'])) {
                continue;
            }
            $live = $liveChecksum($row['targetPath']);
            $own = $installed((int)$row['packageId'], $row['targetPath']);
            if ($live === null || $own === null || $live !== $own['checksum'] || $live === (string)$row['checksum']) {
                continue;
            }
            $ops[] = ['op' => 'update', 'id' => (int)$row['id'], 'set' => ['checksum' => $live, 'installedVersion' => $own['version']]];
        }

        return $ops;
    }

    private static function wrongWins(array $wrong, array $right, ?string $live): bool
    {
        $w = (string)($wrong['checksum'] ?? '');
        $r = (string)($right['checksum'] ?? '');
        if ($w === '') {
            return false;
        }
        if ($r === '') {
            return true;
        }
        if ($live !== null && ($w === $live) !== ($r === $live)) {
            return $w === $live;
        }

        return strcmp((string)($wrong['dateUpdated'] ?? ''), (string)($right['dateUpdated'] ?? '')) > 0;
    }
}
