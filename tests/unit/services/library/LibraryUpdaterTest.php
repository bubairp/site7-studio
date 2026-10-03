<?php

namespace site7\studio\tests\unit\services\library;

use Codeception\Test\Unit;
use site7\studio\services\library\LibraryUpdater;

class LibraryUpdaterTest extends Unit
{
    protected \UnitTester $tester;

    public function testUntouchedItemsFollowTheNewVersion(): void
    {
        $this->assertSame('apply', LibraryUpdater::decide('a', 'a', 'b'));
    }

    public function testTheCustomersEditIsKept(): void
    {
        $this->assertSame('kept', LibraryUpdater::decide('a', 'mine', 'b'));
        $this->assertSame('kept', LibraryUpdater::decide('a', 'mine', 'a'), 'edited here, unchanged upstream');
    }

    public function testNothingToDo(): void
    {
        $this->assertSame('none', LibraryUpdater::decide('a', 'a', 'a'));
        $this->assertSame('none', LibraryUpdater::decide('a', 'b', 'b'), 'already what the new version has');
        $this->assertSame('none', LibraryUpdater::decide('a', null, 'b'), 'deleted here stays deleted');
    }

    public function testNewUpstreamItemsAreAddedUnlessSomethingElseIsThere(): void
    {
        $this->assertSame('apply', LibraryUpdater::decide(null, null, 'b'));
        $this->assertSame('none', LibraryUpdater::decide(null, 'b', 'b'));
        $this->assertSame('kept', LibraryUpdater::decide(null, 'x', 'b'));
    }
}
