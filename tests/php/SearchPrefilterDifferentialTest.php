<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use PHPUnit\Framework\Attributes\Group;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\ReplaceService;
use brandindustry\editrix\services\SearchService;

/**
 * The prefilter may return more rows than needed, never fewer. These
 * compare a search with it against the same search scanning everything,
 * on real data, and require identical results.
 *
 * The quick tests run in the normal suite. The slow ones scan a whole
 * large section the long way; run them with `EDITRIX_SLOW=1`.
 */
class SearchPrefilterDifferentialTest extends EditrixTestCase
{
    /** @return string[] sorted keys of the rows a result list holds */
    private function keys(array $results): array
    {
        $keys = [];
        foreach ($results as $r) {
            $keys[ReplaceService::keyFor([
                'elementType' => $r->elementType,
                'elementId' => $r->elementId,
                'fieldHandle' => $r->fieldHandle,
                'siteId' => $r->siteId,
            ])] = true;
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    private function searchRows(string $query, array $options, bool $prefilter, bool $regex = false, bool $caseSensitive = true, int $limit = 300): array
    {
        $meta = [];
        $results = Editrix::$plugin->search->search(
            $query,
            $this->siteId(),
            $regex,
            $caseSensitive,
            array_merge($options, [
                'sqlPrefilter' => $prefilter,
                'searchMatrix' => true,
                'maxResults' => $limit,
            ]),
            null,
            null,
            $meta
        );

        return [$this->keys($results), $meta];
    }

    private function assertSameWithAndWithout(string $query, array $options, bool $regex = false, bool $caseSensitive = true, bool $wholeWords = false): int
    {
        if ($wholeWords) {
            $options['wholeWords'] = true;
        }

        $limit = getenv('EDITRIX_SLOW') ? SearchService::HARD_CEILING : 300;
        [$full, $fullMeta] = $this->searchRows($query, $options, false, $regex, $caseSensitive, $limit);
        [$fast, $fastMeta] = $this->searchRows($query, $options, true, $regex, $caseSensitive, $limit);

        $this->assertFalse($fullMeta['prefiltered']);
        $this->assertTrue($fastMeta['prefiltered'], "The prefilter should apply to \"{$query}\".");
        $this->assertSame(
            $full,
            $fast,
            "Prefiltered search for \"{$query}\" differs. Missing: " .
                json_encode(array_slice(array_values(array_diff($full, $fast)), 0, 5)) .
                ' Extra: ' . json_encode(array_slice(array_values(array_diff($fast, $full)), 0, 5))
        );

        return count($full);
    }

    /** A word of 5+ letters from a title in the section, plus the section. */
    private function wordFrom(string $section): ?string
    {
        $entries = Entry::find()->siteId($this->siteId())->section($section)->status(null)->limit(40)->all();
        foreach ($entries as $entry) {
            if (preg_match('/\b([A-Za-z]{5,})\b/', (string) $entry->title, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    public function testAWordFromTheSectionIsFoundTheSameWay(): void
    {
        $section = $this->smallestSection(150);
        $word = $section ? $this->wordFrom($section[0]) : null;
        if (!$word) {
            $this->markTestSkipped('No section with 150+ entries and a title word to search for.');
        }

        $found = $this->assertSameWithAndWithout($word, $this->sectionOptions($section[0]));
        $this->assertGreaterThan(0, $found, 'The word comes from a title, so it must be found.');
    }

    public function testIgnoringCaseFindsTheSameRows(): void
    {
        $section = $this->smallestSection(150);
        $word = $section ? $this->wordFrom($section[0]) : null;
        if (!$word) {
            $this->markTestSkipped('No section with 150+ entries and a title word to search for.');
        }

        $this->assertSameWithAndWithout(strtoupper($word), $this->sectionOptions($section[0]), false, false);
    }

    public function testWholeWordsFindTheSameRows(): void
    {
        $section = $this->smallestSection(150);
        $word = $section ? $this->wordFrom($section[0]) : null;
        if (!$word) {
            $this->markTestSkipped('No section with 150+ entries and a title word to search for.');
        }

        $this->assertSameWithAndWithout($word, $this->sectionOptions($section[0]), false, true, true);
    }

    public function testARegexFindsTheSameRows(): void
    {
        $section = $this->smallestSection(150);
        $word = $section ? $this->wordFrom($section[0]) : null;
        if (!$word) {
            $this->markTestSkipped('No section with 150+ entries and a title word to search for.');
        }

        $this->assertSameWithAndWithout($word . '\\w*', $this->sectionOptions($section[0]), true, true);
    }

    public function testAWordThatIsNowhereFindsNothingEitherWay(): void
    {
        $section = $this->smallestSection(150);
        if (!$section) {
            $this->markTestSkipped('The site has no section with 150+ entries.');
        }

        $this->assertSame(0, $this->assertSameWithAndWithout('zzqeditrixnosuchword', $this->sectionOptions($section[0])));
    }

    public function testASearchWithNothingToFilterOnStillScansEverything(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('The site has no section with 20+ entries.');
        }

        [, $meta] = $this->searchRows('e', $this->sectionOptions($section[0]), true);

        $this->assertFalse($meta['prefiltered'], 'One letter is too short to filter on.');
    }

    private function callSearch(string $method, array $args)
    {
        $m = new \ReflectionMethod(Editrix::$plugin->search, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(Editrix::$plugin->search, $args);
    }

    public function testRevisionsAreNeverCandidates(): void
    {
        $rows = (new Query())
            ->select(['es.elementId', 'es.title'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where(['es.siteId' => $this->siteId()])
            ->andWhere(['not', ['e.revisionId' => null]])
            ->orderBy(['es.elementId' => SORT_DESC])
            ->limit(200)
            ->all();

        $row = null;
        foreach ($rows as $candidate) {
            if (preg_match('/[A-Za-z]{5,}/', (string) $candidate['title'], $m)) {
                $row = $candidate;
                break;
            }
        }

        if (!$row) {
            $this->markTestSkipped('The site keeps no revisions with a title word.');
        }

        $id = (int) $row['elementId'];
        $candidates = $this->callSearch('candidateIds', [[$m[0]], $id, $id + 1, $this->siteId(), []]);

        $this->assertSame([], $candidates, 'A revision holds the word but a search never reads it.');
    }

    public function testNeoOwnersAreReadFromTheColumnTheInstalledNeoHas(): void
    {
        $column = $this->callSearch('neoOwnerColumn', []);
        $table = Craft::$app->getDb()->getTableSchema('{{%neoblocks}}', true);

        if (!$table) {
            $this->assertNull($column);
            return;
        }

        $this->assertContains($column, ['primaryOwnerId', 'ownerId']);
        $this->assertNotNull($table->getColumn($column), 'The column must exist in the table, or the query fails.');
    }

    public function testTheSettingTurnsThePrefilterOff(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('The site has no section with 20+ entries.');
        }

        [, $meta] = $this->searchRows('hotel', $this->sectionOptions($section[0]), false);

        $this->assertFalse($meta['prefiltered']);
    }

    public function testANestedEntryStandsForItsTopLevelOwner(): void
    {
        $nested = (new Query())
            ->select(['n.id', 'n.primaryOwnerId'])
            ->from(['n' => Table::ENTRIES])
            ->innerJoin(['o' => Table::ENTRIES], '[[o.id]] = [[n.primaryOwnerId]]')
            ->where(['not', ['n.primaryOwnerId' => null]])
            ->andWhere(['o.primaryOwnerId' => null])
            ->limit(1)
            ->one();

        if (!$nested) {
            $this->markTestSkipped('The site has no nested entries.');
        }

        $method = new \ReflectionMethod(Editrix::$plugin->search, 'topLevelIds');
        $method->setAccessible(true);
        $top = $method->invoke(Editrix::$plugin->search, [(int) $nested['id']]);

        $this->assertSame([(int) $nested['primaryOwnerId']], array_keys($top));
    }

    public function testEntriesCarryingAMatchingTagAreCandidates(): void
    {
        $tag = (new Query())
            ->select(['es.elementId', 'es.title'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['t' => Table::TAGS], '[[t.id]] = [[es.elementId]]')
            ->where(['es.siteId' => $this->siteId()])
            ->andWhere(['not', ['es.title' => null]])
            ->limit(1)
            ->one();

        if (!$tag || !preg_match('/[A-Za-z0-9]{3,}/', $tag['title'], $m)) {
            $this->markTestSkipped('The site has no tag with a word to search for.');
        }

        $source = (new Query())
            ->select(['sourceId'])
            ->from(Table::RELATIONS)
            ->where(['targetId' => $tag['elementId']])
            ->column();
        $expected = array_keys((function () use ($source) {
            $method = new \ReflectionMethod(Editrix::$plugin->search, 'topLevelIds');
            $method->setAccessible(true);
            return $method->invoke(Editrix::$plugin->search, array_map('intval', $source));
        })());

        if (!$expected) {
            $this->markTestSkipped('That tag is not used by any entry.');
        }

        $method = new \ReflectionMethod(Editrix::$plugin->search, 'tagOwnerIds');
        $method->setAccessible(true);
        $owners = $method->invoke(Editrix::$plugin->search, [$m[0]], $this->siteId());

        $this->assertEmpty(array_diff($expected, $owners), 'Every entry carrying the tag must be a candidate.');
    }

    private function requireSlow(): void
    {
        if (!getenv('EDITRIX_SLOW')) {
            $this->markTestSkipped('Slow: scans a large section the long way. Set EDITRIX_SLOW=1 to run it.');
        }
    }

    #[Group('slow')]
    public function testAWholeLargeSectionGivesTheSameRows(): void
    {
        $this->requireSlow();
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        if (!$section) {
            $this->markTestSkipped('This site has no "articles" section.');
        }

        $total = 0;
        foreach (['available', 'publicly available', 'quinault', 'availab[l]e'] as $i => $query) {
            $total += $this->assertSameWithAndWithout($query, $this->sectionOptions('articles'), $i === 3);
        }
        $total += $this->assertSameWithAndWithout('HOTEL', $this->sectionOptions('articles'), false, false);

        $this->assertGreaterThan(0, $total, 'These queries should find something in a large section of articles.');
    }

    #[Group('slow')]
    public function testASearchOfTheWholeSiteAgreesWithAFullScanOfOneSection(): void
    {
        $this->requireSlow();
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        if (!$section) {
            $this->markTestSkipped('This site has no "articles" section.');
        }

        // A phrase common enough to be found in many articles but not so
        // common that the whole-site search hits the row ceiling and stops
        // before it reaches the older entries.
        $query = 'publicly available';
        [$truth] = $this->searchRows($query, $this->sectionOptions('articles'), false, false, true, SearchService::HARD_CEILING);

        // Unscoped: no section filter, so the whole table is looked at.
        [$whole, $meta] = $this->searchRows($query, [
            'searchEntries' => true,
            'searchGlobals' => false,
            'searchMatrix' => true,
            'searchCategories' => false,
        ], true, false, true, SearchService::HARD_CEILING);

        if ($meta['truncated']) {
            $this->markTestSkipped('The whole-site search hit the row ceiling, so it did not look at every entry.');
        }

        $this->assertNotEmpty($truth, 'The phrase should be found in the articles.');

        $inSection = array_values(array_filter($whole, function ($key) use ($truth) {
            return in_array($key, $truth, true);
        }));

        $this->assertSame($truth, $inSection, 'Every row a full scan of the section finds must also be found by the whole-site search.');
    }
}
