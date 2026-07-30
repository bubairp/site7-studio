<?php

namespace site7\studio\models\synchronization;

/**
 * A change SynchronizationPlanner refuses to auto-apply (Website Starter Kit
 * System Phase 8) - the concrete embodiment of "never overwrite local
 * modifications without explicit confirmation." Produced whenever the
 * *live* Craft resource state no longer matches what the previously-installed
 * Blueprint said it should be, regardless of what the new Blueprint wants -
 * the target site's own live edit always wins by default; a user has to
 * look at $installedValue vs $newValue and decide.
 */
final class Conflict
{
    /** Live Craft state has drifted from the previously-installed Blueprint's own captured definition - someone edited it directly in the CP since install. */
    public const TYPE_LOCALLY_MODIFIED = 'locally-modified';

    /** The previously-installed Blueprint's resource no longer exists live at all (deleted since install), so there's nothing safe to update in place. */
    public const TYPE_DELETED = 'deleted';

    /** The new Blueprint's required plugin version constraint doesn't satisfy the currently-installed plugin's actual version. */
    public const TYPE_PLUGIN_VERSION = 'plugin-version';

    /** A captured dependency/installation-order relationship changed in a way that couldn't be classified as a simple add/remove. */
    public const TYPE_DEPENDENCY = 'dependency';

    public function __construct(
        public readonly string $type,
        public readonly string $resourceKey,
        public readonly string $description,
        public readonly array $installedValue = [],
        public readonly array $newValue = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'resourceKey' => $this->resourceKey,
            'description' => $this->description,
            'installedValue' => $this->installedValue,
            'newValue' => $this->newValue,
        ];
    }
}
