<?php

namespace site7\studio\services\installation;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Symfony\Component\Process\Process;
use site7\studio\models\installation\InstallationSession;
use site7\studio\services\ComposerDependencyScanner;

/**
 * Runs an InstallationSession to completion by looping over
 * InstallationStageRunner::runNextStage() - but never by calling it directly
 * in this process. Every stage is executed inside its own freshly spawned
 * `php craft site7-studio/install/run-stage <uid>` subprocess instead, which
 * is what actually gives Phase 6's proven finding a real fix: installing a
 * plugin via Composer and then enabling it via Craft's Plugins service
 * cannot happen in the same PHP process (Craft caches its Composer-plugin
 * manifest once per process). A generic Craft queue runner alone doesn't
 * guarantee that separation - `queue/run`/`queue/listen` process many jobs
 * in one long-lived loop within a single process - so this class provides
 * the actual OS-level process boundary explicitly, the same way
 * NpmExecutor already shells out via Symfony Process rather than assuming
 * anything about the calling process's lifetime.
 *
 * Both the CP wizard's queue job (InstallStarterKitJob) and the console
 * command (`site7-studio/install/run`) call this same class, so neither
 * entry point duplicates the stage-boundary logic - exactly the
 * "support future queue-based or CLI execution without architectural
 * changes" requirement.
 */
class InstallationOrchestratorService extends Component
{
    private const STAGE_TIMEOUT_SECONDS = 900;

    public ?InstallationSessionService $sessions = null;

    public function init(): void
    {
        parent::init();
        $this->sessions ??= new InstallationSessionService();
    }

    /**
     * @param callable(InstallationSession): void|null $onStageBoundary invoked after each stage's subprocess exits, session freshly reloaded
     */
    public function runToCompletion(string $sessionUid, ?callable $onStageBoundary = null): InstallationSession
    {
        while (true) {
            $session = $this->sessions->loadSession($sessionUid);
            if ($session === null) {
                throw new \RuntimeException("No InstallationSession found for uid {$sessionUid}.");
            }

            if ($session->isDone()) {
                return $session;
            }

            $exitCode = $this->runStageSubprocess($sessionUid);

            $session = $this->sessions->loadSession($sessionUid);
            if ($session === null) {
                throw new \RuntimeException("InstallationSession {$sessionUid} disappeared mid-run.");
            }

            if ($exitCode !== 0 && !$session->isDone()) {
                $session->status = InstallationSession::STATUS_FAILED;
                $session->fatalError = "The install-stage subprocess exited with code {$exitCode}.";
                $this->sessions->save($session);
            }

            if ($onStageBoundary) {
                $onStageBoundary($session);
            }

            if ($session->isDone()) {
                return $session;
            }
        }
    }

    /** Spawns exactly one `php craft site7-studio/install/run-stage <uid>` subprocess and waits for it to exit. */
    private function runStageSubprocess(string $sessionUid): int
    {
        $phpExecutable = App::phpExecutable();
        $craftScript = (new ComposerDependencyScanner())->projectRoot() . '/craft';

        if (!$phpExecutable || !is_file($craftScript)) {
            return 1;
        }

        $process = new Process([$phpExecutable, $craftScript, 'site7-studio/install/run-stage', $sessionUid]);
        $process->setTimeout(self::STAGE_TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            Craft::warning(
                "Install-stage subprocess for session {$sessionUid} exited with code {$process->getExitCode()}: {$process->getErrorOutput()}",
                'site7-studio'
            );
        }

        return $process->getExitCode() ?? 1;
    }
}
