<?php

namespace site7\studio\interfaces;

/**
 * A dedicated, single-resource-kind discovery unit under CraftResourceScanner
 * (Website Starter Kit System - resource discovery refactor). Each
 * implementation wraps exactly one native Craft CMS service/API and returns
 * live Craft objects - never a copy, transformation, or manifest-shaped
 * array. Classification, description, and manifest serialization stay the
 * job of the existing dedicated services (ResourceClassifierService,
 * CraftResourceService::describeFieldLayout(), the *ImportService classes)
 * that consume a scanner's output; a scanner only ever answers "what exists
 * on this site of this kind."
 */
interface ResourceScannerInterface
{
    /**
     * @return array Every live Craft resource of this scanner's kind, project-wide.
     */
    public function scan(): array;
}
