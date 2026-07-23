<?php

namespace site7\studio\interfaces;

use site7\studio\models\publishing\PublishResult;

/**
 * Orchestrates a full publish: validate -> build -> hand off to a chosen
 * PackagePublishTargetInterface -> record history. Never does any of those
 * steps itself - composes PackageValidatorInterface, PackageBuilderInterface,
 * RepositoryManagerService and PublishHistoryService, so none of that logic
 * is duplicated here or in the controller (see PackagePublisherController,
 * which only ever calls this).
 */
interface PackagePublisherInterface
{
    /**
     * @param array $options {repositoryHandle: string, metadata?: array, bumpType?: string, releaseNotes?: string}
     * @throws \Exception if $options['repositoryHandle'] is missing/unknown.
     */
    public function publish(string $handle, array $options): PublishResult;
}
