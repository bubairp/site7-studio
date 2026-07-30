<?php

namespace site7\studio\services\installation;

use craft\base\Component;
use craft\helpers\StringHelper;
use site7\studio\models\installation\InstallationSession;
use site7\studio\records\InstallationSessionRecord;

/**
 * Pure persistence for InstallationSession (Website Starter Kit System
 * Phase 7) - create/load/save/delete only. Contains no installation logic
 * and no orchestration decisions of its own; InstallationStageRunner and
 * InstallationOrchestratorService are the only things that decide what
 * happens to a session's state, this class only stores and retrieves it.
 */
class InstallationSessionService extends Component
{
    public function create(string $starterKitHandle, string $packagePath, ?array $blueprint, bool $dryRun): InstallationSession
    {
        $session = new InstallationSession(
            uid: StringHelper::UUID(),
            starterKitHandle: $starterKitHandle,
            packagePath: $packagePath,
            dryRun: $dryRun,
            blueprint: $blueprint,
        );

        $this->save($session);

        return $session;
    }

    /**
     * Named loadSession() rather than load() - craft\base\Component extends
     * craft\base\Model extends yii\base\Model, which reserves load($data,
     * $formName = null); a same-named override here would be a fatal
     * incompatible-signature error, the same class of bug
     * PHASE-6-INSTALLATION-ORCHESTRATION.md already hit three times with
     * validate()/save()-shaped names on this exact base class.
     */
    public function loadSession(string $uid): ?InstallationSession
    {
        $record = InstallationSessionRecord::find()->where(['uid' => $uid])->one();
        if (!$record) {
            return null;
        }

        $data = json_decode($record->data, true) ?: [];
        $data['uid'] = $record->uid;
        $data['dateCreated'] = (string)$record->dateCreated;
        $data['dateUpdated'] = (string)$record->dateUpdated;

        return InstallationSession::fromArray($data);
    }

    public function save(InstallationSession $session): void
    {
        $record = InstallationSessionRecord::find()->where(['uid' => $session->uid])->one()
            ?? new InstallationSessionRecord(['uid' => $session->uid]);

        $record->starterKitHandle = $session->starterKitHandle;
        $record->status = $session->status;
        $record->data = json_encode($session->toArray(), JSON_UNESCAPED_SLASHES);
        $record->save(false);

        $session->dateCreated = (string)$record->dateCreated;
        $session->dateUpdated = (string)$record->dateUpdated;
    }

    public function delete(string $uid): void
    {
        InstallationSessionRecord::deleteAll(['uid' => $uid]);
    }
}
