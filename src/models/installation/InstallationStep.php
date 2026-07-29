<?php

namespace site7\studio\models\installation;

/**
 * One concrete, executable unit of work within an InstallationPlan (Website
 * Starter Kit System Phase 6) - distinct from Phase 5's
 * site7\studio\models\project\InstallationPlan, which describes dependency
 * *ordering* for the Blueprint. This is the step-by-step plan
 * InstallationExecutor actually runs, built by InstallationPlanner from a
 * Blueprint plus a read-only look at the target environment.
 */
final class InstallationStep
{
    public const TYPE_PLUGIN_INSTALL = 'plugin-install';
    public const TYPE_COMPOSER = 'composer';
    public const TYPE_NPM = 'npm';
    public const TYPE_CRAFT_RESOURCE = 'craft-resource';
    public const TYPE_CONTENT = 'content';
    public const TYPE_FRONTEND = 'frontend';
    public const TYPE_PROJECT_CONFIG = 'project-config';

    public function __construct(
        public readonly string $type,
        /** Stable identity for idempotency checks - e.g. a plugin handle, a Volume handle, a Composer package name. Never a runtime ID. */
        public readonly string $key,
        public readonly string $label,
        /** @var array Whatever the responsible executor needs to carry out this step. */
        public readonly array $payload = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label,
            'payload' => $this->payload,
        ];
    }
}
