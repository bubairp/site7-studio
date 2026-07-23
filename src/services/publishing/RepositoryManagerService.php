<?php

namespace site7\studio\services\publishing;

use craft\base\Component;
use site7\studio\interfaces\PackagePublishTargetInterface;
use site7\studio\repositories\marketplace\Commerce24PublishTarget;
use site7\studio\repositories\marketplace\LocalPublishTarget;

/**
 * Registry of publish targets - the publish-side counterpart to
 * MarketplaceService's registerRepository()/getRepositories() (the
 * install-side registry), following the exact same pattern: a plain
 * handle-keyed array, auto-registering the Local target on init(), with
 * future Private/Enterprise/Commerce24 targets registered the same way
 * without any change to this class or PackagePublisherService.
 */
class RepositoryManagerService extends Component
{
    /** @var PackagePublishTargetInterface[] keyed by handle */
    private array $targets = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        if (empty($this->targets)) {
            $this->registerTarget(new LocalPublishTarget());
            // Commerce24PublishTarget is architecture-prep only (see its own
            // docblock) - registered here too so it's visible to
            // getPublishableTargets() once configured, but supportsPublish()
            // keeps it out of the Publish wizard's repository picker until then.
            $this->registerTarget(new Commerce24PublishTarget());
        }
    }

    public function registerTarget(PackagePublishTargetInterface $target): void
    {
        $this->targets[$target->getHandle()] = $target;
    }

    /**
     * @return PackagePublishTargetInterface[] keyed by handle
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    public function getTarget(string $handle): ?PackagePublishTargetInterface
    {
        return $this->targets[$handle] ?? null;
    }

    /**
     * @return PackagePublishTargetInterface[] keyed by handle - only targets currently accepting publishes
     */
    public function getPublishableTargets(): array
    {
        return array_filter($this->targets, fn(PackagePublishTargetInterface $target) => $target->supportsPublish());
    }
}
