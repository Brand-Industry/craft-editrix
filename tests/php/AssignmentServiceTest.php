<?php

namespace brandindustry\editrix\tests;

use brandindustry\editrix\Editrix;

class AssignmentServiceTest extends EditrixTestCase
{
    public function testACategoryNameThatDoesNotExistReturnsNothing(): void
    {
        $this->assertSame(
            [],
            Editrix::$plugin->assignment->searchCategoryAssignments('zzq-no-such-category-zzq', $this->siteId())
        );
    }

    public function testATagNameThatDoesNotExistReturnsNothing(): void
    {
        $this->assertSame(
            [],
            Editrix::$plugin->assignment->searchTagAssignments('zzq-no-such-tag-zzq', $this->siteId())
        );
    }
}
