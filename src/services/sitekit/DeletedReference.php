<?php

namespace site7\studio\services\sitekit;

/**
 * Thrown by SiteKitContent when a content row points at a structural row
 * (structure, field, section...) that's soft-deleted on the source, so its
 * UID can't be carried to the target.
 */
class DeletedReference extends \Exception
{
}
