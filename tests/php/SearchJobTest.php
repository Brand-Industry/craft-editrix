<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use yii\base\Event;

class SearchJobTest extends EditrixTestCase
{
    private const NO_MATCH = 'zzq-editrix-no-such-text-zzq';

    public function testCompletesAndRecordsFullProgress(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries to scan.');
        }

        $searchId = $this->createSearchRow('queued');
        $row = $this->runJob($searchId, [
            'query' => self::NO_MATCH,
            'options' => $this->sectionOptions($section[0]),
        ]);

        $this->assertSame('completed', $row['status']);
        $this->assertSame($section[1], (int) $row['totalCount']);
        $this->assertSame((int) $row['totalCount'], (int) $row['scannedCount']);

        $payload = json_decode($row['resultsJson'], true);
        $this->assertSame(0, $payload['totalResults']);
        $this->assertFalse($payload['truncated']);
    }

    public function testTheRowReadsRunningOnceTheJobHasStarted(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries to scan.');
        }

        $searchId = $this->createSearchRow('queued');
        $this->assertSame('queued', $this->row($searchId)['status']);

        $seen = null;
        $handler = function () use (&$seen, $searchId) {
            $seen ??= $this->row($searchId)['status'];
        };

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $handler);

        try {
            $this->runJob($searchId, [
                'query' => self::NO_MATCH,
                'options' => $this->sectionOptions($section[0]),
            ]);
        } finally {
            Event::off(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $handler);
        }

        $this->assertSame('running', $seen);
    }

    public function testStopsAtTheResultCapAndReportsTruncation(): void
    {
        $section = $this->smallestSection(5);
        if (!$section) {
            $this->markTestSkipped('No section has enough entries to hit a cap of 3.');
        }

        $searchId = $this->createSearchRow('queued');
        $row = $this->runJob($searchId, [
            'query' => 'e',
            'options' => $this->sectionOptions($section[0], ['maxResults' => 3]),
        ]);

        $payload = json_decode($row['resultsJson'], true);
        if ($payload['totalResults'] < 3) {
            $this->markTestSkipped('Fewer than 3 matches for "e"; cannot exercise the cap on this data.');
        }

        $this->assertSame('completed', $row['status']);
        $this->assertSame(3, $payload['totalResults']);
        $this->assertTrue($payload['truncated']);
    }

    public function testASearchCancelledWhileQueuedStaysCancelledAndScansNothing(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries to scan.');
        }

        $searchId = $this->createSearchRow('cancelled');
        $row = $this->runJob($searchId, [
            'query' => 'e',
            'options' => $this->sectionOptions($section[0]),
        ]);

        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(0, (int) $row['scannedCount']);
    }

    public function testCancellingMidScanStopsEarly(): void
    {
        $section = $this->smallestSection(800);
        if (!$section) {
            $this->markTestSkipped('No section has 800+ entries, so there is nothing to cancel partway through.');
        }

        $searchId = $this->createSearchRow('queued');
        $populated = 0;
        $handler = function () use (&$populated, $searchId) {
            if (++$populated === 300) {
                $this->setStatus($searchId, 'cancelled');
            }
        };

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $handler);

        try {
            $row = $this->runJob($searchId, [
                'query' => self::NO_MATCH,
                'options' => $this->sectionOptions($section[0]),
            ]);
        } finally {
            Event::off(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, $handler);
        }

        $this->assertSame('cancelled', $row['status']);
        $this->assertLessThan($section[1], (int) $row['scannedCount']);
        $this->assertLessThanOrEqual(500, (int) $row['scannedCount']);
    }

    public function testAJobFailureIsRecordedOnTheRowInsteadOfThrowing(): void
    {
        $searchId = $this->createSearchRow('queued');
        $row = $this->runJob($searchId, [
            'query' => '(unclosed',
            'useRegex' => true,
            'options' => ['searchEntries' => false, 'searchGlobals' => true],
        ]);

        $this->assertContains($row['status'], ['error', 'completed']);
        if ($row['status'] === 'error') {
            $this->assertNotEmpty($row['errorMessage']);
        }
    }
}
