<?php

namespace site7\studio\services\synchronization;

use craft\base\Component;
use craft\helpers\StringHelper;
use site7\studio\models\synchronization\SynchronizationSession;
use site7\studio\records\SynchronizationSessionRecord;

/**
 * Pure persistence for SynchronizationSession (Website Starter Kit System
 * Phase 8) - mirrors InstallationSessionService exactly. Named
 * loadSession() rather than load() for the same reason InstallationSessionService
 * is - craft\base\Component extends craft\base\Model extends yii\base\Model,
 * which reserves load($data, $formName = null).
 */
class SynchronizationSessionService extends Component
{
    public function create(string $handle, string $packagePath, string $fromVersion, string $toVersion, ?array $oldBlueprint, ?array $newBlueprint, bool $dryRun): SynchronizationSession
    {
        $session = new SynchronizationSession(
            uid: StringHelper::UUID(),
            packageHandle: $handle,
            packagePath: $packagePath,
            fromVersion: $fromVersion,
            toVersion: $toVersion,
            dryRun: $dryRun,
            oldBlueprint: $oldBlueprint,
            newBlueprint: $newBlueprint,
        );

        $this->save($session);

        return $session;
    }

    public function loadSession(string $uid): ?SynchronizationSession
    {
        $record = SynchronizationSessionRecord::find()->where(['uid' => $uid])->one();
        if (!$record) {
            return null;
        }

        $data = json_decode($record->data, true) ?: [];
        $data['uid'] = $record->uid;
        $data['dateCreated'] = (string)$record->dateCreated;
        $data['dateUpdated'] = (string)$record->dateUpdated;

        return SynchronizationSession::fromArray($data);
    }

    public function save(SynchronizationSession $session): void
    {
        $record = SynchronizationSessionRecord::find()->where(['uid' => $session->uid])->one()
            ?? new SynchronizationSessionRecord(['uid' => $session->uid]);

        $record->handle = $session->packageHandle;
        $record->status = $session->status;
        $record->data = json_encode($session->toArray(), JSON_UNESCAPED_SLASHES);
        $record->save(false);

        $session->dateCreated = (string)$record->dateCreated;
        $session->dateUpdated = (string)$record->dateUpdated;
    }
}
