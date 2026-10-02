<?php

namespace site7\studio\services\sitekit;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use Symfony\Component\Process\Process;

/**
 * Runs site-kit console commands in the background for the CP Site Kits
 * screen. Building or installing a kit takes far longer than a web request
 * should, and installing replaces vendor/ under the running app, so the CP
 * starts the same console command detached and polls its log.
 *
 * storage/site7-studio/site-kit-jobs/<id>.json  label, startedAt
 * storage/site7-studio/site-kit-jobs/<id>.log   output; last line "__EXIT__ <code>"
 */
class SiteKitJobs extends Component
{
    private const EXIT_MARKER = '__EXIT__';

    public function start(string $label, array $craftArgs): string
    {
        $dir = $this->dir();
        $id = date('Ymd-His') . '-' . StringHelper::randomString(6);
        file_put_contents("{$dir}/{$id}.json", json_encode(['id' => $id, 'label' => $label, 'startedAt' => date(DATE_ATOM)]));

        $root = rtrim(Craft::getAlias('@root'), '/');
        $command = implode(' ', array_map('escapeshellarg', array_merge([App::phpExecutable() ?? 'php', "{$root}/craft"], $craftArgs)));
        // Web server processes may run without HOME, which composer and npm need.
        $home = escapeshellarg(Craft::$app->getRuntimePath() . '/site7-home');
        $script = "export HOME=\${HOME:-{$home}} COMPOSER_HOME=\${COMPOSER_HOME:-{$home}/composer}; mkdir -p \"\$HOME\"; {$command}; echo " . self::EXIT_MARKER . ' $?';

        Process::fromShellCommandline(
            'nohup sh -c ' . escapeshellarg($script) . ' > ' . escapeshellarg("{$dir}/{$id}.log") . ' 2>&1 &',
            $root
        )->run();

        return $id;
    }

    /**
     * @return array{id: string, label: string, startedAt: string, lines: string[], done: bool, exitCode: int|null}|null
     */
    public function status(string $id): ?array
    {
        if (!preg_match('/^[\w\-]+$/', $id) || !is_file($this->dir() . "/{$id}.json")) {
            return null;
        }

        $meta = json_decode((string)file_get_contents($this->dir() . "/{$id}.json"), true);
        $log = (string)@file_get_contents($this->dir() . "/{$id}.log");
        $lines = array_values(array_filter(explode("\n", preg_replace('/\e\[[\d;]*m/', '', $log)), fn($line) => trim($line) !== ''));

        $exitCode = null;
        if ($lines && preg_match('/^' . self::EXIT_MARKER . ' (\d+)$/', trim(end($lines)), $m)) {
            $exitCode = (int)$m[1];
            array_pop($lines);
        }

        return $meta + ['lines' => $lines, 'done' => $exitCode !== null, 'exitCode' => $exitCode];
    }

    /**
     * Most recent jobs, newest first.
     *
     * @return array[]
     */
    public function recent(int $limit = 5): array
    {
        $files = glob($this->dir() . '/*.json') ?: [];
        rsort($files);

        return array_values(array_filter(array_map(fn($file) => $this->status(basename($file, '.json')), array_slice($files, 0, $limit))));
    }

    private function dir(): string
    {
        $dir = Craft::getAlias('@storage') . '/site7-studio/site-kit-jobs';
        FileHelper::createDirectory($dir);
        return $dir;
    }
}
