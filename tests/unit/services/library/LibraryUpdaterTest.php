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

    public function testARenamedPageIsFoundUnderItsInstalledHandle(): void
    {
        $installed = ['template-blogs-new-goal-4' => 'uid-a', 'template-contact' => 'uid-b'];
        $this->assertSame('template-blogs-new-goal-4', LibraryUpdater::pageOwner($installed, 'uid-a', 'template-blogs-choosing-a-cms'));
        $this->assertNull(LibraryUpdater::pageOwner($installed, 'uid-c', 'template-blogs-choosing-a-cms'), 'a new page');
        $this->assertNull(LibraryUpdater::pageOwner($installed, 'uid-b', 'template-contact'), 'itself');
        $this->assertNull(LibraryUpdater::pageOwner($installed, '', 'template-x'), 'no entry UID');
    }

    public function testABlockFindsItsPage(): void
    {
        $ownerOf = [11 => 10, 12 => 11];
        $this->assertSame(10, LibraryUpdater::pageOf(12, $ownerOf), 'nested block');
        $this->assertSame(10, LibraryUpdater::pageOf(10, $ownerOf), 'the page itself');
        $this->assertContains(LibraryUpdater::pageOf(1, [1 => 2, 2 => 1]), [1, 2], 'a cycle ends');
    }

    public function testAnEditedPageIsKeptWhole(): void
    {
        $ownerOf = [11 => 10, 12 => 10, 21 => 20];
        // Page 10: title edited here, the author replaced its blocks. Page 20: untouched.
        $actions = [10 => 'kept', 11 => 'replace', 12 => 'trash', 13 => 'insert', 20 => 'none', 21 => 'replace'];
        $this->assertSame([10 => true], LibraryUpdater::pagesKeptWhole($actions, $ownerOf + [13 => 10]));
        // An edited block keeps its page too.
        $this->assertSame([20 => true], LibraryUpdater::pagesKeptWhole([20 => 'replace', 21 => 'kept'], $ownerOf));
        $this->assertSame([20 => true], LibraryUpdater::pagesKeptWhole([20 => 'none', 21 => 'kept-removed'], $ownerOf));
    }

    public function testAnElementWithoutBlocksKeepsThePerElementRule(): void
    {
        $this->assertSame([], LibraryUpdater::pagesKeptWhole([5 => 'kept', 6 => 'replace'], []), 'an edited image');
        $this->assertSame([], LibraryUpdater::pagesKeptWhole([10 => 'replace', 11 => 'replace'], [11 => 10]), 'nothing edited');
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
