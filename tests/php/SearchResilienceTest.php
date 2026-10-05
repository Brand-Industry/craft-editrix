<?php

namespace brandindustry\editrix\tests;

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\SearchService;

/**
 * A scan has to survive what a big site throws at it: a batch that fails to
 * load, and a worker that is running out of memory.
 */
class SearchResilienceTest extends EditrixTestCase
{
    public function testAMemoryLimitStopsTheScanWithPartialResults(): void
    {
        $section = $this->smallestSection(300);
        if (!$section) {
            $this->markTestSkipped('No section has 300 entries, so the periodic memory check never runs.');
        }

        $service = new class extends SearchService {
            protected function memoryLow(): bool
            {
                return true;
            }
        };

        $scanned = 0;
        $meta = [];
        $service->search(
            'zzq-editrix-no-such-text-zzq',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0]),
            function (int $done) use (&$scanned) {
                $scanned = $done;
            },
            null,
            $meta
        );

        $this->assertTrue($meta['truncated']);
        $this->assertSame('memory', $meta['stopReason']);
        $this->assertSame(250, $scanned, 'The scan should stop at the first memory check.');
    }

    public function testTheCapReportsItsOwnStopReason(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('No section has enough entries.');
        }

        $meta = [];
        $results = Editrix::$plugin->search->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0], ['maxResults' => 2]),
            null,
            null,
            $meta
        );

        if (count($results) < 2) {
            $this->markTestSkipped('Fewer than 2 matches for "e".');
        }

        $this->assertTrue($meta['truncated']);
        $this->assertSame('results', $meta['stopReason']);
    }

    public function testTheWindowedScanVisitsExactlyTheEntriesCraftReturns(): void
    {
        $section = $this->smallestSection(150);
        if (!$section) {
            $this->markTestSkipped('No section has more than one batch of entries.');
        }

        $service = new class extends SearchService {
            public function ids(EntryQuery $query): array
            {
                $ids = [];
                foreach ($this->eachEntry($query) as $entry) {
                    $ids[] = $entry->id;
                }
                return $ids;
            }
        };

        $make = fn() => Entry::find()
            ->siteId($this->siteId())
            ->section($section[0])
            ->status(null)
            ->drafts(false)
            ->revisions(false);

        $visited = $service->ids($make());
        $expected = $make()->ids();

        sort($expected);
        $sorted = $visited;
        sort($sorted);

        $this->assertSame($expected, $sorted);
        $descending = $visited;
        rsort($descending);
        $this->assertSame($descending, $visited, 'Entries should come newest id first.');
    }

    public function testABatchThatFailsToLoadIsRetriedEntryByEntry(): void
    {
        $section = $this->smallestSection(150);
        if (!$section) {
            $this->markTestSkipped('No section has more than one batch of entries.');
        }

        $expected = (int) $section[1];

        $service = new class extends SearchService {
            public function ids(EntryQuery $query): array
            {
                $ids = [];
                foreach ($this->eachEntry($query) as $entry) {
                    $ids[] = $entry->id;
                }
                return $ids;
            }
        };

        // Fails whenever it is asked for more than one entry at once, which
        // is what a bad row does to a whole batch.
        $query = new class(Entry::class) extends EntryQuery {
            public function all($db = null): array
            {
                if (is_array($this->id) && count($this->id) > 1) {
                    throw new \RuntimeException('simulated bad batch');
                }
                return parent::all($db);
            }
        };
        $query->siteId($this->siteId())
            ->section($section[0])
            ->status(null)
            ->drafts(false)
            ->revisions(false);

        $ids = $service->ids($query);

        $this->assertCount($expected, $ids, 'Every entry should still be visited.');
        $this->assertSame(count($ids), count(array_unique($ids)), 'No entry should be visited twice.');
    }
}
