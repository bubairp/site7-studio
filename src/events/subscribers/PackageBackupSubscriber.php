<?php

namespace site7\studio\events\subscribers;

use site7\studio\events\EventSubscriberInterface;
use site7\studio\events\ResourceImportedEvent;
use site7\studio\services\support\PackageBackupService;

/**
 * Bridges every "Import Existing X" flow (Section, Website, Page, Matrix
 * Entry Type - anything that dispatches ResourceImportedEvent) to
 * PackageBackupService, so a freshly-imported package is immediately backed
 * up into the Local Repository without each importer needing to know that
 * backup exists at all.
 */
class PackageBackupSubscriber implements EventSubscriberInterface
{
    public function getSubscribedEvents(): array
    {
        return [
            [ResourceImportedEvent::class, ResourceImportedEvent::class, 'onResourceImported'],
        ];
    }

    public function onResourceImported(ResourceImportedEvent $event): void
    {
        $backup = new PackageBackupService();
        foreach ($event->packageHandles as $handle) {
            $backup->backupToLocalRepository($handle);
        }
    }
}
