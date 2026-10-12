<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;

/**
 * How long searches take on a large site: a rare word across the whole site,
 * a common word that stops at the result limit, and a search limited to a
 * large section. The numbers go to stderr, for the reference times in the
 * manual QA list. Slow: run with EDITRIX_SLOW=1.
 */
class SearchTimingTest extends EditrixTestCase
{
    private function timed(string $label, string $word, array $options): void
    {
        $started = microtime(true);
        $results = Editrix::$plugin->search->search(
            $word,
            $this->siteId(),
            false,
            false,
            ['searchEntries' => true, 'searchGlobals' => true, 'searchMatrix' => true] + $options
        );
        $seconds = microtime(true) - $started;

        $rows = 0;
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            $rows += count($site['results']);
        }

        fwrite(STDERR, sprintf(
            "\n%s: \"%s\" -> %d rows in %.1f s (peak memory %.0f MB)\n",
            $label,
            $word,
            $rows,
            $seconds,
            memory_get_peak_usage(true) / 1048576
        ));

        $this->addToAssertionCount(1);
    }

    public function testReferenceTimings(): void
    {
        if (!getenv('EDITRIX_SLOW')) {
            $this->markTestSkipped('Slow: set EDITRIX_SLOW=1 to run it.');
        }

        // A rare word: the longest word in the title of an entry in the
        // middle of the id range.
        $total = (int) (new Query())->from(Table::ENTRIES)->count();
        if ($total < 1000) {
            $this->markTestSkipped('The site is too small for these timings.');
        }

        // Entries whose title has a long word, found from the middle of the
        // id range onwards.
        $middleId = (int) (new Query())->select(['id'])->from(Table::ENTRIES)->orderBy(['id' => SORT_ASC])->offset(intdiv($total, 2))->limit(1)->scalar();
        $words = [];
        foreach (Entry::find()->id(['and', '>= ' . $middleId])->siteId($this->siteId())->status(null)->drafts(false)->revisions(false)->limit(30)->all() as $candidate) {
            foreach (preg_split('/\W+/u', (string) $candidate->title) ?: [] as $word) {
                if (mb_strlen($word) >= 8) {
                    $words[] = $word;
                }
            }
            if ($words) {
                break;
            }
        }
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $rare = getenv('EDITRIX_RARE') ?: ($words[0] ?? '');
        if (mb_strlen($rare) < 6) {
            $this->markTestSkipped('No usable rare word found.');
        }

        $this->timed('Rare word, whole site', $rare, []);
        $this->timed('Common word, stops at the limit', 'hotel', []);

        // The section whose entry count is nearest to 27,000.
        $best = null;
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $count = (int) Entry::find()->section($section->handle)->siteId($this->siteId())->status(null)->drafts(false)->revisions(false)->count();
            if ($best === null || abs($count - 27000) < abs($best[1] - 27000)) {
                $best = [$section->handle, $count];
            }
        }
        if ($best) {
            $this->timed("Section {$best[0]} ({$best[1]} entries)", $rare, ['sections' => [$best[0]]]);
        }
    }
}
