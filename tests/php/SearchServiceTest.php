<?php

namespace brandindustry\editrix\tests;

use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\SearchService;

class SearchServiceTest extends EditrixTestCase
{
    public function testAQueryWithNoMatchesIsNotTruncated(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries to scan.');
        }

        $meta = [];
        $results = Editrix::$plugin->search->search(
            'zzq-editrix-no-such-text-zzq',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0]),
            null,
            null,
            $meta
        );

        $this->assertSame([], $results);
        $this->assertFalse($meta['truncated']);
        $this->assertSame(0, $meta['resultCount']);
    }

    public function testTheCapStopsTheScanEarlyAsWellAsTrimmingResults(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('No section has enough entries to show an early stop.');
        }

        $scanned = 0;
        $meta = [];
        $results = Editrix::$plugin->search->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0], ['maxResults' => 2]),
            function (int $done) use (&$scanned) {
                $scanned = $done;
            },
            null,
            $meta
        );

        if (count($results) < 2) {
            $this->markTestSkipped('Fewer than 2 matches for "e"; cannot exercise the cap on this data.');
        }

        // The cap is on rows (fields); a field that matches twice is one row.
        $this->assertSame(2, $meta['resultCount']);
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertTrue($meta['truncated']);
        $this->assertLessThan($section[1], $scanned, 'The scan should stop once the cap is hit.');
    }

    public function testOneBrokenEntryDoesNotAbortTheSearch(): void
    {
        $section = $this->smallestSection(4);
        if (!$section) {
            $this->markTestSkipped('No section has enough entries to skip some of them.');
        }

        $service = new class extends SearchService {
            public int $calls = 0;

            protected function searchOneEntry(
                Entry $entry,
                string $query,
                int $siteId,
                $site,
                bool $useRegex,
                bool $caseSensitive,
                bool $searchMatrix,
                bool $wholeWords,
                array $fieldFilter,
                array &$results
            ): void {
                $this->calls++;
                if ($this->calls % 2 === 1) {
                    throw new \RuntimeException('simulated template error');
                }
                parent::searchOneEntry(
                    $entry, $query, $siteId, $site, $useRegex,
                    $caseSensitive, $searchMatrix, $wholeWords, $fieldFilter, $results
                );
            }
        };

        $total = 0;
        $service->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0]),
            function (int $done, int $all) use (&$total) {
                $total = $all;
            }
        );

        $this->assertSame($total, $service->calls, 'Every entry should still be visited after earlier ones threw.');
    }

    public function testResultsDoNotKeepTheFieldTextInMemory(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('The site has no section with enough entries.');
        }

        $results = Editrix::$plugin->search->search(
            'e',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0], ['maxResults' => 50])
        );

        if (!$results) {
            $this->markTestSkipped('No matches for "e".');
        }

        foreach ($results as $result) {
            $this->assertSame('', $result->fieldValue, 'A result must not hold the whole field.');
            $this->assertNotNull($result->valueHash);
            $this->assertSame(32, strlen($result->valueHash));
        }
    }
}
