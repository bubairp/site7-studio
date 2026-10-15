<?php

namespace site7\studio\migrations;

use Craft;
use craft\db\Migration;
use site7\studio\jobs\ReindexSearchJob;

/**
 * Library content imported before 2026-10-15 never reached Craft's search index,
 * so site search found almost nothing on Library sites (docs/43 #58). Queue one
 * rebuild of the index; imports index what they bring in from now on.
 */
class m261015_120000_reindex_library_content extends Migration
{
    public function safeUp(): bool
    {
        Craft::$app->getQueue()->push(new ReindexSearchJob());

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
