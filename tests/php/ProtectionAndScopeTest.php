<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\ReplaceService;

/**
 * Fields and sections Replace must never change, and searching only some
 * entries by id.
 */
class ProtectionAndScopeTest extends EditrixTestCase
{
    private array $savedFields = [];
    private array $savedSections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = Editrix::$plugin->getSettings();
        $this->savedFields = $settings->protectedFields;
        $this->savedSections = $settings->protectedSections;
    }

    protected function tearDown(): void
    {
        $settings = Editrix::$plugin->getSettings();
        $settings->protectedFields = $this->savedFields;
        $settings->protectedSections = $this->savedSections;

        parent::tearDown();
    }

    public function testNothingIsProtectedByDefault(): void
    {
        $this->assertFalse(ReplaceService::isProtected('title', 'articles'));
    }

    public function testAFieldCanBeProtected(): void
    {
        Editrix::$plugin->getSettings()->protectedFields = ['stripeCustomerId'];

        $this->assertTrue(ReplaceService::isProtected('stripeCustomerId', 'articles'));
        $this->assertFalse(ReplaceService::isProtected('title', 'articles'));
    }

    public function testASectionCanBeProtected(): void
    {
        Editrix::$plugin->getSettings()->protectedSections = ['legal'];

        $this->assertTrue(ReplaceService::isProtected('title', 'legal'));
        $this->assertFalse(ReplaceService::isProtected('title', 'articles'));
        $this->assertFalse(ReplaceService::isProtected('title', null));
    }

    public function testAProtectedRowIsShownButReadOnly(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }
        Editrix::$plugin->getSettings()->protectedSections = [$section[0]];

        $results = Editrix::$plugin->search->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0], ['fields' => ['title'], 'maxResults' => 5])
        );
        $rows = [];
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            array_push($rows, ...$site['results']);
        }

        if (!$rows) {
            $this->markTestSkipped('No match for "e" in the smallest section.');
        }

        foreach ($rows as $row) {
            $this->assertTrue($row['readOnly']);
            $this->assertSame('protected', $row['readOnlyReason']);
        }
    }

    public function testAReplaceRefusesAProtectedFieldEvenWhenCalledDirectly(): void
    {
        $section = $this->smallestSection(1);
        $entry = $section ? Entry::find()->siteId($this->siteId())->section($section[0])->status(null)->one() : null;
        if (!$entry) {
            $this->markTestSkipped('The site has no entries.');
        }

        Editrix::$plugin->getSettings()->protectedFields = ['title'];

        $row = [
            'elementType' => 'entry', 'elementId' => $entry->id, 'siteId' => $this->siteId(),
            'fieldHandle' => 'title', 'sectionHandle' => $section[0], 'elementTitle' => $entry->title,
            'valueHash' => ReplaceService::hashValue((string) $entry->title),
        ];

        $skipped = [];
        $failed = [];
        $replacements = Editrix::$plugin->replace->replace([$row], (string) $entry->title, 'changed', false, true, false, $skipped, $failed);

        $this->assertSame([], $replacements);
        $this->assertSame('protected', $skipped[0]['reason']);
        $this->assertSame((string) $entry->title, (string) Entry::find()->id($entry->id)->siteId($this->siteId())->status(null)->one()->title);
    }

    public function testSearchingOnlyTheGivenEntryIds(): void
    {
        $section = $this->smallestSection(2);
        if (!$section) {
            $this->markTestSkipped('No section with two entries.');
        }

        $ids = Entry::find()->siteId($this->siteId())->section($section[0])->status(null)->drafts(false)->revisions(false)->limit(2)->ids();

        $meta = [];
        $results = Editrix::$plugin->search->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0], ['elementIds' => [$ids[0]], 'fields' => ['title'], 'maxResults' => 100]),
            null,
            null,
            $meta
        );

        foreach ($results as $result) {
            $this->assertSame($ids[0], $result->elementId, 'Only the listed entry may be searched.');
        }
        $this->assertFalse($meta['prefiltered'], 'A short list needs no prefilter.');
    }
}
