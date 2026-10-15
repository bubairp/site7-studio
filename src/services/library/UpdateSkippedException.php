<?php

namespace site7\studio\services\library;

/**
 * Thrown while applying an update that must not be applied on this site, e.g.
 * a page whose new version needs a block this site's plan doesn't include
 * (docs/43 #53). LibraryUpdater reports it as skipped, not failed, and the
 * installed version stays.
 */
class UpdateSkippedException extends \Exception
{
}
