<?php

namespace brandindustry\editrix\tests;

use Craft;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\ReplaceService;

/**
 * How long a dry run of a full 100 rows takes, and how much memory it uses,
 * on a real site. Slow and only meaningful on a large one: run with
 * EDITRIX_SLOW=1. The numbers go to stderr so they can be copied.
 */
class DryRunTimingTest extends EditrixTestCase
{
    public function testADryRunOfTheMostRowsItAllowsStaysWithinReason(): void
    {
        if (!getenv('EDITRIX_SLOW')) {
            $this->markTestSkipped('Slow: set EDITRIX_SLOW=1 to run it.');
        }

        $limit = ReplaceService::DRY_RUN_ROW_LIMIT;
        $word = getenv('EDITRIX_WORD') ?: 'the';

        $results = Editrix::$plugin->search->search(
            $word,
            $this->siteId(),
            false,
            false,
            [
                'searchEntries' => true,
                'searchGlobals' => false,
                'searchMatrix' => false,
                'maxResults' => $limit,
            ]
        );

        $rows = [];
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            array_push($rows, ...$site['results']);
        }
        $rows = array_slice($rows, 0, $limit);

        if (count($rows) < 10) {
            $this->markTestSkipped("Only " . count($rows) . " rows contain \"{$word}\".");
        }

        $memoryBefore = memory_get_usage(true);
        $started = microtime(true);

        $summary = Editrix::$plugin->replace->dryRun(
            $rows,
            $word,
            'EditrixDryRun',
            false,
            false,
            false
        );

        $seconds = microtime(true) - $started;
        $peakMb = (memory_get_peak_usage(true) - $memoryBefore) / 1048576;

        fwrite(STDERR, sprintf(
            "\nDry run: %d rows in %.1f s, %d would change, %d skipped, %d failed, memory up %.1f MB (peak)\n",
            count($rows),
            $seconds,
            $summary['replacedCount'],
            count($summary['skipped']),
            count($summary['failed']),
            $peakMb
        ));

        $this->assertTrue($summary['dryRun']);
        $this->assertLessThan(60, $seconds, 'A dry run of 100 rows should not take a minute.');
        $this->assertLessThan(256, $peakMb, 'A dry run should not use a quarter of a gigabyte.');
    }
}
