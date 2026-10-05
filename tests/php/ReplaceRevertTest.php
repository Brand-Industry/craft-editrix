<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\ReplaceService;

/**
 * Search, replace and revert on a real entry title. The original title is
 * restored in tearDown even if an assertion fails, but this does write to
 * the host project's database, so keep it to local copies.
 */
class ReplaceRevertTest extends EditrixTestCase
{
    private ?int $entryId = null;
    private ?string $originalTitle = null;

    protected function tearDown(): void
    {
        if ($this->entryId !== null) {
            $entry = Entry::find()->id($this->entryId)->siteId($this->siteId())->status(null)->one();
            if ($entry && $entry->title !== $this->originalTitle) {
                $entry->title = $this->originalTitle;
                Craft::$app->getElements()->saveElement($entry, false);
            }
        }
        parent::tearDown();
    }

    /**
     * Finds an entry whose title has a word we can target, in a small
     * section, and returns [entry, word].
     */
    private function pickEntry(): array
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }

        $entries = Entry::find()
            ->siteId($this->siteId())
            ->section($section[0])
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->limit(50)
            ->all();

        foreach ($entries as $entry) {
            if (preg_match('/\b(\p{L}{5,})\b/u', (string) $entry->title, $m)) {
                $this->entryId = $entry->id;
                $this->originalTitle = (string) $entry->title;
                return [$entry, $m[1], $section[0]];
            }
        }

        $this->markTestSkipped('No entry in the smallest section has a title word of 5+ letters.');
    }

    private function titleResult(string $word, int $entryId, string $sectionHandle): array
    {
        $results = Editrix::$plugin->search->search(
            $word,
            $this->siteId(),
            false,
            true,
            $this->sectionOptions($sectionHandle, ['fields' => ['title']])
        );

        $grouped = Editrix::$plugin->search->groupResultsBySite($results);
        foreach ($grouped as $site) {
            foreach ($site['results'] as $result) {
                if ($result['elementId'] === $entryId && $result['fieldHandle'] === 'title') {
                    return $result;
                }
            }
        }

        $this->fail("Searching for \"{$word}\" did not return the title of entry {$entryId}.");
    }

    private function currentTitle(): string
    {
        return (string) Entry::find()->id($this->entryId)->siteId($this->siteId())->status(null)->one()->title;
    }

    public function testReplaceWritesTheNewTitleAndRevertRestoresTheOriginal(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $result = $this->titleResult($word, $entry->id, $section);
        $replacements = Editrix::$plugin->replace->replace([$result], $word, $replacement, false, true, false);

        $this->assertCount(1, $replacements);
        $this->assertSame(str_replace($word, $replacement, $this->originalTitle), $this->currentTitle());

        $validation = Editrix::$plugin->replace->validateRevert($replacements);
        $this->assertSame('safe', $validation[0]['status']);

        $this->assertTrue(Editrix::$plugin->replace->revert($replacements[0]));
        $this->assertSame($this->originalTitle, $this->currentTitle());
    }

    public function testRevertIsFlaggedWhenTheContentChangedAfterTheReplace(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $result = $this->titleResult($word, $entry->id, $section);
        $replacements = Editrix::$plugin->replace->replace([$result], $word, $replacement, false, true, false);
        $this->assertCount(1, $replacements);

        $edited = Entry::find()->id($entry->id)->siteId($this->siteId())->status(null)->one();
        $edited->title = $replacement . ' edited by someone else';
        Craft::$app->getElements()->saveElement($edited, false);

        $validation = Editrix::$plugin->replace->validateRevert($replacements);
        $this->assertSame('modified', $validation[0]['status']);
    }

    public function testReplacingWithTheSameTextChangesNothing(): void
    {
        [$entry, $word, $section] = $this->pickEntry();

        $result = $this->titleResult($word, $entry->id, $section);
        $replacements = Editrix::$plugin->replace->replace([$result], $word, $word, false, true, false);

        $this->assertSame([], $replacements);
        $this->assertSame($this->originalTitle, $this->currentTitle());
    }

    public function testAFieldEditedAfterTheSearchIsSkippedNotOverwritten(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $result = $this->titleResult($word, $entry->id, $section);

        // Someone edits the title after the search ran.
        $edited = Entry::find()->id($entry->id)->siteId($this->siteId())->status(null)->one();
        $edited->title = $this->originalTitle . ' edited meanwhile';
        Craft::$app->getElements()->saveElement($edited, false);

        $skipped = [];
        $replacements = Editrix::$plugin->replace->replace(
            [$result],
            $word,
            $replacement,
            false,
            true,
            false,
            $skipped
        );

        $this->assertSame([], $replacements);
        $this->assertCount(1, $skipped);
        $this->assertSame('changed', $skipped[0]['reason']);
        $this->assertSame(ReplaceService::keyFor($result), $skipped[0]['key']);
        $this->assertSame($this->originalTitle . ' edited meanwhile', $this->currentTitle(), 'The newer edit must survive.');
    }

    public function testReplacingTheSameResultTwiceOnlyAppliesOnce(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $result = $this->titleResult($word, $entry->id, $section);
        $first = Editrix::$plugin->replace->replace([$result], $word, $replacement, false, true, false);
        $this->assertCount(1, $first);

        $skipped = [];
        $second = Editrix::$plugin->replace->replace([$result], $word, $replacement, false, true, false, $skipped);

        $this->assertSame([], $second);
        $this->assertSame('changed', $skipped[0]['reason']);
    }

    public function testTheSameFieldOnTwoSitesHasTwoKeys(): void
    {
        $base = ['elementType' => 'entry', 'elementId' => 5, 'fieldHandle' => 'title'];

        $this->assertNotSame(
            ReplaceService::keyFor($base + ['siteId' => 1]),
            ReplaceService::keyFor($base + ['siteId' => 2])
        );
    }

    public function testReplacedMatchesAreRememberedOnTheSavedSearch(): void
    {
        $searchId = $this->createSearchRow('completed');
        $userId = (int) $this->row($searchId)['userId'];

        Editrix::$plugin->replace->markReplaced($searchId, $userId, ['a_1_title_1']);
        Editrix::$plugin->replace->markReplaced($searchId, $userId, ['a_1_title_1', 'b_2_body_1']);

        $this->assertSame(
            ['a_1_title_1', 'b_2_body_1'],
            json_decode($this->row($searchId)['replacedJson'], true)
        );
    }

    public function testAnotherUserCannotMarkSomeoneElsesSearch(): void
    {
        $searchId = $this->createSearchRow('completed');
        $userId = (int) $this->row($searchId)['userId'];

        Editrix::$plugin->replace->markReplaced($searchId, $userId + 999999, ['a_1_title_1']);

        $this->assertNull($this->row($searchId)['replacedJson']);
    }

    public function testReplacedOccurrencesAreCountedPerMatchNotPerField(): void
    {
        $row = fn(int $id, int $count, array $matches = []) => [
            'elementType' => 'entry',
            'elementId' => $id,
            'fieldHandle' => 'body',
            'siteId' => 1,
            'matchCount' => $count,
            'matches' => $matches,
        ];

        $selected = [$row(1, 4), $row(2, 3), $row(3, 5)];
        $replaced = array_map(fn($r) => ReplaceService::keyFor($r), [$selected[0], $selected[1]]);

        $this->assertSame(7, ReplaceService::countOccurrences($selected, $replaced));
    }

    public function testMatchesLeftAloneBecauseOfFormattingAreNotCounted(): void
    {
        $row = [
            'elementType' => 'entry',
            'elementId' => 1,
            'fieldHandle' => 'body',
            'siteId' => 1,
            'matchCount' => 3,
            'matches' => [['readOnly' => false], ['readOnly' => true], ['readOnly' => false]],
        ];

        $this->assertSame(2, ReplaceService::countOccurrences([$row], [ReplaceService::keyFor($row)]));
    }

    public function testARowWithNoCountIsOneOccurrence(): void
    {
        $row = ['elementType' => 'entry', 'elementId' => 1, 'fieldHandle' => 'title', 'siteId' => 1];

        $this->assertSame(1, ReplaceService::countOccurrences([$row], [ReplaceService::keyFor($row)]));
    }

    public function testTheCurrentValueIsReadFromTheElement(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $result = $this->titleResult($word, $entry->id, $section);

        $this->assertSame($this->originalTitle, Editrix::$plugin->replace->currentValueFor($result));
    }

    public function testARowMatchesItsSnapshotUntilTheFieldChanges(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $result = $this->titleResult($word, $entry->id, $section);
        $service = Editrix::$plugin->replace;

        $this->assertArrayNotHasKey('fieldValue', $result, 'Rows carry a fingerprint, not the text.');
        $this->assertTrue($service->matchesSearchSnapshot($result, $this->originalTitle));
        $this->assertFalse($service->matchesSearchSnapshot($result, $this->originalTitle . ' edited'));
    }

    public function testASavedSearchFromBeforeFingerprintsStillWorks(): void
    {
        $service = Editrix::$plugin->replace;
        $old = ['fieldValue' => 'the old text'];

        $this->assertTrue($service->matchesSearchSnapshot($old, 'the old text'));
        $this->assertFalse($service->matchesSearchSnapshot($old, 'the new text'));
    }

    public function testARowThatCannotBeVerifiedIsNeverReplaced(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $result = $this->titleResult($word, $entry->id, $section);
        unset($result['valueHash']);

        $skipped = [];
        $replacements = Editrix::$plugin->replace->replace(
            [$result],
            $word,
            'EditrixQA' . random_int(1000, 9999),
            false,
            true,
            false,
            $skipped
        );

        $this->assertSame([], $replacements);
        $this->assertSame('changed', $skipped[0]['reason']);
        $this->assertSame($this->originalTitle, $this->currentTitle());
    }

    public function testTheLogKeepsTheTextThatWasReplacedNotJustAFingerprint(): void
    {
        [$entry, $word, $section] = $this->pickEntry();
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $result = $this->titleResult($word, $entry->id, $section);
        $replacements = Editrix::$plugin->replace->replace([$result], $word, $replacement, false, true, false);

        $this->assertSame($this->originalTitle, $replacements[0]['oldValue'], 'Revert needs the real previous text.');
        $this->assertTrue(Editrix::$plugin->replace->revert($replacements[0]));
        $this->assertSame($this->originalTitle, $this->currentTitle());
    }
}
