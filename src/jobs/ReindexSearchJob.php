<?php

namespace site7\studio\jobs;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\queue\BaseJob;

/**
 * Rebuilds Craft's search index for every entry and asset, on every site, without
 * saving them (so Library updates don't see them as edited). Queued once by the
 * m261015_120000_reindex_library_content migration for sites whose Library
 * content was imported before imports indexed it (docs/43 #58).
 */
class ReindexSearchJob extends BaseJob
{
    public function execute($queue): void
    {
        $search = Craft::$app->getSearch();
        $elements = [];
        foreach ([Entry::class, Asset::class] as $type) {
            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                foreach ($type::find()->siteId($siteId)->status(null)->ids() as $id) {
                    $elements[] = [$type, $id, $siteId];
                }
            }
        }
        $total = count($elements);
        foreach ($elements as $i => [$type, $id, $siteId]) {
            $this->setProgress($queue, $total ? $i / $total : 1);
            $element = Craft::$app->getElements()->getElementById($id, $type, $siteId);
            if ($element) {
                $search->indexElementAttributes($element);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Site7 Studio: rebuilding the search index';
    }
}
