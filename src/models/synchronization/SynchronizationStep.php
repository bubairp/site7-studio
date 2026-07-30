<?php

namespace site7\studio\models\synchronization;

/**
 * One unit of work SynchronizationPlanner proposes (Website Starter Kit
 * System Phase 8) - deliberately the same {type, key, label, payload} shape
 * as Phase 6's InstallationStep (same $type vocabulary too: 'composer',
 * 'plugin-install', 'craft-resource', 'frontend', 'npm', 'project-config' -
 * never 'content', which Phase 8 always routes to manual review instead of
 * auto-applying; see SynchronizationPlanner's docblock for why) so a
 * create/update SynchronizationStep can be handed to the existing
 * InstallationExecutor unchanged, via a real InstallationSession built
 * from the newer Blueprint. $action exists for reporting/preview only - it
 * never changes what the underlying step executor does, since every
 * existing Phase 6 executor is already idempotent create-or-update.
 */
final class SynchronizationStep
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_REMOVE = 'remove';

    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly string $key,
        public readonly string $label,
        public readonly array $payload = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'key' => $this->key,
            'label' => $this->label,
            'payload' => $this->payload,
        ];
    }
}
